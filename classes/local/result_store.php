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
 * The two tables of the result receiver: the results applied and the audit of every statement.
 *
 * Neither is purged automatically: they back a certificate (docs/DECISIONS.md, D8).
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class result_store {
    /** @var string Table of the results applied, one row per attempt. */
    public const RESULTS = 'format_rumbos_result';

    /** @var string Table of the audit, one row per statement received. */
    public const AUDIT = 'format_rumbos_audit';

    /**
     * The result applied for an attempt, if any.
     *
     * @param string $attemptid
     * @return \stdClass|null
     */
    public static function find_by_attempt(string $attemptid): ?\stdClass {
        global $DB;

        return $DB->get_record(self::RESULTS, ['attemptid' => $attemptid]) ?: null;
    }

    /**
     * The result applied by the statement with an id, if any.
     *
     * @param string $jti
     * @return \stdClass|null
     */
    public static function find_by_jti(string $jti): ?\stdClass {
        global $DB;

        return $DB->get_record(self::RESULTS, ['jti' => $jti]) ?: null;
    }

    /**
     * Stores the result of a verified statement.
     *
     * @param array $claims The normalised claims.
     * @param int $courseid
     * @param int $userid
     * @param int $now
     * @return int The new row's id.
     * @throws \dml_write_exception When the attempt or the statement id was applied already (a race).
     */
    public static function insert_result(array $claims, int $courseid, int $userid, int $now): int {
        global $DB;

        return $DB->insert_record(self::RESULTS, (object) [
            'courseid' => $courseid,
            'userid' => $userid,
            'purpose' => $claims['purpose'],
            'attemptid' => $claims['attempt_id'],
            'jti' => $claims['jti'],
            'payloadhash' => result_statement::payload_hash($claims),
            'percent' => $claims['percent'],
            'passed' => $claims['passed'] ? 1 : 0,
            'timefinished' => $claims['finished_at'],
            'timeapplied' => $now,
        ]);
    }

    /**
     * Writes one audit row.
     *
     * @param int $now When the statement arrived.
     * @param int $status The HTTP status answered.
     * @param string $reason The reason answered.
     * @param array $known What is known about the statement so far: any of `kid`, `jti`, `attemptid`, `subject`,
     *     `courseid`, `userid`, `purpose`, `percent`, `passed`, `policy`. Nothing else is kept.
     */
    public static function audit(int $now, int $status, string $reason, array $known = []): void {
        global $DB;

        $text = fn(string $name, int $length): ?string =>
            isset($known[$name]) ? \core_text::substr((string) $known[$name], 0, $length) : null;

        $DB->insert_record(self::AUDIT, (object) [
            'timecreated' => $now,
            'status' => $status,
            'reason' => \core_text::substr($reason, 0, 48),
            'kid' => $text('kid', 32),
            'jti' => $text('jti', 64),
            'attemptid' => $text('attemptid', 64),
            'subject' => $text('subject', 64),
            'courseid' => (int) ($known['courseid'] ?? 0),
            'userid' => (int) ($known['userid'] ?? 0),
            'purpose' => $text('purpose', 12),
            'percent' => $text('percent', 6),
            'passed' => isset($known['passed']) ? (int) (bool) $known['passed'] : null,
            'policy' => $text('policy', 24),
        ]);
    }

    /**
     * What is worth knowing about a verified statement, for the audit.
     *
     * @param array $claims The normalised claims.
     * @return array
     */
    public static function audit_fields(array $claims): array {
        return [
            'jti' => $claims['jti'],
            'attemptid' => $claims['attempt_id'],
            'subject' => $claims['sub'],
            'courseid' => $claims['course_id'],
            'purpose' => $claims['purpose'],
            'percent' => $claims['percent'],
            'passed' => $claims['passed'],
            'policy' => $claims['pass_operator'] . ':' . $claims['pass_percent'],
        ];
    }

    /**
     * Deletes a user's results, and makes their audit rows anonymous (the row stays as a record of the statement).
     *
     * @param int $userid
     * @param int|null $courseid Only this course, or every course.
     */
    public static function erase_user(int $userid, ?int $courseid = null): void {
        global $DB;

        $conditions = ['userid' => $userid] + ($courseid === null ? [] : ['courseid' => $courseid]);
        $DB->delete_records(self::RESULTS, $conditions);
        $DB->set_field_select(
            self::AUDIT,
            'subject',
            null,
            'userid = :userid' . ($courseid === null ? '' : ' AND courseid = :courseid'),
            $conditions
        );
        $DB->set_field(self::AUDIT, 'userid', 0, $conditions);
    }

    /**
     * Deletes the results and the audit rows of a course.
     *
     * @param int $courseid
     */
    public static function erase_course(int $courseid): void {
        global $DB;

        $DB->delete_records(self::RESULTS, ['courseid' => $courseid]);
        $DB->delete_records(self::AUDIT, ['courseid' => $courseid]);
    }
}
