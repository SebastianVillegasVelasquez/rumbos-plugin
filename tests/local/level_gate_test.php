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

use format_rumbos\level_fixture;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/level_fixture.php');

/**
 * Tests for the level gate: who may open the Rumbos content of a level.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\local\level_gate::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\local\level_decision::class)]
final class level_gate_test extends \advanced_testcase {
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
     * With the switch off nothing changes, whatever the chain says.
     */
    public function test_switch_off_is_todays_behaviour(): void {
        [$a1, $a2] = $this->two_levels();
        set_config('levelgate', 0, 'format_rumbos');
        $user = $this->student($a1, $a2);

        $decision = level_gate::check($a2->id, $user->id);

        $this->assertSame(level_decision::OFF, $decision->status);
        $this->assertTrue($decision->is_open());
    }

    /**
     * The switch is off by default on a fresh install.
     */
    public function test_the_switch_defaults_to_off(): void {
        $this->resetAfterTest();
        $this->assertFalse(config::is_level_gate_enabled());
        $this->assertSame([], config::get_level_chain());
    }

    /**
     * The first level and a course that is not in the chain have no prerequisite.
     */
    public function test_no_predecessor(): void {
        [$a1] = $this->two_levels();
        $outside = $this->level('7777');
        $user = $this->student($a1, $outside);

        $this->assertSame(level_decision::NOT_GATED, level_gate::check($a1->id, $user->id)->status);
        $this->assertSame(level_decision::NOT_GATED, level_gate::check($outside->id, $user->id)->status);
    }

    /**
     * A student who has not completed the previous level is locked, and the previous level is named.
     */
    public function test_not_met(): void {
        [$a1, $a2] = $this->two_levels();
        $user = $this->student($a1, $a2);

        $decision = level_gate::check($a2->id, $user->id);

        $this->assertSame(level_decision::LOCKED, $decision->status);
        $this->assertSame(level_decision::REASON_INCOMPLETE, $decision->reason);
        $this->assertSame((int) $a1->id, (int) $decision->prerequisite->id);
        $this->assertFalse($decision->is_open());
    }

    /**
     * A student who completed the previous level may open the next one.
     */
    public function test_met(): void {
        [$a1, $a2] = $this->two_levels();
        $user = $this->student($a1, $a2);
        $this->complete($a1, $user);

        $decision = level_gate::check($a2->id, $user->id);

        $this->assertSame(level_decision::MET, $decision->status);
        $this->assertTrue($decision->is_open());
    }

    /**
     * Completing level N-1 opens level N only, not N+1.
     */
    public function test_a_longer_chain_opens_one_level_at_a_time(): void {
        $this->start_levels();
        $a1 = $this->level('1000');
        $a2 = $this->level('1001');
        $b1 = $this->level('1003');
        $this->gate_on(['1000', '1001', '1003']);
        $user = $this->student($a1, $a2, $b1);
        $this->complete($a1, $user);

        $this->assertTrue(level_gate::check($a2->id, $user->id)->is_open());
        $this->assertFalse(level_gate::check($b1->id, $user->id)->is_open());
    }

    /**
     * Someone enrolled in a level only (for example B2) is locked, and the reason says they are not enrolled in the previous one.
     */
    public function test_unenrolled_in_the_predecessor(): void {
        [, $a2] = $this->two_levels();
        $user = $this->student($a2);

        $decision = level_gate::check($a2->id, $user->id);

        $this->assertSame(level_decision::LOCKED, $decision->status);
        $this->assertSame(level_decision::REASON_NOT_ENROLLED, $decision->reason);
    }

    /**
     * A completion recorded earlier counts even when the student is no longer enrolled in the previous level.
     */
    public function test_completion_survives_unenrolment(): void {
        global $DB;
        [$a1, $a2] = $this->two_levels();
        $user = $this->student($a1, $a2);
        $this->complete($a1, $user);
        $instance = $DB->get_record('enrol', ['courseid' => $a1->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        enrol_get_plugin('manual')->unenrol_user($instance, $user->id);

        $this->assertSame(level_decision::MET, level_gate::check($a2->id, $user->id)->status);
    }

    /**
     * A completion an administrator marked by hand counts like any other: Moodle is the source of truth.
     */
    public function test_manual_completion(): void {
        [$a1, $a2] = $this->two_levels();
        $user = $this->student($a1, $a2);
        $this->setAdminUser();
        $this->complete($a1, $user);

        $this->assertSame(level_decision::MET, level_gate::check($a2->id, $user->id)->status);
    }

    /**
     * Editors and administrators are never blocked, enrolled or not.
     */
    public function test_staff_are_never_blocked(): void {
        [$a1, $a2] = $this->two_levels();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $a2->id, 'editingteacher');
        $admin = get_admin();

        $this->assertSame(level_decision::BYPASS, level_gate::check($a2->id, $teacher->id)->status);
        $this->assertSame(level_decision::BYPASS, level_gate::check($a2->id, $admin->id)->status);
        $this->assertTrue(level_gate::check($a1->id, $teacher->id)->is_open());
    }

    /**
     * Staff are not blocked even when the check could not run for students.
     */
    public function test_staff_are_not_affected_by_a_broken_prerequisite(): void {
        $this->start_levels();
        $a2 = $this->level('1001');
        $this->gate_on(['1000', '1001']);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $a2->id, 'editingteacher');

        $this->assertSame(level_decision::BYPASS, level_gate::check($a2->id, $teacher->id)->status);
    }

    /**
     * A recorded exception (the capability, in one course) opens that level for that student only.
     */
    public function test_override(): void {
        [, $a2] = $this->two_levels();
        $b1 = $this->level('1003');
        $this->gate_on(['1000', '1001', '1003']);
        $excepted = $this->student($a2, $b1);
        $other = $this->student($a2);
        $roleid = create_role('Level exception', 'levelexception', '');
        set_role_contextlevels($roleid, [CONTEXT_COURSE]);
        assign_capability(level_gate::SKIP_CAPABILITY, CAP_ALLOW, $roleid, \core\context\system::instance()->id);
        role_assign($roleid, $excepted->id, \core\context\course::instance($a2->id)->id);

        $this->assertSame(level_decision::OVERRIDE, level_gate::check($a2->id, $excepted->id)->status);
        $this->assertSame(level_decision::LOCKED, level_gate::check($a2->id, $other->id)->status);
        // The exception is for that level: the next one still needs A2 completed.
        $this->assertSame(level_decision::LOCKED, level_gate::check($b1->id, $excepted->id)->status);
    }

    /**
     * Nobody holds the exception by default.
     */
    public function test_no_default_role_holds_the_exception(): void {
        global $DB;
        $this->resetAfterTest();

        $this->assertSame(0, $DB->count_records('role_capabilities', ['capability' => level_gate::SKIP_CAPABILITY]));
    }

    /**
     * A previous level that does not exist closes the content and logs why.
     */
    public function test_missing_course_fails_closed(): void {
        $this->start_levels();
        $a2 = $this->level('1001');
        $this->gate_on(['1000', '1001']);
        $user = $this->student($a2);

        $decision = level_gate::check($a2->id, $user->id);

        $this->assertSame(level_decision::ERROR, $decision->status);
        $this->assertFalse($decision->is_open());
        $this->assertStringContainsString("no course has the level ID number '1000'", $this->error_log_text());
    }

    /**
     * A previous level that two courses claim closes the content too.
     */
    public function test_ambiguous_course_fails_closed(): void {
        global $DB;
        [, $a2] = $this->two_levels();
        $twin = $this->level('1999');
        $DB->set_field('course', 'idnumber', '1000', ['id' => $twin->id]);
        $user = $this->student($a2);

        $decision = level_gate::check($a2->id, $user->id);

        $this->assertSame(level_decision::ERROR, $decision->status);
        $this->assertStringContainsString('several courses share', $this->error_log_text());
    }

    /**
     * Completion tracking switched off in the previous level closes the content.
     */
    public function test_completion_disabled_fails_closed(): void {
        global $DB;
        [$a1, $a2] = $this->two_levels();
        $DB->set_field('course', 'enablecompletion', 0, ['id' => $a1->id]);
        $user = $this->student($a1, $a2);

        $decision = level_gate::check($a2->id, $user->id);

        $this->assertSame(level_decision::ERROR, $decision->status);
        $this->assertStringContainsString('completion tracking is off', $this->error_log_text());
    }

    /**
     * A previous level without completion criteria can never be completed: it closes the content instead of locking it for ever.
     */
    public function test_no_criteria_fails_closed(): void {
        $this->start_levels();
        $a1 = $this->level('1000', false);
        $a2 = $this->level('1001');
        $this->gate_on(['1000', '1001']);
        $user = $this->student($a1, $a2);

        $decision = level_gate::check($a2->id, $user->id);

        $this->assertSame(level_decision::ERROR, $decision->status);
        $this->assertFalse($decision->is_open());
        $this->assertStringContainsString('no course completion criteria', $this->error_log_text());
    }

    /**
     * An exception while checking closes the content and logs it.
     */
    public function test_an_exception_fails_closed(): void {
        $this->start_levels();
        $this->gate_on(['1000', '1001']);
        $user = $this->student();

        $decision = level_gate::check(987654, $user->id);

        $this->assertSame(level_decision::ERROR, $decision->status);
        $this->assertStringContainsString('format_rumbos level gate failed closed', $this->error_log_text());
    }

    /**
     * The dry run can ask the gate with the switch off, and a quiet call does not write to the log.
     */
    public function test_decide_ignores_the_switch_and_can_stay_quiet(): void {
        [$a1, $a2] = $this->two_levels();
        set_config('levelgate', 0, 'format_rumbos');
        $user = $this->student($a1, $a2);

        $this->assertSame(level_decision::LOCKED, level_gate::decide($a2->id, $user->id, ['1000', '1001'])->status);

        $quiet = level_gate::decide($a2->id, $user->id, ['0000', '1001'], false);
        $this->assertSame(level_decision::ERROR, $quiet->status);
        $this->assertSame('', $this->error_log_text());
    }
}
