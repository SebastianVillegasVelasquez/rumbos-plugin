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
}
