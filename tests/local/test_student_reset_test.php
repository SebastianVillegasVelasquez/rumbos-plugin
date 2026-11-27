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

/**
 * Tests for the staging-only reset of a test student.
 *
 * The plugin's record of an override is its log event, so these tests run with the standard log store on.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\local\test_student_reset::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\event\staging_completion_overridden::class)]
final class test_student_reset_test extends \advanced_testcase {
    /** @var string The map. */
    private const MAP = '3f0c2a52-6c1e-4f6e-9a8e-0d6b1f9a7c11';

    /** @var string A second map id the student has rows on. */
    private const OTHERMAP = 'aaaaaaaa-6c1e-4f6e-9a8e-0d6b1f9a7c11';

    /**
     * A staging site with a log store, a course with two tracked SCORM activities, and two students with flow rows.
     *
     * @return array [course, student, other student, scorm cmid A, scorm cmid B]
     */
    private function setup_course(): array {
        global $CFG;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $CFG->config_php_settings[staging_completion::CONFIG_FLAG] = true;
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        set_config('buffersize', 0, 'logstore_standard');
        set_config('logguests', 1, 'logstore_standard');
        get_log_manager(true);

        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'rumbos', 'enablecompletion' => 1]);
        $student = $generator->create_and_enrol($course, 'student');
        $other = $generator->create_and_enrol($course, 'student');
        $a = $generator->create_module('scorm', [
            'course' => $course->id, 'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionview' => 1,
        ]);
        $b = $generator->create_module('scorm', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);

        foreach ([$student, $other] as $user) {
            flow_progress::record($course->id, $user->id, self::MAP, 'welcome', 1, 'completed');
            flow_progress::record($course->id, $user->id, self::MAP, 'final-exam', 1, 'fallback');
        }
        flow_progress::record($course->id, $student->id, self::OTHERMAP, 'welcome', 1, 'completed');
        return [$course, $student, $other, (int) $a->cmid, (int) $b->cmid];
    }

    /**
     * The completion row of a student in an activity.
     *
     * @param int $cmid
     * @param \stdClass $student
     * @return \stdClass|false
     */
    private function row(int $cmid, \stdClass $student): \stdClass|false {
        global $DB;
        return $DB->get_record('course_modules_completion', ['coursemoduleid' => $cmid, 'userid' => $student->id]);
    }

    /**
     * The action of each activity of a report, by course module id.
     *
     * @param array $report
     * @return string[]
     */
    private function actions(array $report): array {
        return array_column($report['overrides'], 'action', 'cmid');
    }

    /**
     * Without the staging flag it refuses, to plan or to apply, and changes nothing.
     */
    public function test_a_site_that_is_not_marked_as_staging_is_refused(): void {
        global $CFG, $DB;
        [$course, $student, , $a] = $this->setup_course();
        staging_completion::apply($course->id, $student->id, true);
        $rows = $DB->count_records(flow_progress::TABLE);

        foreach ([null, false, 1, '1'] as $flag) {
            $CFG->config_php_settings[staging_completion::CONFIG_FLAG] = $flag;
            foreach (['plan', 'apply'] as $method) {
                try {
                    test_student_reset::$method($course->id, $student->id);
                    $this->fail("$method ran with the flag " . var_export($flag, true));
                } catch (\moodle_exception $e) {
                    $this->assertSame('stagingonly', $e->errorcode);
                }
            }
        }
        $this->assertSame($rows, $DB->count_records(flow_progress::TABLE));
        $this->assertEquals(COMPLETION_COMPLETE, $this->row($a, $student)->completionstate);
    }

    /**
     * A user who is not enrolled, a suspended one and an editor are refused, and nothing changes.
     */
    public function test_only_an_enrolled_student_can_be_reset(): void {
        global $DB;
        [$course] = $this->setup_course();
        $generator = $this->getDataGenerator();
        $stranger = $generator->create_user();
        $suspended = $generator->create_and_enrol($course, 'student', null, 'manual', 0, 0, ENROL_USER_SUSPENDED);
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        foreach ([$stranger, $suspended, $teacher] as $user) {
            flow_progress::record($course->id, $user->id, self::MAP, 'welcome', 1, 'completed');
        }
        $rows = $DB->count_records(flow_progress::TABLE);

        foreach ([$stranger, $suspended, $teacher] as $user) {
            try {
                test_student_reset::apply($course->id, $user->id);
                $this->fail('User ' . $user->id . ' should be refused.');
            } catch (\moodle_exception $e) {
                $this->assertSame('invaliduser', $e->errorcode);
            }
        }
        $this->assertSame($rows, $DB->count_records(flow_progress::TABLE));
    }

    /**
     * A plan lists the rows and the overrides, and changes nothing.
     */
    public function test_the_plan_changes_nothing(): void {
        global $DB;
        [$course, $student, , $a, $b] = $this->setup_course();
        staging_completion::apply($course->id, $student->id, true);
        $before = [
            $DB->get_records(flow_progress::TABLE, null, 'id'),
            $DB->get_records('course_modules_completion', null, 'id'),
        ];

        $report = test_student_reset::plan($course->id, $student->id);

        $this->assertTrue($report['readable']);
        $this->assertCount(3, $report['flow']);
        $this->assertSame([$a => 'revert', $b => 'revert'], $this->actions($report));
        $this->assertEquals($before, [
            $DB->get_records(flow_progress::TABLE, null, 'id'),
            $DB->get_records('course_modules_completion', null, 'id'),
        ]);
    }

    /**
     * Applying deletes this student's rows of both flows and takes back the staging helper's overrides, and only theirs.
     */
    public function test_apply_resets_this_student_only(): void {
        global $DB;
        [$course, $student, $other, $a, $b] = $this->setup_course();
        staging_completion::apply($course->id, $student->id, true);
        staging_completion::apply($course->id, $other->id, true);

        $report = test_student_reset::apply($course->id, $student->id);

        $this->assertSame([$a => 'reverted', $b => 'reverted'], $this->actions($report));
        $this->assertSame(0, $DB->count_records(flow_progress::TABLE, ['userid' => $student->id]));
        $this->assertSame(2, $DB->count_records(flow_progress::TABLE, ['userid' => $other->id]));
        foreach ([$a, $b] as $cmid) {
            $this->assertEquals(COMPLETION_INCOMPLETE, $this->row($cmid, $student)->completionstate);
            $this->assertEquals(COMPLETION_COMPLETE, $this->row($cmid, $other)->completionstate);
        }

        // A second run has nothing left to do.
        $again = test_student_reset::apply($course->id, $student->id);
        $this->assertSame([], $again['flow']);
        $this->assertSame([$a => 'notcomplete', $b => 'notcomplete'], $this->actions($again));
    }

    /**
     * What auto-completion overrode is taken back as well: its event is the record.
     */
    public function test_overrides_of_auto_completion_are_taken_back(): void {
        [$course, $student, , $a] = $this->setup_course();
        $completion = new \completion_info($course);
        $cm = get_fast_modinfo($course)->get_cm($a);
        $completion->update_state($cm, COMPLETION_COMPLETE, $student->id, true);
        \format_rumbos\event\content_autocompleted::create([
            'context' => \core\context\module::instance($a),
            'objectid' => $a,
            'userid' => (int) get_admin()->id,
            'relateduserid' => $student->id,
            'courseid' => $course->id,
            'other' => ['jti' => 'j1', 'purpose' => 'check_in', 'previousstate' => 0, 'modname' => 'scorm'],
        ])->trigger();

        $report = test_student_reset::apply($course->id, $student->id);

        $this->assertSame([$a => 'reverted'], $this->actions($report));
        $this->assertEquals(COMPLETION_INCOMPLETE, $this->row($a, $student)->completionstate);
    }

    /**
     * An override somebody else made, with no record of the plugin, is left alone and reported.
     */
    public function test_an_override_the_plugin_did_not_record_is_left_alone(): void {
        [$course, $student, , $a] = $this->setup_course();
        $completion = new \completion_info($course);
        $completion->update_state(get_fast_modinfo($course)->get_cm($a), COMPLETION_COMPLETE, $student->id, true);

        $report = test_student_reset::apply($course->id, $student->id);

        $this->assertSame([], $report['overrides']);
        $this->assertSame([$a], $report['unrecorded']);
        $this->assertEquals(COMPLETION_COMPLETE, $this->row($a, $student)->completionstate);
    }

    /**
     * An activity the student completed on their own has no record, and one changed after the record is left alone.
     */
    public function test_later_changes_are_left_alone(): void {
        global $DB;
        [$course, $student, , $a, $b] = $this->setup_course();
        staging_completion::apply($course->id, $student->id, true);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        // A teacher takes A back and overrides it again later; B is calculated by Moodle (no override).
        $DB->set_field('course_modules_completion', 'overrideby', $teacher->id, ['coursemoduleid' => $a, 'userid' => $student->id]);
        $DB->set_field('course_modules_completion', 'overrideby', null, ['coursemoduleid' => $b, 'userid' => $student->id]);
        \cache::make('core', 'completion')->purge();

        $report = test_student_reset::apply($course->id, $student->id);

        $this->assertSame([$a => 'changedsince', $b => 'notanoverride'], $this->actions($report));
        $this->assertEquals(COMPLETION_COMPLETE, $this->row($a, $student)->completionstate);
        $this->assertEquals(COMPLETION_COMPLETE, $this->row($b, $student)->completionstate);
    }

    /**
     * The same overrider changing the activity after the record is also a later change.
     */
    public function test_a_later_override_by_the_same_account_is_left_alone(): void {
        global $DB;
        [$course, $student, , $a] = $this->setup_course();
        staging_completion::apply($course->id, $student->id, true);
        $where = ['coursemoduleid' => $a, 'userid' => $student->id];
        $DB->set_field('course_modules_completion', 'timemodified', time() + 60, $where);
        \cache::make('core', 'completion')->purge();

        $report = test_student_reset::plan($course->id, $student->id);

        $this->assertSame('changedsince', $this->actions($report)[$a]);
    }

    /**
     * With no log store the plugin cannot know its overrides: it says so, takes none back and still resets the flows.
     */
    public function test_without_a_log_store_no_override_is_touched(): void {
        global $DB;
        [$course, $student, , $a, $b] = $this->setup_course();
        staging_completion::apply($course->id, $student->id, true);
        set_config('enabled_stores', '', 'tool_log');
        get_log_manager(true);

        $report = test_student_reset::apply($course->id, $student->id);

        $this->assertFalse($report['readable']);
        $this->assertSame([], $report['overrides']);
        $this->assertSame([$a, $b], $report['unrecorded']);
        $this->assertEquals(COMPLETION_COMPLETE, $this->row($a, $student)->completionstate);
        $this->assertSame(0, $DB->count_records(flow_progress::TABLE, ['userid' => $student->id]));
    }

    /**
     * SCORM attempts and tracks, grades and results are counted and kept; the report names the attempts per activity.
     */
    public function test_evidence_is_never_deleted(): void {
        global $DB;
        [$course, $student, , $a] = $this->setup_course();
        $scormid = get_fast_modinfo($course)->get_cm($a)->instance;
        $attemptid = $DB->insert_record('scorm_attempt', ['userid' => $student->id, 'scormid' => $scormid, 'attempt' => 1]);
        $item = new \grade_item(['courseid' => $course->id, 'itemtype' => 'manual', 'itemname' => 'x'], false);
        $item->insert();
        $item->update_final_grade($student->id, 50);
        staging_completion::apply($course->id, $student->id, true);

        $report = test_student_reset::apply($course->id, $student->id);

        $this->assertSame(1, $report['untouched']['scormattempts']);
        $this->assertSame(1, array_column($report['overrides'], 'scormattempts', 'cmid')[$a]);
        $this->assertGreaterThanOrEqual(1, $report['untouched']['grades']);
        $this->assertTrue($DB->record_exists('scorm_attempt', ['id' => $attemptid]));
        $this->assertEquals(50, $item->get_grade($student->id, false)->finalgrade);
    }

    /**
     * The staging helper records each override it actually makes, and none when nothing changes.
     */
    public function test_the_staging_helper_records_what_it_changes(): void {
        [$course, $student] = $this->setup_course();
        $sink = $this->redirectEvents();

        staging_completion::apply($course->id, $student->id, true);
        staging_completion::apply($course->id, $student->id, true);

        $events = array_filter(
            $sink->get_events(),
            fn($e) => $e instanceof \format_rumbos\event\staging_completion_overridden
        );
        $this->assertCount(2, $events);
    }
}
