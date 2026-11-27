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

use format_rumbos\local\result_rejection;

/**
 * What the mirror does with the two statements the receiver hands it: an exam definition and an attempt record.
 *
 * It verifies no signature: {@see \format_rumbos\local\result_receiver} has done that, with the same code as for a
 * result, before anything here runs. It grants nothing: no result, grade or completion is read or written here.
 *
 * Its answers are chosen for how the service treats them. A refusal it would not retry is kept for what a retry cannot
 * mend. Everything else is retried: the mirror being off (503), a definition that is missing (409
 * `unknown_definition`, which also makes the service send it again), and a statement this version cannot read or write
 * (503), so that a fix on this side is enough for it to arrive. Nothing is dropped.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class mirror_intake {
    /** @var string The reason, and the `error`, that asks the service for a definition again. */
    public const UNKNOWN_DEFINITION = 'unknown_definition';

    /** @var string The reason while the administrator has the mirror off. */
    public const NOT_ENABLED = 'mirror_not_enabled';

    /**
     * Keeps an exam definition.
     *
     * @param \stdClass $claims The claims of a definition statement; signature, issuer, audience and tenant verified.
     * @param int $now
     * @return array{applied: bool, reason: string} `definition_stored`, or `definition_already_stored`.
     * @throws result_rejection 409 `definition_conflict`, 422 `unknown_kind`, 503, or 500 `mirror_failed`.
     */
    public static function definition(\stdClass $claims, int $now): array {
        $known = [
            'formid' => self::text_claim($claims, 'form_id'),
            'version' => self::text_claim($claims, 'definition_hash'),
        ];
        return self::guard('definition', $known, $now, function () use ($claims, $known, $now): array {
            if (($claims->schema ?? null) !== exam::SCHEMA) {
                throw new result_rejection(422, 'unknown_kind');
            }
            $contentversion = $claims->content_version ?? null;
            $definition = $claims->definition ?? null;
            if (
                !is_string($contentversion) || $contentversion === '' || \core_text::strlen($contentversion) > 64
                    || !$definition instanceof \stdClass
            ) {
                throw new result_rejection(422, 'invalid_definition:statement');
            }
            $data = json_decode(json_encode($definition), true);
            $exam = exam::from_definition($data, (string) $known['version']);
            if ($exam->formid !== $known['formid']) {
                throw new result_rejection(422, 'invalid_definition:form_id');
            }

            $stored = mirror_store::find_definition($exam->formid, $exam->version);
            if ($stored !== null) {
                if (self::canonical(json_decode($stored->definition, true)) !== self::canonical($data)) {
                    // The hash names one exam. A second one under it is for a person to look at.
                    throw new result_rejection(409, 'definition_conflict');
                }
                return ['applied' => false, 'reason' => 'definition_already_stored'];
            }
            try {
                mirror_store::insert_definition(
                    $exam->formid,
                    $exam->version,
                    $contentversion,
                    json_encode($definition, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    $now
                );
            } catch (\dml_write_exception $e) {
                if (mirror_store::find_definition($exam->formid, $exam->version) === null) {
                    throw $e;
                }
                // The same definition arrived twice at the same moment and the other request kept it.
                return ['applied' => false, 'reason' => 'definition_already_stored'];
            }
            mirror_store::log('definition', 'applied', 200, 'definition_stored', $known, $now);
            return ['applied' => true, 'reason' => 'definition_stored'];
        });
    }

    /**
     * Writes an attempt record as a finished attempt of the course's mirror quiz, making the quiz when this is the
     * course's first attempt at that exam.
     *
     * @param \stdClass $claims The claims of an attempt statement, already verified.
     * @param array $result Its result claims, as normalised: they have been applied for this course and user.
     * @param int $courseid
     * @param int $userid
     * @param int $now
     * @return string `mirrored`, or `already_mirrored`.
     * @throws result_rejection 409 `unknown_definition`, 503, or 500 `mirror_failed`.
     */
    public static function attempt(\stdClass $claims, array $result, int $courseid, int $userid, int $now): string {
        $known = [
            'formid' => self::text_claim($claims, 'form_id'),
            'version' => self::text_claim($claims, 'definition_hash'),
            'attemptid' => $result['attempt_id'],
            'courseid' => $courseid,
        ];
        return self::guard('attempt', $known, $now, function () use ($claims, $result, $courseid, $userid, $known, $now): string {
            if (mirror_store::find_attempt($result['attempt_id']) !== null) {
                return 'already_mirrored';
            }
            $attempt = attempt_data::from_claims($claims, $result);
            $stored = mirror_store::find_definition($attempt->formid, $attempt->version);
            if ($stored === null) {
                throw new result_rejection(409, self::UNKNOWN_DEFINITION);
            }
            $exam = exam::from_definition(json_decode($stored->definition, true), $stored->hash);
            $attempt->check_against($exam);

            [$mirror] = mirror_quiz::ensure(get_course($courseid), $exam, $now);
            $written = mirror_attempts::write($mirror, $exam, $attempt, $courseid, $userid, $now);
            if (!$written['written']) {
                return 'already_mirrored';
            }
            mirror_store::log(
                'attempt',
                $written['diverged'] ? 'divergent' : 'applied',
                200,
                $written['diverged'] ? 'marks_differ' : 'written',
                $known,
                $now
            );
            return 'mirrored';
        });
    }

    /**
     * Runs one of the two, only when the mirror is on, and turns whatever goes wrong into an answer, which it logs.
     *
     * @param string $kind `definition` or `attempt`.
     * @param array $known The fields for the log.
     * @param int $now
     * @param callable $work
     * @return mixed What the work returns.
     * @throws result_rejection
     */
    private static function guard(string $kind, array $known, int $now, callable $work): mixed {
        try {
            if (!mirror_config::is_enabled()) {
                throw new result_rejection(503, self::NOT_ENABLED);
            }
            return $work();
        } catch (result_rejection $rejection) {
            // A statement this version cannot take is retried, so that a fix here is enough for it to arrive.
            $status = str_starts_with($rejection->reason, 'invalid_') ? 503 : $rejection->status;
            mirror_store::log($kind, 'rejected', $status, explode(':', $rejection->reason)[0], [
                'detail' => $rejection->reason,
            ] + $known, $now);
            throw new result_rejection($status, $rejection->reason);
        } catch (\Throwable $e) {
            // The transaction rolled back, so nothing is half-written.
            debugging('format_rumbos mirror: ' . get_class($e) . ': ' . $e->getMessage(), DEBUG_NORMAL);
            mirror_store::log($kind, 'failed', 500, 'mirror_failed', ['detail' => get_class($e)] + $known, $now);
            throw new result_rejection(500, 'mirror_failed');
        }
    }

    /**
     * A claim that is a short text, for the log.
     *
     * @param \stdClass $claims
     * @param string $name
     * @return string|null Null when it is not one.
     */
    private static function text_claim(\stdClass $claims, string $name): ?string {
        $value = $claims->{$name} ?? null;
        return is_string($value) && preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $value) === 1 ? $value : null;
    }

    /**
     * One form of a decoded JSON value, whatever order its members came in.
     *
     * @param mixed $value
     * @return string
     */
    private static function canonical(mixed $value): string {
        $sort = function (mixed $item) use (&$sort): mixed {
            if (!is_array($item)) {
                return $item;
            }
            if (!array_is_list($item)) {
                ksort($item, SORT_STRING);
            }
            return array_map($sort, $item);
        };
        return (string) json_encode($sort($value));
    }
}
