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

use core\context\course as context_course;
use core\context\module as context_module;
use format_rumbos\event\content_autocomplete_failed;
use format_rumbos\event\content_autocompleted;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/completionlib.php');

/**
 * Marks the course content complete for a student whose passing result the Rumbos service reported (docs/DECISIONS.md, D30).
 *
 * **Only a verified result statement may cause this.** The one trigger is a statement the result receiver verified (its
 * signature, its policy, its replay rules) and applied: the row in `format_rumbos_result` with `passed = 1` is what this
 * class reads, never a message from a browser. The browser has no way to reach it, which is the point: a forged message
 * would otherwise be a self-service completion.
 *
 * It uses Moodle's supported override, {@see \completion_info::update_state()} with the override flag, which is what a
 * teacher's "Override" in the activity completion report calls. Moodle needs a current user who holds
 * `moodle/course:overridecompletion` for that, and the receiver has none, so for the duration of the call the site
 * administrator is the current user: `overrideby` therefore names the administrator, and the reason (a verified passing
 * result, its id and its purpose) is in the event {@see content_autocompleted} written for each activity. Nothing is
 * deleted: no attempt, track or grade is touched, and the student can still open every activity.
 *
 * It is off unless the administrator turns the setting on, and it is idempotent: what is complete stays as it is.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class content_autocomplete {
    /**
     * The modules whose activities count as the content of a level.
     *
     * The SCORM activities are what the map's bubbles open and what the final-exam gate counts (docs/FINAL-EXAM-GATE.md). A
     * Rumbos course also holds plain content (a PDF, a link) that students look at and that tracks nothing the gate reads;
     * marking those would change completion data nobody asked to change. Add a module name here to include it.
     *
     * @var string[]
     */
    public const MODULES = ['scorm'];

    /** @var int Most passes over the course: finishing one activity can unlock another that was restricted by it. */
    public const MAX_PASSES = 5;

    /** @var string Outcome: the setting is off. */
    public const DISABLED = 'disabled';

    /** @var string Outcome: no verified passing result backs the request. */
    public const UNVERIFIED = 'unverified';

    /** @var string Outcome: the user is not a student who takes part in the course. */
    public const NOT_A_STUDENT = 'notastudent';

    /** @var string Outcome: everything required is complete (it may have been already). */
    public const DONE = 'done';

    /** @var string Outcome: something could not be completed and a retry is queued. */
    public const PARTIAL = 'partial';

    /**
     * Runs after a verified result was applied, for a passing one, and never lets anything escape.
     *
     * The result is already committed when this runs, and nothing here can undo it or change the answer the sender
     * gets. A failure is logged, becomes an event the administrator can read, and queues a retry.
     *
     * @param int $courseid
     * @param int $userid
     * @param string $jti The id of the statement that carried the pass.
     */
    public static function after_result(int $courseid, int $userid, string $jti): void {
        if (!config::is_autocomplete_enabled()) {
            return;
        }
        try {
            $summary = self::run($courseid, $userid, $jti);
            if ($summary['outcome'] === self::PARTIAL) {
                self::schedule_retry($courseid, $userid, $jti);
            }
        } catch (\Throwable $e) {
            self::report_failure($courseid, $userid, $jti, [], get_class($e) . ': ' . $e->getMessage());
            self::schedule_retry($courseid, $userid, $jti);
        }
    }

    /**
     * Completes the required activities of a student, when a verified passing result says so.
     *
     * @param int $courseid
     * @param int $userid
     * @param string $jti The id of the statement that carried the pass.
     * @return array{outcome: string, completed: int[], already: int[], skipped: array<int, string>, failed: array<int, string>}
     */
    public static function run(int $courseid, int $userid, string $jti): array {
        $summary = ['outcome' => self::DISABLED, 'completed' => [], 'already' => [], 'skipped' => [], 'failed' => []];
        if (!config::is_autocomplete_enabled()) {
            return $summary;
        }
        $result = result_store::find_by_jti($jti);
        if (
            $result === null || (int) $result->passed !== 1 || (int) $result->userid !== $userid ||
                (int) $result->courseid !== $courseid
        ) {
            $summary['outcome'] = self::UNVERIFIED;
            return $summary;
        }
        $context = context_course::instance($courseid);
        if (!is_enrolled($context, $userid, '', true) || has_capability('format/rumbos:editmap', $context, $userid)) {
            $summary['outcome'] = self::NOT_A_STUDENT;
            return $summary;
        }

        $actor = get_admin();
        $previous = $GLOBALS['USER'];
        \core\session\manager::set_user($actor);
        try {
            $summary = self::complete_all(get_course($courseid), $userid, $result, $actor, $summary);
        } finally {
            \core\session\manager::set_user($previous);
        }

        if ($summary['failed']) {
            $summary['outcome'] = self::PARTIAL;
            self::report_failure($courseid, $userid, $jti, array_keys($summary['failed']), implode('; ', $summary['failed']));
        } else {
            $summary['outcome'] = self::DONE;
        }
        return $summary;
    }

    /**
     * Goes over the course until nothing more can be completed.
     *
     * What counts is what the student can see (visible and not restricted for them), the rule of the final-exam gate. An
     * activity restricted by the completion of another becomes visible when that one completes, which is why this runs
     * more than one pass.
     *
     * @param \stdClass $course
     * @param int $userid
     * @param \stdClass $result The verified result row.
     * @param \stdClass $actor The user Moodle records as the one who overrode.
     * @param array $summary
     * @return array The summary, filled in.
     */
    private static function complete_all(
        \stdClass $course,
        int $userid,
        \stdClass $result,
        \stdClass $actor,
        array $summary
    ): array {
        $info = new \completion_info($course);
        $attempted = [];
        for ($pass = 0; $pass < self::MAX_PASSES; $pass++) {
            $changed = false;
            foreach (self::required($course, $userid, $info) as $cm) {
                if (isset($attempted[$cm->id])) {
                    continue;
                }
                $attempted[$cm->id] = true;
                $outcome = self::complete_one($info, $cm, $userid, $result, $actor);
                if ($outcome === 'completed') {
                    $summary['completed'][] = (int) $cm->id;
                    $changed = true;
                } else if ($outcome === 'already') {
                    $summary['already'][] = (int) $cm->id;
                } else {
                    $summary['failed'][(int) $cm->id] = $outcome;
                }
            }
            if (!$changed) {
                break;
            }
        }
        return $summary;
    }

    /**
     * The activities that must be complete: of the content modules, tracked, and visible to the student.
     *
     * Hidden ones, ones the student is restricted from, ones being deleted and ones that track nothing are left out:
     * the first because the student cannot open them, the last because Moodle records nothing for them.
     *
     * @param \stdClass $course
     * @param int $userid
     * @param \completion_info $info
     * @return \cm_info[]
     */
    public static function required(\stdClass $course, int $userid, \completion_info $info): array {
        $required = [];
        // Moodle caches the modinfo of a user; completing one activity can change what the next one's restrictions say.
        get_fast_modinfo($course, $userid, true);
        foreach (get_fast_modinfo($course, $userid)->get_cms() as $cm) {
            if (
                in_array($cm->modname, self::MODULES, true) && $cm->uservisible && !$cm->deletioninprogress &&
                    $info->is_enabled($cm) != COMPLETION_TRACKING_NONE
            ) {
                $required[] = $cm;
            }
        }
        return $required;
    }

    /**
     * Completes one activity, with Moodle's override, and records it.
     *
     * A failed state is not touched by an override to complete (Moodle returns early), so it is first overridden to
     * incomplete, which is the two steps a teacher takes by hand.
     *
     * @param \completion_info $info
     * @param \cm_info $cm
     * @param int $userid
     * @param \stdClass $result
     * @param \stdClass $actor
     * @return string "completed", "already" or the reason it failed.
     */
    private static function complete_one(
        \completion_info $info,
        \cm_info $cm,
        int $userid,
        \stdClass $result,
        \stdClass $actor
    ): string {
        try {
            $before = (int) $info->get_data($cm, false, $userid)->completionstate;
            if (in_array($before, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS], true)) {
                return 'already';
            }
            if ($before === COMPLETION_COMPLETE_FAIL) {
                $info->update_state($cm, COMPLETION_INCOMPLETE, $userid, true);
            }
            $info->update_state($cm, COMPLETION_COMPLETE, $userid, true);

            $after = (int) $info->get_data($cm, false, $userid)->completionstate;
            if (!in_array($after, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS], true)) {
                return "cm {$cm->id} is still not complete (state $after)";
            }
            content_autocompleted::create([
                'context' => context_module::instance($cm->id),
                'objectid' => $cm->id,
                'userid' => (int) $actor->id,
                'relateduserid' => $userid,
                'courseid' => (int) $cm->course,
                'other' => [
                    'jti' => (string) $result->jti,
                    'purpose' => (string) $result->purpose,
                    'previousstate' => $before,
                    'modname' => $cm->modname,
                ],
            ])->trigger();
            return 'completed';
        } catch (\Throwable $e) {
            return "cm {$cm->id}: " . get_class($e) . ': ' . $e->getMessage();
        }
    }

    /**
     * Makes a failure visible: the server error log and an event in the course logs.
     *
     * @param int $courseid
     * @param int $userid
     * @param string $jti
     * @param int[] $cmids The activities that failed, when known.
     * @param string $message What went wrong.
     */
    private static function report_failure(int $courseid, int $userid, string $jti, array $cmids, string $message): void {
        // Not debugging(): that prints nothing at the production debug level, and this must reach the server log.
        // phpcs:ignore moodle.PHP.ForbiddenFunctions.FoundWithAlternative
        error_log("format_rumbos auto-completion failed for course $courseid, user $userid, statement $jti: $message");
        try {
            content_autocomplete_failed::create([
                'context' => context_course::instance($courseid),
                'relateduserid' => $userid,
                'courseid' => $courseid,
                'other' => ['jti' => $jti, 'cmids' => $cmids, 'message' => \core_text::substr($message, 0, 250)],
            ])->trigger();
        } catch (\Throwable $e) {
            // The log entry is best effort; the error log above already has it.
            debugging('format_rumbos could not write the auto-completion failure event: ' . $e->getMessage(), DEBUG_NORMAL);
        }
    }

    /**
     * Queues a retry through Moodle's cron, which repeats a failed task with a growing delay.
     *
     * @param int $courseid
     * @param int $userid
     * @param string $jti
     */
    private static function schedule_retry(int $courseid, int $userid, string $jti): void {
        try {
            $task = new \format_rumbos\task\autocomplete_content();
            $task->set_custom_data(['courseid' => $courseid, 'userid' => $userid, 'jti' => $jti]);
            \core\task\manager::queue_adhoc_task($task, true);
        } catch (\Throwable $e) {
            // phpcs:ignore moodle.PHP.ForbiddenFunctions.FoundWithAlternative
            error_log('format_rumbos could not queue the auto-completion retry: ' . $e->getMessage());
        }
    }
}
