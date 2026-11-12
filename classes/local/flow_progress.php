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
 * The progress of each user through the welcome flow of a Rumbos map, stored in format_rumbos_flow.
 *
 * The flow itself (its steps and their order) lives in the Rumbos service. Moodle only remembers which step a
 * user completed, with which revision of it, how and when. The client reports its own progress: that is a
 * convenience for the app, never something an authority decision may rest on.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class flow_progress {
    /** @var string The table. */
    public const TABLE = 'format_rumbos_flow';

    /** @var int The most rows one user may have in one course. */
    public const MAX_ROWS = 500;

    /** @var string The step was done. */
    public const METHOD_COMPLETED = 'completed';

    /** @var string The step was done through its fallback. */
    public const METHOD_FALLBACK = 'fallback';

    /** @var string The step could not be shown and was skipped. */
    public const METHOD_UNAVAILABLE = 'unavailable';

    /** @var string[] How a step may have been completed. */
    public const METHODS = [self::METHOD_COMPLETED, self::METHOD_FALLBACK, self::METHOD_UNAVAILABLE];

    /** @var int The highest revision accepted. */
    public const MAX_REVISION = 1000000;

    /** @var string Pattern of a map id: a UUID, which Moodle treats as opaque text (D: no trailing newline). */
    public const MAPID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD';

    /** @var string Pattern of a step key; the column holds 16 characters. */
    public const STEPKEY_PATTERN = '/^[A-Za-z0-9_-]{1,16}$/D';

    /** @var string The row was stored. */
    public const RESULT_STORED = 'stored';

    /** @var string The row replaced an older revision. */
    public const RESULT_REPLACED = 'replaced';

    /** @var string The step was already recorded for that revision or a newer one; nothing changed. */
    public const RESULT_UNCHANGED = 'unchanged';

    /** @var string The user already has the most rows allowed; nothing was stored. */
    public const RESULT_CAPPED = 'capped';

    /**
     * Whether a value is a map id.
     *
     * @param mixed $mapid
     * @return bool
     */
    public static function is_mapid($mapid): bool {
        return is_string($mapid) && preg_match(self::MAPID_PATTERN, $mapid) === 1;
    }

    /**
     * Whether a value is a step key.
     *
     * @param mixed $stepkey
     * @return bool
     */
    public static function is_stepkey($stepkey): bool {
        return is_string($stepkey) && preg_match(self::STEPKEY_PATTERN, $stepkey) === 1;
    }

    /**
     * Whether a value is a revision in the accepted range.
     *
     * @param mixed $revision
     * @return bool
     */
    public static function is_revision($revision): bool {
        return is_int($revision) && $revision >= 0 && $revision <= self::MAX_REVISION;
    }

    /**
     * Whether a value is one of the methods.
     *
     * @param mixed $method
     * @return bool
     */
    public static function is_method($method): bool {
        return is_string($method) && in_array($method, self::METHODS, true);
    }

    /**
     * The rows of one user on one map, oldest first.
     *
     * @param int $courseid
     * @param int $userid
     * @param string $mapid
     * @return array[] Each with stepKey, revision, method and completedAt (ISO 8601, UTC).
     */
    public static function get_items(int $courseid, int $userid, string $mapid): array {
        global $DB;

        $rows = $DB->get_records(
            self::TABLE,
            ['courseid' => $courseid, 'userid' => $userid, 'mapid' => strtolower($mapid)],
            'timecompleted ASC, id ASC',
            'id, stepkey, revision, method, timecompleted'
        );
        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'stepKey' => $row->stepkey,
                'revision' => (int) $row->revision,
                'method' => $row->method,
                'completedAt' => gmdate('Y-m-d\TH:i:s\Z', (int) $row->timecompleted),
            ];
        }
        return $items;
    }

    /**
     * Records that a user completed a step.
     *
     * Repeating a step is harmless: for the same revision the earliest completion is kept, a lower revision is
     * ignored and a higher one replaces the row. The caller has already checked every value.
     *
     * @param int $courseid
     * @param int $userid
     * @param string $mapid
     * @param string $stepkey
     * @param int $revision
     * @param string $method
     * @param int|null $time Defaults to now.
     * @return string One of the RESULT_ constants.
     */
    public static function record(
        int $courseid,
        int $userid,
        string $mapid,
        string $stepkey,
        int $revision,
        string $method,
        ?int $time = null
    ): string {
        global $DB;

        $time = $time ?? time();
        $key = ['courseid' => $courseid, 'userid' => $userid, 'mapid' => strtolower($mapid), 'stepkey' => $stepkey];

        $existing = $DB->get_record(self::TABLE, $key, 'id, revision');
        if ($existing) {
            return self::update_existing($existing, $revision, $method, $time);
        }

        if ($DB->count_records(self::TABLE, ['courseid' => $courseid, 'userid' => $userid]) >= self::MAX_ROWS) {
            return self::RESULT_CAPPED;
        }
        try {
            $DB->insert_record(self::TABLE, (object) ($key + [
                'revision' => $revision,
                'method' => $method,
                'timecompleted' => $time,
            ]));
        } catch (\dml_write_exception $e) {
            // Another request stored the same step an instant ago: treat this one as the repeat it is.
            $existing = $DB->get_record(self::TABLE, $key, 'id, revision');
            if (!$existing) {
                throw $e;
            }
            return self::update_existing($existing, $revision, $method, $time);
        }
        return self::RESULT_STORED;
    }

    /**
     * Applies a repeated completion to the row that is already there.
     *
     * @param \stdClass $existing The row: id and revision.
     * @param int $revision
     * @param string $method
     * @param int $time
     * @return string RESULT_REPLACED or RESULT_UNCHANGED.
     */
    private static function update_existing(\stdClass $existing, int $revision, string $method, int $time): string {
        global $DB;

        if ($revision <= (int) $existing->revision) {
            return self::RESULT_UNCHANGED;
        }
        $DB->update_record(self::TABLE, (object) [
            'id' => $existing->id,
            'revision' => $revision,
            'method' => $method,
            'timecompleted' => $time,
        ]);
        return self::RESULT_REPLACED;
    }

    /**
     * Forgets the progress of a user on one map.
     *
     * @param int $courseid
     * @param int $userid
     * @param string $mapid
     */
    public static function reset_map(int $courseid, int $userid, string $mapid): void {
        global $DB;

        $DB->delete_records(self::TABLE, ['courseid' => $courseid, 'userid' => $userid, 'mapid' => strtolower($mapid)]);
    }

    /**
     * Forgets everything a user has in a course, or in every course.
     *
     * @param int $userid
     * @param int|null $courseid Null for every course.
     */
    public static function delete_for_user(int $userid, ?int $courseid = null): void {
        global $DB;

        $conditions = ['userid' => $userid];
        if ($courseid !== null) {
            $conditions['courseid'] = $courseid;
        }
        $DB->delete_records(self::TABLE, $conditions);
    }

    /**
     * Forgets everything about a course.
     *
     * @param int $courseid
     */
    public static function delete_for_course(int $courseid): void {
        global $DB;

        $DB->delete_records(self::TABLE, ['courseid' => $courseid]);
    }
}
