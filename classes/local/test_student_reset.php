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
use format_rumbos\event\content_autocompleted;
use format_rumbos\event\staging_completion_overridden;
use format_rumbos\task\autocomplete_content;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/completionlib.php');

/**
 * STAGING ONLY: puts a test student's Rumbos journey back, as far as that can be done without deleting evidence.
 *
 * It does two things. It deletes the student's flow progress rows of the course (the welcome and the closing flow share
 * them) through {@see flow_progress::reset_map()}. And it takes back the completion overrides **this plugin recorded** for
 * the student in the course: the plugin knows them from its own log events, one per override
 * ({@see content_autocompleted} and {@see staging_completion_overridden}), read through Moodle's log reader. An override
 * somebody else made, or changed since, is left alone.
 *
 * "Taking back" is overriding to incomplete with {@see \completion_info::update_state()}: Moodle's API cannot remove an
 * override, and it holds only an override to complete. An activity overridden to incomplete is calculated again by
 * Moodle's own rules at the next trigger, so a SCORM with tracks that meet its rules becomes complete again. Nothing else
 * is changed or deleted: no SCORM track, quiz attempt, grade, result or course completion.
 *
 * It refuses unless config.php says the site is a staging site, like {@see staging_completion}.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class test_student_reset {
    /** @var string[] The events by which the plugin records an override it made. */
    public const RECORD_EVENTS = [content_autocompleted::class, staging_completion_overridden::class];

    /** @var string The override is the plugin's and still stands: it is taken back. */
    public const REVERT = 'revert';

    /** @var string It was taken back. */
    public const REVERTED = 'reverted';

    /** @var string Moodle did not change the state when asked to. */
    public const FAILED = 'failed';

    /** @var string The activity is not complete: there is nothing to take back. */
    public const NOT_COMPLETE = 'notcomplete';

    /** @var string The state is no longer an override: Moodle calculated it again by its own rules. */
    public const NOT_AN_OVERRIDE = 'notanoverride';

    /** @var string The state changed after the plugin's record, or was overridden by somebody else. */
    public const CHANGED_SINCE = 'changedsince';

    /** @var string The activity is no longer in the course. */
    public const GONE = 'gone';

    /** @var string The activity no longer tracks completion. */
    public const UNTRACKED = 'untracked';

    /**
     * What a reset would do. Changes nothing.
     *
     * @param int $courseid
     * @param int $userid
     * @return array See {@see self::apply()}; `overrides` carry the action that apply() would take.
     * @throws \moodle_exception When the site is not a staging site or the user is not a student of the course.
     */
    public static function plan(int $courseid, int $userid): array {
        global $DB;

        self::require_allowed($courseid, $userid);
        $records = self::records($courseid, $userid);
        $course = get_course($courseid);
        $overrides = self::overrides($course, $userid, $records ?? []);

        $flow = $DB->get_records(
            flow_progress::TABLE,
            ['courseid' => $courseid, 'userid' => $userid],
            'mapid ASC, timecompleted ASC, id ASC',
            'id, mapid, stepkey, revision, method, timecompleted'
        );

        return [
            'readable' => $records !== null,
            'flow' => array_values($flow),
            'overrides' => $overrides,
            'unrecorded' => self::unrecorded_overrides($courseid, $userid, array_column($overrides, 'cmid')),
            'untouched' => self::untouched($courseid, $userid),
        ];
    }

    /**
     * Resets: deletes the flow rows and takes back the plugin's overrides that still stand.
     *
     * The current user needs moodle/course:overridecompletion when there is an override to take back.
     *
     * @param int $courseid
     * @param int $userid
     * @return array With:
     *      - readable: whether a log store could be read; without one the plugin cannot know its overrides;
     *      - flow: the flow rows that were there (and are deleted);
     *      - overrides: one per activity the plugin recorded, each with cmid, name, modname, state (before), action,
     *        and scormattempts (the student's SCORM attempts in it);
     *      - unrecorded: ids of activities with an override the plugin has no record of, which are left alone;
     *      - untouched: counts of what a reset never changes.
     * @throws \moodle_exception When the site is not a staging site or the user is not a student of the course.
     */
    public static function apply(int $courseid, int $userid): array {
        $report = self::plan($courseid, $userid);
        $completion = new \completion_info(get_course($courseid));

        // The overrides first: if one throws, the flow rows are still there and the run can be repeated.
        foreach ($report['overrides'] as $key => $item) {
            if ($item['action'] !== self::REVERT) {
                continue;
            }
            $cm = get_fast_modinfo($courseid)->get_cm($item['cmid']);
            $completion->update_state($cm, COMPLETION_INCOMPLETE, $userid, true);
            $after = (int) $completion->get_data($cm, false, $userid)->completionstate;
            $report['overrides'][$key]['action'] = $after === COMPLETION_INCOMPLETE ? self::REVERTED : self::FAILED;
        }

        foreach (array_unique(array_column($report['flow'], 'mapid')) as $mapid) {
            flow_progress::reset_map($courseid, $userid, $mapid);
        }
        return $report;
    }

    /**
     * Refuses a site that is not a staging site, and a user who is not a student of the course.
     *
     * @param int $courseid
     * @param int $userid
     * @throws \moodle_exception
     */
    private static function require_allowed(int $courseid, int $userid): void {
        $refusal = staging_completion::refusal();
        if ($refusal !== null) {
            throw new \moodle_exception('stagingonly', 'format_rumbos');
        }
        staging_completion::require_student($courseid, $userid);
    }

    /**
     * The latest record the plugin made of an override, per activity.
     *
     * @param int $courseid
     * @param int $userid
     * @return array[]|null By course module id, each with actor, time and state (what the plugin set); null when no log
     *      store can be read.
     */
    private static function records(int $courseid, int $userid): ?array {
        global $DB;

        $readers = get_log_manager()->get_readers(\core\log\sql_reader::class);
        if (!$readers) {
            return null;
        }
        $names = array_map(fn(string $class): string => '\\' . $class, self::RECORD_EVENTS);
        [$insql, $params] = $DB->get_in_or_equal($names, SQL_PARAMS_NAMED, 'event');
        $select = "courseid = :courseid AND relateduserid = :userid AND eventname $insql";
        $params += ['courseid' => $courseid, 'userid' => $userid];

        $records = [];
        foreach ($readers as $reader) {
            foreach ($reader->get_events_select($select, $params, 'timecreated ASC, id ASC', 0, 0) as $event) {
                $cmid = (int) $event->objectid;
                if (isset($records[$cmid]) && $records[$cmid]['time'] > (int) $event->timecreated) {
                    continue;
                }
                $records[$cmid] = [
                    'actor' => (int) $event->userid,
                    'time' => (int) $event->timecreated,
                    'state' => $event instanceof content_autocompleted ? COMPLETION_COMPLETE : (int) $event->other['state'],
                ];
            }
        }
        return $records;
    }

    /**
     * Decides, per recorded activity, whether the plugin's override still stands.
     *
     * @param \stdClass $course
     * @param int $userid
     * @param array[] $records What {@see self::records()} returned.
     * @return array[]
     */
    private static function overrides(\stdClass $course, int $userid, array $records): array {
        $modinfo = get_fast_modinfo($course);
        $completion = new \completion_info($course);
        $items = [];
        ksort($records);
        foreach ($records as $cmid => $record) {
            $cm = $modinfo->get_cms()[$cmid] ?? null;
            $item = ['cmid' => $cmid, 'name' => '', 'modname' => '', 'state' => null, 'action' => self::GONE, 'scormattempts' => 0];
            if ($cm !== null && !$cm->deletioninprogress) {
                $data = $completion->get_data($cm, false, $userid);
                $item['name'] = $cm->name;
                $item['modname'] = $cm->modname;
                $item['state'] = (int) $data->completionstate;
                $item['scormattempts'] = $cm->modname === 'scorm' ? self::scorm_attempts((int) $cm->instance, $userid) : 0;
                $item['action'] = self::action($completion, $cm, $data, $record);
            }
            $items[] = $item;
        }
        return $items;
    }

    /**
     * What to do with one recorded activity that is still in the course.
     *
     * @param \completion_info $completion
     * @param \cm_info $cm
     * @param \stdClass $data The completion data now.
     * @param array $record The plugin's latest record.
     * @return string One of the action constants.
     */
    private static function action(\completion_info $completion, \cm_info $cm, \stdClass $data, array $record): string {
        if ($completion->is_enabled($cm) == COMPLETION_TRACKING_NONE) {
            return self::UNTRACKED;
        }
        if (!in_array((int) $data->completionstate, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS], true)) {
            return self::NOT_COMPLETE;
        }
        if (empty($data->overrideby)) {
            return self::NOT_AN_OVERRIDE;
        }
        // The plugin records an override right after making it, so a later modification is not that override.
        if (
            $record['state'] !== COMPLETION_COMPLETE || (int) $data->overrideby !== $record['actor'] ||
                (int) $data->timemodified > $record['time']
        ) {
            return self::CHANGED_SINCE;
        }
        return self::REVERT;
    }

    /**
     * The activities of the course with an override for the user that the plugin has no record of.
     *
     * @param int $courseid
     * @param int $userid
     * @param int[] $recorded
     * @return int[] Course module ids.
     */
    private static function unrecorded_overrides(int $courseid, int $userid, array $recorded): array {
        global $DB;

        $ids = $DB->get_fieldset_sql(
            'SELECT cm.id
               FROM {course_modules_completion} cmc
               JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
              WHERE cm.course = :courseid AND cmc.userid = :userid AND cmc.overrideby IS NOT NULL
           ORDER BY cm.id',
            ['courseid' => $courseid, 'userid' => $userid]
        );
        return array_values(array_diff(array_map('intval', $ids), $recorded));
    }

    /**
     * How many attempts the user has in a SCORM.
     *
     * @param int $scormid
     * @param int $userid
     * @return int
     */
    private static function scorm_attempts(int $scormid, int $userid): int {
        global $DB;

        return $DB->count_records('scorm_attempt', ['scormid' => $scormid, 'userid' => $userid]);
    }

    /**
     * Counts what the user has in the course that a reset never changes.
     *
     * @param int $courseid
     * @param int $userid
     * @return array scormattempts, quizattempts, grades, results, coursecomplete (bool), autocompletequeued (int).
     */
    private static function untouched(int $courseid, int $userid): array {
        global $DB;

        $params = ['courseid' => $courseid, 'userid' => $userid];
        $queued = 0;
        foreach (\core\task\manager::get_adhoc_tasks(autocomplete_content::class) as $task) {
            $data = $task->get_custom_data();
            $queued += (int) ((int) $data->courseid === $courseid && (int) $data->userid === $userid);
        }
        return [
            'scormattempts' => $DB->count_records_sql(
                'SELECT COUNT(a.id) FROM {scorm_attempt} a JOIN {scorm} s ON s.id = a.scormid
                  WHERE s.course = :courseid AND a.userid = :userid',
                $params
            ),
            'quizattempts' => $DB->count_records_sql(
                'SELECT COUNT(a.id) FROM {quiz_attempts} a JOIN {quiz} q ON q.id = a.quiz
                  WHERE q.course = :courseid AND a.userid = :userid',
                $params
            ),
            'grades' => $DB->count_records_sql(
                'SELECT COUNT(g.id) FROM {grade_grades} g JOIN {grade_items} i ON i.id = g.itemid
                  WHERE i.courseid = :courseid AND g.userid = :userid AND g.finalgrade IS NOT NULL',
                $params
            ),
            'results' => $DB->count_records('format_rumbos_result', $params),
            'coursecomplete' => (new \completion_info(get_course($courseid)))->is_course_complete($userid),
            'autocompletequeued' => $queued,
        ];
    }
}
