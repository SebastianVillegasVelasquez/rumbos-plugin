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

/**
 * The database side of the mirror: which quiz holds which exam version, which question holds which item, which quiz
 * attempt was written for which attempt of the service, and what the mirror refused.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mirror_store {
    /** @var string Table of the mirror quizzes. */
    public const QUIZZES = 'format_rumbos_mirror_quiz';

    /** @var string Table of the items of each mirror quiz. */
    public const ITEMS = 'format_rumbos_mirror_item';

    /** @var string Table of the attempts written. */
    public const ATTEMPTS = 'format_rumbos_mirror_attempt';

    /** @var string Table of what the mirror refused, failed or flagged. */
    public const LOG = 'format_rumbos_mirror_log';

    /** @var int Most rows the administrator's list shows. */
    public const LOG_LIST_LIMIT = 200;

    /**
     * The mirror quiz of an exam version in a course, if there is one.
     *
     * @param int $courseid
     * @param string $formid
     * @param string $version
     * @return \stdClass|null
     */
    public static function find_quiz(int $courseid, string $formid, string $version): ?\stdClass {
        global $DB;

        return $DB->get_record(self::QUIZZES, ['courseid' => $courseid, 'formid' => $formid, 'version' => $version]) ?: null;
    }

    /**
     * A mirror quiz by its id.
     *
     * @param int $mirrorid
     * @return \stdClass|null
     */
    public static function get_quiz(int $mirrorid): ?\stdClass {
        global $DB;

        return $DB->get_record(self::QUIZZES, ['id' => $mirrorid]) ?: null;
    }

    /**
     * Records a mirror quiz.
     *
     * @param int $courseid
     * @param exam $exam
     * @param int $cmid
     * @param int $quizid
     * @param int $now
     * @return int The id of the mirror.
     * @throws \dml_write_exception When the version has a quiz already (a race).
     */
    public static function insert_quiz(int $courseid, exam $exam, int $cmid, int $quizid, int $now): int {
        global $DB;

        return $DB->insert_record(self::QUIZZES, (object) [
            'courseid' => $courseid,
            'formid' => $exam->formid,
            'version' => $exam->version,
            'purpose' => $exam->purpose,
            'cmid' => $cmid,
            'quizid' => $quizid,
            'timecreated' => $now,
        ]);
    }

    /**
     * Forgets a mirror quiz and its items. The quiz itself is not touched.
     *
     * @param int $mirrorid
     */
    public static function delete_quiz(int $mirrorid): void {
        global $DB;

        $DB->delete_records(self::ITEMS, ['mirrorid' => $mirrorid]);
        $DB->delete_records(self::QUIZZES, ['id' => $mirrorid]);
    }

    /**
     * Records where an item of the exam is in the quiz.
     *
     * @param int $mirrorid
     * @param string $itemid
     * @param int $slot
     * @param int $questionid
     * @param float $maxmark
     * @param int|float $units
     */
    public static function insert_item(
        int $mirrorid,
        string $itemid,
        int $slot,
        int $questionid,
        float $maxmark,
        int|float $units
    ): void {
        global $DB;

        $DB->insert_record(self::ITEMS, (object) [
            'mirrorid' => $mirrorid,
            'itemid' => $itemid,
            'slot' => $slot,
            'questionid' => $questionid,
            'maxmark' => $maxmark,
            'units' => $units,
        ]);
    }

    /**
     * The items of a mirror quiz.
     *
     * @param int $mirrorid
     * @return \stdClass[] By the item id of the exam.
     */
    public static function items(int $mirrorid): array {
        global $DB;

        return $DB->get_records(self::ITEMS, ['mirrorid' => $mirrorid], 'slot', 'itemid, id, slot, questionid, maxmark, units');
    }

    /**
     * The quiz attempt written for an attempt of the service, if any.
     *
     * @param string $attemptid
     * @return \stdClass|null
     */
    public static function find_attempt(string $attemptid): ?\stdClass {
        global $DB;

        return $DB->get_record(self::ATTEMPTS, ['attemptid' => $attemptid]) ?: null;
    }

    /**
     * Records a quiz attempt that was written.
     *
     * @param int $mirrorid
     * @param attempt_data $attempt
     * @param int $courseid
     * @param int $userid
     * @param int $quizattemptid
     * @param bool $diverged Whether the total of the quiz attempt, rounded, was not the service's percent.
     * @param int $now
     * @return int The id of the row.
     * @throws \dml_write_exception When the attempt was written already (a race).
     */
    public static function insert_attempt(
        int $mirrorid,
        attempt_data $attempt,
        int $courseid,
        int $userid,
        int $quizattemptid,
        bool $diverged,
        int $now
    ): int {
        global $DB;

        return $DB->insert_record(self::ATTEMPTS, (object) [
            'mirrorid' => $mirrorid,
            'attemptid' => $attempt->attemptid,
            'courseid' => $courseid,
            'userid' => $userid,
            'quizattemptid' => $quizattemptid,
            'percent' => $attempt->percent,
            'passed' => (int) $attempt->passed,
            'diverged' => (int) $diverged,
            'timefinished' => $attempt->finished,
            'timecreated' => $now,
        ]);
    }

    /**
     * Writes a line of the log, and never lets that fail what it describes.
     *
     * @param string $kind `definition` or `attempt`.
     * @param string $outcome `applied`, `rejected`, `failed` or `divergent`.
     * @param int $status The HTTP status answered.
     * @param string $reason The reason answered.
     * @param array $known Any of `detail`, `formid`, `version`, `attemptid` and `courseid`.
     * @param int $now
     */
    public static function log(string $kind, string $outcome, int $status, string $reason, array $known, int $now): void {
        global $DB;

        try {
            $DB->insert_record(self::LOG, (object) [
                'timecreated' => $now,
                'kind' => $kind,
                'outcome' => $outcome,
                'status' => $status,
                'reason' => \core_text::substr($reason, 0, 48),
                'detail' => isset($known['detail']) ? \core_text::substr((string) $known['detail'], 0, 255) : null,
                'formid' => $known['formid'] ?? null,
                'version' => $known['version'] ?? null,
                'attemptid' => $known['attemptid'] ?? null,
                'courseid' => (int) ($known['courseid'] ?? 0),
            ]);
        } catch (\Throwable $e) {
            debugging('format_rumbos mirror log failed: ' . $e->getMessage(), DEBUG_NORMAL);
        }
    }

    /**
     * The newest lines of the log.
     *
     * @param bool $problemsonly Leave out the quizzes that were made and the attempts that were written.
     * @param int $limit
     * @return \stdClass[] Newest first.
     */
    public static function recent_log(bool $problemsonly = true, int $limit = self::LOG_LIST_LIMIT): array {
        global $DB;

        $where = $problemsonly ? "outcome <> 'applied'" : '1 = 1';
        return $DB->get_records_select(self::LOG, $where, [], 'timecreated DESC, id DESC', '*', 0, $limit);
    }

    /**
     * How many quizzes and attempts there are, and how many problems were logged since a time.
     *
     * @param int $since
     * @return array{quizzes: int, attempts: int, problems: int, diverged: int}
     */
    public static function counts(int $since): array {
        global $DB;

        return [
            'quizzes' => $DB->count_records(self::QUIZZES),
            'attempts' => $DB->count_records(self::ATTEMPTS),
            'problems' => $DB->count_records_select(self::LOG, "outcome IN ('rejected', 'failed') AND timecreated >= ?", [$since]),
            'diverged' => $DB->count_records(self::ATTEMPTS, ['diverged' => 1]),
        ];
    }
}
