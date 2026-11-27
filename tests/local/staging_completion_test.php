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
 * Tests for the staging helper that marks SCORM activities complete or incomplete.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\local\staging_completion::class)]
final class staging_completion_test extends \advanced_testcase {
    /**
     * A completion-enabled course with a tracked SCORM, an untracked SCORM, a tracked page and a student.
     *
     * @return array [course, student, tracked scorm cm, untracked scorm cm, page cm]
     */
    private function course(): array {
        $this->resetAfterTest();
        // The SCORM generator needs a current user.
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'rumbos', 'enablecompletion' => 1]);
        $student = $generator->create_and_enrol($course, 'student');
        $tracked = $generator->create_module('scorm', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $untracked = $generator->create_module('scorm', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_NONE]);
        $page = $generator->create_module('page', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        return [$course, $student, $tracked, $untracked, $page];
    }

    /**
     * A site that does not say it is staging is refused, whatever else its configuration holds.
     */
    public function test_a_site_that_is_not_marked_as_staging_is_refused(): void {
        global $CFG;
        $this->resetAfterTest();

        unset($CFG->config_php_settings[staging_completion::CONFIG_FLAG]);
        $this->assertNotNull(staging_completion::refusal());
        $this->assertStringContainsString('staging', (string) staging_completion::refusal());

        // Only the boolean true counts: a string or a number is not a deliberate opt-in.
        foreach (['1', 1, 'true', 'yes', false, null] as $value) {
            $CFG->config_php_settings[staging_completion::CONFIG_FLAG] = $value;
            $this->assertNotNull(staging_completion::refusal(), var_export($value, true));
        }
    }

    /**
     * A site whose config.php opts in is allowed, although Moodle has by then turned the value in $CFG into a string:
     * it loads the core settings over $CFG while it starts, the config.php ones included.
     */
    public function test_a_site_marked_as_staging_is_allowed(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->config_php_settings[staging_completion::CONFIG_FLAG] = true;
        // What lib/setup.php leaves behind (initialise_cfg()).
        $CFG->{staging_completion::CONFIG_FLAG} = get_config('core', staging_completion::CONFIG_FLAG);

        $this->assertSame('1', $CFG->{staging_completion::CONFIG_FLAG});
        $this->assertNull(staging_completion::refusal());
    }

    /**
     * Only config.php can opt in: a setting in the database, or a value set while running, does not.
     */
    public function test_nothing_but_config_php_can_allow_it(): void {
        global $CFG;
        $this->resetAfterTest();

        set_config(staging_completion::CONFIG_FLAG, 1);
        $this->assertNotNull(staging_completion::refusal(), 'a database setting');

        $CFG->{staging_completion::CONFIG_FLAG} = true;
        $this->assertNotNull(staging_completion::refusal(), 'set while running');
    }

    /**
     * The plan lists only the SCORM activities that track completion, with their state now.
     */
    public function test_the_plan_is_the_tracked_scorm_activities(): void {
        [$course, $student, $tracked] = $this->course();

        $plan = staging_completion::plan($course->id, $student->id);

        $this->assertSame([(int) $tracked->cmid], array_column($plan, 'cmid'));
        $this->assertSame([COMPLETION_INCOMPLETE], array_column($plan, 'state'));
    }

    /**
     * Marking complete, then incomplete, changes the state and records the administrator as the one who overrode it.
     */
    public function test_marking_complete_and_incomplete_records_the_override(): void {
        global $DB;
        [$course, $student, $tracked, , $page] = $this->course();
        $this->setAdminUser();

        $after = staging_completion::apply($course->id, $student->id, true);

        $this->assertSame([COMPLETION_COMPLETE], array_column($after, 'state'));
        $row = $DB->get_record('course_modules_completion', ['coursemoduleid' => $tracked->cmid, 'userid' => $student->id]);
        $this->assertSame(get_admin()->id, (string) $row->overrideby);
        // A module that was not asked for is left alone.
        $this->assertFalse($DB->record_exists('course_modules_completion', ['coursemoduleid' => $page->cmid]));

        $after = staging_completion::apply($course->id, $student->id, false);

        $this->assertSame([COMPLETION_INCOMPLETE], array_column($after, 'state'));
    }

    /**
     * Running it twice leaves the same state: the second run changes nothing.
     */
    public function test_it_is_idempotent(): void {
        global $DB;
        [$course, $student] = $this->course();
        $this->setAdminUser();

        staging_completion::apply($course->id, $student->id, true);
        $first = $DB->get_records('course_modules_completion', ['userid' => $student->id]);
        staging_completion::apply($course->id, $student->id, true);

        $this->assertEquals($first, $DB->get_records('course_modules_completion', ['userid' => $student->id]));
    }

    /**
     * Someone who may not override completion cannot use it, even with the staging flag.
     */
    public function test_the_override_needs_the_capability(): void {
        [$course, $student] = $this->course();
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);
        staging_completion::apply($course->id, $student->id, true);
    }

    /**
     * Only a real student of the course can be marked: not an editor, not a stranger, not a suspended student.
     */
    public function test_only_an_active_student_of_the_course_can_be_marked(): void {
        global $DB;
        [$course, $student] = $this->course();
        $generator = $this->getDataGenerator();
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $stranger = $generator->create_user();
        $suspended = $generator->create_and_enrol($course, 'student', null, 'manual', 0, 0, ENROL_USER_SUSPENDED);
        $this->setAdminUser();

        foreach ([$teacher, $stranger, $suspended] as $user) {
            try {
                staging_completion::plan($course->id, $user->id);
                $this->fail('User ' . $user->id . ' should be refused.');
            } catch (\moodle_exception $e) {
                $this->assertSame('invaliduser', $e->errorcode);
            }
        }
        $this->assertSame(0, $DB->count_records('course_modules_completion'));
        $this->assertNotEmpty(staging_completion::plan($course->id, $student->id));
    }
}
