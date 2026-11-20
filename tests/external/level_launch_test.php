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

use format_rumbos\level_fixture;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/level_fixture.php');

/**
 * Tests that the launch assertion is signed only for users who may open the level.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\external\get_launch_assertion::class)]
final class level_launch_test extends \advanced_testcase {
    use level_fixture;

    /**
     * Two levels, A1 then A2, with the gate on.
     *
     * @return \stdClass[] [A1, A2]
     */
    private function two_levels(): array {
        $this->start_levels();
        $a1 = $this->level('1000');
        $a2 = $this->level('1001');
        $this->gate_on(['1000', '1001']);
        return [$a1, $a2];
    }

    /**
     * A student who has not completed the previous level gets no assertion.
     */
    public function test_a_locked_student_gets_no_assertion(): void {
        [$a1, $a2] = $this->two_levels();
        $this->setUser($this->student($a1, $a2));

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('errorlevellocked', 'format_rumbos'));
        get_launch_assertion::execute($a2->id);
    }

    /**
     * A student who completed the previous level gets the assertion.
     */
    public function test_a_student_who_completed_the_previous_level_gets_one(): void {
        [$a1, $a2] = $this->two_levels();
        $user = $this->student($a1, $a2);
        $this->complete($a1, $user);
        $this->setUser($user);

        $result = get_launch_assertion::execute($a2->id);

        $this->assertNotSame('', $result['assertion']);
    }

    /**
     * A check that cannot run gives no assertion either, with the retry message.
     */
    public function test_a_failed_check_gives_no_assertion(): void {
        $this->start_levels();
        $a2 = $this->level('1001');
        $this->gate_on(['1000', '1001']);
        $this->setUser($this->student($a2));

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('errorlevelcheck', 'format_rumbos'));
        get_launch_assertion::execute($a2->id);
    }

    /**
     * Teachers and administrators still get theirs.
     */
    public function test_staff_get_an_assertion(): void {
        [, $a2] = $this->two_levels();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $a2->id, 'editingteacher');

        $this->setUser($teacher);
        $this->assertNotSame('', get_launch_assertion::execute($a2->id)['assertion']);

        $this->setAdminUser();
        $this->assertNotSame('', get_launch_assertion::execute($a2->id)['assertion']);
    }

    /**
     * With the switch off the same student gets an assertion: today's behaviour.
     */
    public function test_switch_off_changes_nothing(): void {
        [$a1, $a2] = $this->two_levels();
        set_config('levelgate', 0, 'format_rumbos');
        $this->setUser($this->student($a1, $a2));

        $this->assertNotSame('', get_launch_assertion::execute($a2->id)['assertion']);
    }
}
