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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->libdir . '/gradelib.php');

/**
 * Applies a verified result to Moodle: the best percent into the gradebook, and the course completion on a pass.
 *
 * Everything happens in one database transaction together with the audit row, so a result is applied if and only if
 * it is audited, and a failure leaves nothing behind.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class result_applier {
    /** @var string Reason when this statement completed the course. */
    public const COMPLETED = 'completed';

    /** @var string Reason when the result was recorded and the completion did not change. */
    public const RECORDED = 'recorded';

    /**
     * Applies a result for a student whose course is set up.
     *
     * @param \stdClass $course The course record.
     * @param int $userid The student.
     * @param array $claims The normalised claims of a verified statement.
     * @param int $now
     * @param array $known What the audit row of the statement already knows (see {@see result_store::audit()}).
     * @return string {@see self::COMPLETED} or {@see self::RECORDED}.
     * @throws \dml_write_exception When the attempt or the statement id was applied in the meantime: the caller decides.
     */
    public static function apply(\stdClass $course, int $userid, array $claims, int $now, array $known = []): string {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        try {
            result_store::insert_result($claims, (int) $course->id, $userid, $now);
            self::record_best($course, $userid, result_statement::cents($claims['percent']));
            $completed = result_statement::completes($claims) && self::complete($course, $userid);
            $reason = $completed ? self::COMPLETED : self::RECORDED;

            result_store::audit($now, 200, $reason, ['userid' => $userid] + $known + result_store::audit_fields($claims));
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
        return $reason;
    }

    /**
     * Raises the course's result grade of the user to the percent when it is higher; never lowers it.
     *
     * "Best of the check-in and the final" is this comparison, made in whole hundredths so nothing is a float.
     *
     * @param \stdClass $course
     * @param int $userid
     * @param int $cents The percent in hundredths, 0 to 10000.
     */
    private static function record_best(\stdClass $course, int $userid, int $cents): void {
        $item = result_course::grade_item((int) $course->id);
        $current = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $userid]);
        $best = $current && $current->finalgrade !== null ? (int) round((float) $current->finalgrade * 100) : null;
        if ($best === null || $cents > $best) {
            $item->update_final_grade($userid, $cents / 100, 'format_rumbos');
        }
    }

    /**
     * Marks the course's completion criterion for the user and aggregates their completion at once.
     *
     * An already marked criterion is left alone and Moodle never moves a completion time, so the course completes
     * once however many passes arrive.
     *
     * @param \stdClass $course
     * @param int $userid
     * @return bool Whether the course was incomplete before and is complete now.
     */
    private static function complete(\stdClass $course, int $userid): bool {
        global $DB;

        $info = new \completion_info($course);
        if ($info->is_course_complete($userid)) {
            return false;
        }

        $criterion = result_course::criterion((int) $course->id);
        foreach ($info->get_completions($userid, COMPLETION_CRITERIA_TYPE_ROLE) as $completion) {
            if ((int) $completion->criteriaid === (int) $criterion->id && !$completion->is_complete()) {
                $criterion->complete($completion);
            }
        }

        $id = $DB->get_field('course_completions', 'id', ['course' => $course->id, 'userid' => $userid]);
        if ($id) {
            aggregate_completions((int) $id);
        }
        return (new \completion_info($course))->is_course_complete($userid);
    }
}
