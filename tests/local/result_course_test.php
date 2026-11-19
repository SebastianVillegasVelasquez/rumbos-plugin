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

require_once(__DIR__ . '/../fixtures/result_testcase.php');

/**
 * Tests for setting a course up for results, and for undoing it.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\local\result_course::class)]
final class result_course_test extends \format_rumbos\result_testcase {
    /**
     * Runs the steps of a plan and returns their keys.
     *
     * @param array[] $steps
     * @return string[]
     */
    private function run_steps(array $steps): array {
        foreach ($steps as $step) {
            $step['run']();
        }
        return array_column($steps, 'key');
    }

    /**
     * A bare course needs the role, the grade item and the criterion; the overall aggregation only when asked.
     */
    public function test_the_plan_for_a_bare_course(): void {
        $course = $this->make_course(false);

        $keys = array_column(result_course::plan((int) $course->id, '80', 'gt', false), 'key');
        $this->assertSame(['createrole', 'gradeitem', 'criterion'], $keys);

        $keys = array_column(result_course::plan((int) $course->id, '80', 'gt', true), 'key');
        $this->assertSame(['createrole', 'gradeitem', 'criterion', 'aggregation'], $keys);
    }

    /**
     * A dry run changes nothing: planning twice gives the same plan.
     */
    public function test_planning_changes_nothing(): void {
        $course = $this->make_course(false);

        $first = array_column(result_course::plan((int) $course->id, '80', 'gt', true), 'key');
        $second = array_column(result_course::plan((int) $course->id, '80', 'gt', true), 'key');

        $this->assertSame($first, $second);
        $this->assertNull(result_course::grade_item((int) $course->id));
        $this->assertNull(result_course::role_id());
        $this->assertNull(result_course::criterion((int) $course->id));
    }

    /**
     * Applying leaves the desired state, and applying again changes nothing.
     */
    public function test_applying_is_idempotent(): void {
        $course = $this->make_course(false);
        $this->run_steps(result_course::plan((int) $course->id, '80', 'gt', true));

        $this->assertSame([], result_course::plan((int) $course->id, '80', 'gt', true));
        $this->assertNull(result_course::not_ready_reason($course));

        $info = new \completion_info($course);
        $this->assertSame(COMPLETION_AGGREGATION_ANY, (int) $info->get_aggregation_method());
        $this->assertCount(1, $info->get_criteria(COMPLETION_CRITERIA_TYPE_ROLE));
        $this->assertCount(1, \grade_item::fetch_all(['courseid' => $course->id, 'idnumber' => result_course::GRADE_IDNUMBER]));
    }

    /**
     * Course completion is switched on in a course that has it off.
     */
    public function test_completion_is_switched_on(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['format' => 'rumbos', 'enablecompletion' => 0]);

        $keys = array_column(result_course::plan((int) $course->id, '80', 'gt', false), 'key');
        $this->assertSame('enablecompletion', $keys[0]);

        $this->run_steps(result_course::plan((int) $course->id, '80', 'gt', false));
        $this->assertSame(1, (int) $DB->get_field('course', 'enablecompletion', ['id' => $course->id]));
    }

    /**
     * Site wide completion off is a site setting: the command stops and says so.
     */
    public function test_site_completion_off_stops_the_plan(): void {
        global $CFG;

        $course = $this->make_course(false);
        $CFG->enablecompletion = 0;

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('errorsitecompletion', 'format_rumbos'));
        result_course::plan((int) $course->id, '80', 'gt', false);
    }

    /**
     * The grade to pass mirrors the rule: a hundredth above for "gt", the value itself for "gte".
     */
    public function test_the_pass_mark_mirror(): void {
        $this->assertEqualsWithDelta(80.01, result_course::mirror_pass_mark('80', 'gt'), 0.000001);
        $this->assertEqualsWithDelta(80.0, result_course::mirror_pass_mark('80', 'gte'), 0.000001);
        $this->assertEqualsWithDelta(75.51, result_course::mirror_pass_mark('75.5', 'gt'), 0.000001);
        $this->assertEqualsWithDelta(100.01, result_course::mirror_pass_mark('100', 'gt'), 0.000001);
        $this->assertEqualsWithDelta(0.01, result_course::mirror_pass_mark('0', 'gt'), 0.000001);
    }

    /**
     * The mirror agrees with the rule at the edge Moodle compares with greater-or-equal, and follows a changed rule.
     */
    public function test_the_mirror_in_the_gradebook_and_a_changed_rule(): void {
        $course = $this->make_course(false);
        $student = $this->make_user($course);
        $this->run_steps(result_course::plan((int) $course->id, '80', 'gt', false));
        $item = result_course::grade_item((int) $course->id);

        $item->update_final_grade($student->id, 80.00, 'test');
        $this->assertFalse(\grade_grade::fetch(['itemid' => $item->id, 'userid' => $student->id])->is_passed($item));
        $item->update_final_grade($student->id, 80.01, 'test');
        $this->assertTrue(\grade_grade::fetch(['itemid' => $item->id, 'userid' => $student->id])->is_passed($item));

        // The rule becomes "at least 80": the mirror follows when the command is run again.
        $keys = $this->run_steps(result_course::plan((int) $course->id, '80', 'gte', false));
        $this->assertSame(['gradepass'], $keys);
        $item = result_course::grade_item((int) $course->id);
        $this->assertEqualsWithDelta(80.0, (float) $item->gradepass, 0.000001);
    }

    /**
     * The role holds one capability, is assigned to nobody, and is one the completion form lists, so saving the form
     * does not drop the criterion.
     */
    public function test_the_role_is_inert_and_survives_the_completion_form(): void {
        global $DB;

        $course = $this->make_course();
        $roleid = result_course::role_id();

        $this->assertSame(0, $DB->count_records('role_assignments', ['roleid' => $roleid]));
        $capabilities = $DB->get_records('role_capabilities', ['roleid' => $roleid, 'permission' => CAP_ALLOW]);
        $this->assertSame(['moodle/course:markcomplete'], array_values(array_map(fn($c) => $c->capability, $capabilities)));
        $listedbytheform = array_keys(get_roles_with_capability(
            'moodle/course:markcomplete',
            CAP_ALLOW,
            \core\context\course::instance($course->id)
        ));
        $this->assertContains($roleid, $listedbytheform);
        // No student can mark anything: they hold no such role and no such capability.
        $student = $this->make_user($course);
        $this->assertFalse(has_capability('moodle/course:markcomplete', \core\context\course::instance($course->id), $student->id));
    }

    /**
     * A student cannot reach the criterion through Moodle's own self completion: there is no such criterion.
     */
    public function test_there_is_no_self_completion_criterion(): void {
        $course = $this->make_course();

        $info = new \completion_info($course);
        $this->assertSame([], $info->get_criteria(COMPLETION_CRITERIA_TYPE_SELF));
    }

    /**
     * Undoing removes the criterion and an empty grade item, keeps the role and every completion that happened.
     */
    public function test_reverting(): void {
        global $DB;

        $course = $this->make_course();
        $student = $this->make_user($course);
        $this->send($this->claims($course, $student, ['percent' => '90.00']));
        $this->assertTrue($this->is_complete($course, $student));

        // With a grade in the item it stays, unless told to drop it.
        $keys = $this->run_steps(result_course::plan_revert((int) $course->id, false));
        $this->assertSame(['removecriterion'], $keys);
        $this->assertNull(result_course::criterion((int) $course->id));
        $this->assertNotNull(result_course::grade_item((int) $course->id));
        $this->assertTrue($this->is_complete($course, $student), 'a completion that happened stays');
        $this->assertNotNull(result_course::role_id());

        $keys = $this->run_steps(result_course::plan_revert((int) $course->id, true));
        $this->assertSame(['removegradeitem'], $keys);
        $this->assertNull(result_course::grade_item((int) $course->id));
        $this->assertSame(1, $DB->count_records('course_completions', ['course' => $course->id]));

        // Nothing left to undo.
        $this->assertSame([], result_course::plan_revert((int) $course->id, true));
    }

    /**
     * An empty grade item goes without dropping grades.
     */
    public function test_reverting_an_empty_item(): void {
        $course = $this->make_course();

        $keys = $this->run_steps(result_course::plan_revert((int) $course->id, false));

        $this->assertSame(['removecriterion', 'removegradeitem'], $keys);
    }

    /**
     * The report an administrator reads: what exists, read only.
     */
    public function test_inspect(): void {
        $course = $this->make_course();
        $this->make_user($course);

        $state = result_course::inspect((int) $course->id);

        $this->assertSame('rumbos', $state['course']['format']);
        $this->assertTrue($state['coursecompletion']);
        $this->assertTrue($state['hasresultitem']);
        $this->assertTrue($state['hascriterion']);
        $this->assertSame('any', $state['aggregation']['overall']);
        $this->assertSame(1, $state['tracked']);
        $this->assertSame(0, $state['completed']);
        $this->assertTrue($state['role']['exists']);
        $this->assertTrue($state['role']['markcomplete']);
        $this->assertSame(0, $state['role']['assignments']);
        $this->assertContains('role', array_column($state['criteria'], 'type'));
    }
}
