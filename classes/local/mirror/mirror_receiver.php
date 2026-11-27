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

namespace format_rumbos\local\mirror;

use format_rumbos\local\result_access;
use format_rumbos\local\result_rejection;
use format_rumbos\local\result_statement;

/**
 * The mirror receiver: one signed message in, one answer out.
 *
 * It never touches the passback: no result, no grade and no completion is read or written here, and a failure here is
 * answered with an error the sender retries, while the passback of the same attempt is a separate request that does
 * not wait for this one. The wire format is PROVISIONAL (see docs/NATIVE-QUIZ-MIRROR.md): the envelope is the
 * passback's, and the payload shapes are in {@see exam} and {@see attempt_data}.
 *
 * Two kinds of message: `exam_definition` (claims `course_id` and `exam`) makes the quiz of a published version, and
 * `exam_attempt` (claims `course_id`, `sub`, `exam` and `attempt`) writes one finished attempt. The attempt carries its
 * exam so that it can never arrive before its quiz: the quiz is made when it is not there.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class mirror_receiver {
    /** @var int Largest request body read, in bytes: an exam with its texts is some tens of kilobytes. */
    public const MAX_BODY = 1048576;

    /**
     * Handles one request.
     *
     * @param string $method The HTTP method.
     * @param string $body The raw request body.
     * @param int|null $now The current time; tests pass their own.
     * @return array{status: int, body: array{applied: bool, reason: string}, headers: array<string, string>}
     */
    public static function handle(string $method, string $body, ?int $now = null): array {
        $now ??= time();
        if ($method !== 'POST') {
            return self::reply(405, false, 'method_not_allowed', ['Allow' => 'POST']);
        }
        if (!mirror_rate_limit::allow($now)) {
            return self::reply(429, false, 'rate_limited', ['Retry-After' => (string) mirror_rate_limit::WINDOW]);
        }

        $known = [];
        $kind = 'attempt';
        $authentic = false;
        try {
            if (!mirror_config::is_ready()) {
                throw new result_rejection(503, 'not_configured');
            }
            $claims = result_statement::verify_envelope(self::statement_of($body), $now);
            $authentic = true;
            $kind = ($claims->kind ?? null) === 'exam_definition' ? 'definition' : 'attempt';
            return self::process($claims, $known, $now);
        } catch (result_rejection $rejection) {
            self::log($authentic, $kind, 'rejected', $rejection->status, $rejection->reason, $known, $now);
            return self::reply($rejection->status, false, $rejection->reason);
        } catch (\Throwable $e) {
            // Whatever it was, the transaction rolled back and nothing was half-written: the sender retries.
            debugging('format_rumbos mirror receiver: ' . get_class($e) . ': ' . $e->getMessage(), DEBUG_NORMAL);
            self::log($authentic, $kind, 'failed', 500, 'internal_error', ['detail' => get_class($e)] + $known, $now);
            return self::reply(500, false, 'internal_error');
        }
    }

    /**
     * Handles a message whose signature is verified.
     *
     * @param \stdClass $claims
     * @param array $known The fields for the log, filled as they become known.
     * @param int $now
     * @return array The reply.
     * @throws result_rejection When the message is refused.
     */
    private static function process(\stdClass $claims, array &$known, int $now): array {
        $kind = $claims->kind ?? null;
        if ($kind !== 'exam_definition' && $kind !== 'exam_attempt') {
            throw new result_rejection(422, 'unknown_kind');
        }
        $course = result_access::course(is_int($claims->course_id ?? null) ? $claims->course_id : 0);
        $known['courseid'] = (int) $course->id;
        $exam = exam::from_array(self::object_of($claims->exam ?? null, 'invalid_definition:exam'));
        $known['formid'] = $exam->formid;
        $known['version'] = $exam->version;

        if ($kind === 'exam_definition') {
            [, $created] = mirror_quiz::ensure($course, $exam, $now);
            return self::reply(200, $created, $created ? 'created' : 'already_mirrored');
        }

        $attempt = attempt_data::from_array(self::object_of($claims->attempt ?? null, 'invalid_attempt:attempt'));
        $known['attemptid'] = $attempt->attemptid;
        if (!is_string($claims->sub ?? null) || $claims->sub === '') {
            throw new result_rejection(422, 'unknown_user');
        }
        $userid = result_access::student($course, $claims->sub);
        $attempt->resolve($exam);

        [$mirror] = mirror_quiz::ensure($course, $exam, $now);
        $result = mirror_attempts::write($mirror, $exam, $attempt, (int) $course->id, $userid, $now);
        if ($result['written']) {
            mirror_store::log(
                'attempt',
                $result['diverged'] ? 'divergent' : 'applied',
                200,
                $result['diverged'] ? 'percent_differs' : 'written',
                $known,
                $now
            );
        }
        return self::reply(200, $result['written'], $result['written'] ? 'written' : 'already_written');
    }

    /**
     * A claim that must be an object, as an array.
     *
     * @param mixed $value
     * @param string $reason What to refuse with when it is not.
     * @return array
     * @throws result_rejection 422
     */
    private static function object_of(mixed $value, string $reason): array {
        if (!is_object($value) && !is_array($value)) {
            throw new result_rejection(422, $reason);
        }
        return json_decode(json_encode($value), true);
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
        if (!is_array($data) || !is_string($data['statement'] ?? null) || $data['statement'] === '') {
            throw new result_rejection(400, 'malformed');
        }
        return $data['statement'];
    }

    /**
     * Writes a line of the log. Messages that were not authentic are not written: anybody can send them for free.
     *
     * @param bool $authentic
     * @param string $kind
     * @param string $outcome
     * @param int $status
     * @param string $reason
     * @param array $known
     * @param int $now
     */
    private static function log(
        bool $authentic,
        string $kind,
        string $outcome,
        int $status,
        string $reason,
        array $known,
        int $now
    ): void {
        if ($authentic) {
            mirror_store::log($kind, $outcome, $status, $reason, $known, $now);
        }
    }

    /**
     * Builds an answer.
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
