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

/**
 * Tests for who may use Rumbos and in which mode the map runs.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\local\access::class)]
final class access_test extends \advanced_testcase {
    /**
     * Creates a user enrolled in a new Rumbos course with a role.
     *
     * @param string $role Role shortname.
     * @return array [context, user]
     */
    private function enrolled(string $role): array {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['format' => 'rumbos']);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, $role);
        $this->setUser($user);
        return [context_course::instance($course->id), $user];
    }

    /**
     * The map editor needs the capability AND editing mode.
     */
    public function test_editor_mode_needs_capability_and_editing(): void {
        [$context] = $this->enrolled('editingteacher');

        $this->assertSame('editor', access::map_mode($context, true));
        $this->assertSame('student', access::map_mode($context, false));
    }

    /**
     * Students never get the editor, whatever the editing flag says.
     */
    public function test_students_are_never_editors(): void {
        [$context] = $this->enrolled('student');

        $this->assertSame('student', access::map_mode($context, true));
        $this->assertSame('student', access::map_mode($context, false));
    }

    /**
     * A teacher without the map capability is a student for the map even with editing on.
     */
    public function test_editing_without_the_capability_is_not_enough(): void {
        [$context] = $this->enrolled('teacher');

        $this->assertSame('student', access::map_mode($context, true));
    }

    /**
     * Only a user who could edit the map is reminded to turn editing on.
     */
    public function test_the_editing_reminder_is_for_would_be_editors_only(): void {
        [$context] = $this->enrolled('editingteacher');
        $this->assertTrue(access::should_suggest_editing($context, false));
        $this->assertFalse(access::should_suggest_editing($context, true));

        [$context] = $this->enrolled('student');
        $this->assertFalse(access::should_suggest_editing($context, false));
        $this->assertFalse(access::should_suggest_editing($context, true));
    }

    /**
     * Creates a user with a site-wide manager role who is NOT enrolled in a new Rumbos course.
     *
     * @return array [context, user]
     */
    private function unenrolled_manager(): array {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['format' => 'rumbos']);
        $user = $this->getDataGenerator()->create_user();
        $managerid = (int) $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        role_assign($managerid, $user->id, \context_system::instance()->id);
        $this->setUser($user);
        return [context_course::instance($course->id), $user];
    }

    /**
     * Switches the current user to a role in a course, as the "Switch role to..." menu does.
     *
     * @param context_course $context
     * @param string $shortname Role shortname.
     */
    private function switch_role(context_course $context, string $shortname): void {
        global $DB;

        role_switch((int) $DB->get_field('role', 'id', ['shortname' => $shortname], MUST_EXIST), $context);
    }

    /**
     * A real student is a participant and sees the map in student mode.
     */
    public function test_an_enrolled_student_can_use_rumbos(): void {
        [$context] = $this->enrolled('student');

        $this->assertTrue(access::can_use($context));
    }

    /**
     * A user who is neither enrolled nor able to edit the map is not a participant.
     */
    public function test_a_stranger_cannot_use_rumbos(): void {
        [$context] = $this->enrolled('student');
        $this->setUser($this->getDataGenerator()->create_user());

        $this->assertFalse(access::can_use($context));
    }

    /**
     * Guests are never participants.
     */
    public function test_a_guest_cannot_use_rumbos(): void {
        [$context] = $this->enrolled('student');
        $this->setGuestUser();

        $this->assertFalse(access::can_use($context));
    }

    /**
     * A manager who is not enrolled and switches to Student keeps Rumbos, as a student.
     *
     * Without this the switched manager lost the container and the external functions, which
     * looked as if students could not see Rumbos.
     */
    public function test_a_switched_role_manager_who_is_not_enrolled_still_gets_rumbos_as_a_student(): void {
        [$context] = $this->unenrolled_manager();
        $this->assertTrue(access::can_use($context));
        $this->assertSame('editor', access::map_mode($context, true));

        $this->switch_role($context, 'student');

        $this->assertTrue(access::can_use($context));
        $this->assertSame('student', access::map_mode($context, true));
        $this->assertFalse(access::should_suggest_editing($context, false));
    }
}
