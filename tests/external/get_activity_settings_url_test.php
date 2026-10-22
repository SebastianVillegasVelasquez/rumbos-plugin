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

namespace format_rumbos\external;

use core\context\module as context_module;
use core_external\external_api;
use format_rumbos\local\activity_settings;

/**
 * Tests for the function that tells a map editor where to configure an activity's completion.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\external\get_activity_settings_url::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\local\activity_settings::class)]
final class get_activity_settings_url_test extends \advanced_testcase {
    /**
     * Creates a course with a quiz, a student, an editing teacher and a non-editing teacher.
     *
     * @return array [course, cmid, student, teacher, assistant]
     */
    private function setup_course(): array {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['format' => 'rumbos', 'enablecompletion' => 1]);
        $quiz = $gen->create_module('quiz', ['course' => $course->id]);
        $student = $gen->create_user();
        $teacher = $gen->create_user();
        $assistant = $gen->create_user();
        $gen->enrol_user($student->id, $course->id, 'student');
        $gen->enrol_user($teacher->id, $course->id, 'editingteacher');
        $gen->enrol_user($assistant->id, $course->id, 'teacher');
        return [$course, (int) $quiz->cmid, $student, $teacher, $assistant];
    }

    /**
     * Runs the function as a user and validates what it returns.
     *
     * @param \stdClass $user
     * @param int $courseid
     * @param int $cmid
     * @return array
     */
    private function call_as(\stdClass $user, int $courseid, int $cmid): array {
        $this->setUser($user);
        return external_api::clean_returnvalue(
            get_activity_settings_url::execute_returns(),
            get_activity_settings_url::execute($courseid, $cmid)
        );
    }

    /**
     * There is no user id parameter, and the caller never supplies an address.
     */
    public function test_parameters_are_only_the_course_and_the_activity(): void {
        $this->assertSame(['courseid', 'cmid'], array_keys(get_activity_settings_url::execute_parameters()->keys));
        $this->assertSame(['url'], array_keys(get_activity_settings_url::execute_returns()->keys));
    }

    /**
     * An editing teacher gets the settings page of the activity, on this site, with the completion section.
     */
    public function test_editor_gets_the_settings_page_on_this_site(): void {
        global $CFG;
        $this->resetAfterTest();
        [$course, $cmid, , $teacher] = $this->setup_course();

        $result = $this->call_as($teacher, (int) $course->id, $cmid);

        $url = new \moodle_url($result['url']);
        $this->assertStringStartsWith($CFG->wwwroot . '/course/modedit.php?', $result['url']);
        $this->assertSame((string) $cmid, $url->get_param('update'));
        $this->assertSame('activitycompletionheader', $url->get_param('showonly'));
        $this->assertSame($url->out(false), activity_settings::completion_url($cmid)->out(false));
    }

    /**
     * Students cannot get it, and neither can a teacher who may not edit the map.
     */
    public function test_capability_is_required(): void {
        $this->resetAfterTest();
        [$course, $cmid, $student, , $assistant] = $this->setup_course();

        foreach ([$student, $assistant] as $user) {
            try {
                $this->call_as($user, (int) $course->id, $cmid);
                $this->fail('A user without the capability must not get the address.');
            } catch (\required_capability_exception $exception) {
                $this->assertInstanceOf(\required_capability_exception::class, $exception);
            }
        }
    }

    /**
     * An editor who may not manage this activity gets nothing, even though they may edit the map.
     */
    public function test_managing_the_activity_is_required(): void {
        $this->resetAfterTest();
        [$course, $cmid, , $teacher] = $this->setup_course();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('moodle/course:manageactivities', CAP_PROHIBIT, $roleid, context_module::instance($cmid)->id, true);
        $this->getDataGenerator()->role_assign($roleid, $teacher->id, context_module::instance($cmid)->id);

        $this->expectException(\required_capability_exception::class);
        $this->call_as($teacher, (int) $course->id, $cmid);
    }

    /**
     * An activity that does not exist, or that belongs to another course, is rejected.
     */
    public function test_unknown_activity_is_rejected(): void {
        $this->resetAfterTest();
        [$course, $cmid, , $teacher] = $this->setup_course();
        $other = $this->getDataGenerator()->create_course();
        $foreign = $this->getDataGenerator()->create_module('page', ['course' => $other->id]);
        $this->getDataGenerator()->enrol_user($teacher->id, $other->id, 'editingteacher');

        foreach ([$cmid + 1000, (int) $foreign->cmid, 0, -1] as $unknown) {
            try {
                $this->call_as($teacher, (int) $course->id, $unknown);
                $this->fail("Course module $unknown must be rejected.");
            } catch (\moodle_exception $exception) {
                $this->assertInstanceOf(\moodle_exception::class, $exception);
            }
        }
    }

    /**
     * Users outside the course and guests get nothing.
     */
    public function test_outsiders_and_guests_are_rejected(): void {
        $this->resetAfterTest();
        [$course, $cmid] = $this->setup_course();
        $outsider = $this->getDataGenerator()->create_user();

        try {
            $this->call_as($outsider, (int) $course->id, $cmid);
            $this->fail('An outsider must be rejected.');
        } catch (\moodle_exception $exception) {
            $this->assertInstanceOf(\moodle_exception::class, $exception);
        }

        $this->setGuestUser();
        $this->expectException(\moodle_exception::class);
        get_activity_settings_url::execute((int) $course->id, $cmid);
    }
}
