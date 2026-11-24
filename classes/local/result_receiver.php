<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace format_rumbos\local;

/**
 * The result receiver: one request in, one answer out, and an audit row for every statement.
 *
 * It knows nothing about HTTP beyond status codes, so it is tested without a web server; passback.php only reads the
 * request and sends the answer. The contract is in docs/RESULT-PASSBACK.md.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class result_receiver {
    /** @var int Largest request body read, in bytes: a statement is about a kilobyte. */
    public const MAX_BODY = 16384;

    /**
     * Handles one request.
     *
     * @param string $method The HTTP method.
     * @param string $body The raw request body.
     * @param int|null $now The current time; tests pass their own.
     * @return array{status: int, body: array{applied: bool, reason: string}, headers: array<string, string>}
     */
    public static function handle(string $method, string $body, ?int $now = null): array {
        $now = $now ?? time();
        if ($method !== 'POST') {
            return self::reply(405, false, 'method_not_allowed', ['Allow' => 'POST']);
        }
        if (!result_rate_limit::allow($now)) {
            return self::reply(429, false, 'rate_limited', ['Retry-After' => (string) result_rate_limit::WINDOW]);
        }

        $known = [];
        $authentic = false;
        try {
            if (!result_config::is_ready()) {
                throw new result_rejection(503, 'not_configured');
            }
            $jws = self::statement_of($body);
            $known['kid'] = self::safe_key_id($jws);

            $claims = result_statement::verify($jws, $now);
            $authentic = true;
            $known = result_store::audit_fields($claims) + $known;

            return self::process($claims, $known, $now);
        } catch (result_rejection $rejection) {
            self::write_audit($now, $rejection->status, $rejection->reason, $known, $authentic);
            return self::reply($rejection->status, false, $rejection->reason);
        } catch (\Throwable $e) {
            // Whatever it was, nothing was applied: the transaction rolled back. The sender retries.
            debugging('format_rumbos result receiver: ' . get_class($e) . ': ' . $e->getMessage(), DEBUG_NORMAL);
            self::write_audit($now, 500, 'internal_error', $known, $authentic);
            return self::reply(500, false, 'internal_error');
        }
    }

    /**
     * Handles a statement whose signature is verified.
     *
     * @param array $claims The normalised claims.
     * @param array $known The audit fields.
     * @param int $now
     * @return array The reply.
     * @throws result_rejection When the statement is refused.
     */
    private static function process(array $claims, array $known, int $now): array {
        $replay = self::replay($claims);
        if ($replay !== null) {
            self::write_audit($now, 200, 'already_applied', ['userid' => (int) $replay->userid] + $known, true);
            // A delivery that arrives again is also the retry of an auto-completion that failed the first time. It
            // does nothing when everything is complete, or when the setting is off.
            if ((int) $replay->passed === 1) {
                content_autocomplete::after_result((int) $replay->courseid, (int) $replay->userid, (string) $replay->jti);
            }
            return self::reply(200, false, 'already_applied');
        }

        $course = result_access::course($claims['course_id']);
        $known['courseid'] = (int) $course->id;
        $userid = result_access::student($course, $claims['sub']);
        $known['userid'] = $userid;

        $notready = result_course::not_ready_reason($course);
        if ($notready !== null) {
            throw new result_rejection(503, 'course_not_configured:' . $notready);
        }

        try {
            $reason = result_applier::apply($course, $userid, $claims, $now, $known);
            // After the commit, so that nothing in it can undo the result or change the answer to the sender.
            if ($claims['passed']) {
                content_autocomplete::after_result((int) $course->id, $userid, (string) $claims['jti']);
            }
        } catch (\dml_write_exception $e) {
            // Two requests for the same attempt arrived together and the other one won.
            $replay = self::replay($claims);
            if ($replay === null) {
                throw $e;
            }
            self::write_audit($now, 200, 'already_applied', $known, true);
            return self::reply(200, false, 'already_applied');
        }
        return self::reply(200, true, $reason);
    }

    /**
     * Whether the statement repeats one that was applied.
     *
     * @param array $claims The normalised claims.
     * @return \stdClass|null Null when it does not; else the result that was applied.
     * @throws result_rejection 409 when its id was used by a different statement.
     */
    private static function replay(array $claims): ?\stdClass {
        $byjti = result_store::find_by_jti($claims['jti']);
        if ($byjti !== null) {
            if (!hash_equals($byjti->payloadhash, result_statement::payload_hash($claims))) {
                throw new result_rejection(409, 'jti_conflict');
            }
            return $byjti;
        }
        return result_store::find_by_attempt($claims['attempt_id']);
    }

    /**
     * The statement in a request body.
     *
     * @param string $body
     * @return string
     * @throws result_rejection 400 when the body is too large, not JSON, or has no statement.
     */
    private static function statement_of(string $body): string {
        if (strlen($body) > self::MAX_BODY) {
            throw new result_rejection(400, 'malformed');
        }
        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['statement']) || !is_string($data['statement']) || $data['statement'] === '') {
            throw new result_rejection(400, 'malformed');
        }
        return $data['statement'];
    }

    /**
     * The key id of the header, kept for the audit only when it looks like one.
     *
     * @param string $jws
     * @return string|null
     */
    private static function safe_key_id(string $jws): ?string {
        try {
            $kid = result_statement::key_id($jws);
        } catch (result_rejection $e) {
            return null;
        }
        return preg_match('/^[0-9a-f]{1,32}$/', $kid) === 1 ? $kid : null;
    }

    /**
     * Writes an audit row, unless it is an unauthenticated request over the cap, and never lets that fail the answer.
     *
     * @param int $now
     * @param int $status
     * @param string $reason
     * @param array $known
     * @param bool $authentic Whether the signature was verified.
     */
    private static function write_audit(int $now, int $status, string $reason, array $known, bool $authentic): void {
        try {
            if (!$authentic && !result_rate_limit::allow_unauthenticated_audit($now)) {
                return;
            }
            result_store::audit($now, $status, $reason, $known);
        } catch (\Throwable $e) {
            debugging('format_rumbos result audit failed: ' . $e->getMessage(), DEBUG_NORMAL);
        }
    }

    /**
     * Builds an answer.
     *
     * The reason of an unconfigured course is `course_not_configured:<detail>` in the audit and
     * `course_not_configured` in the answer.
     *
     * @param int $status
     * @param bool $applied
     * @param string $reason
     * @param array $headers
     * @return array
     */
    private static function reply(int $status, bool $applied, string $reason, array $headers = []): array {
        return [
            'status' => $status,
            'body' => ['applied' => $applied, 'reason' => explode(':', $reason)[0]],
            'headers' => $headers,
        ];
    }
}
