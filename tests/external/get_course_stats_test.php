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

use core\context\course as context_course;
use core_external\external_api;

/**
 * Tests for the completion statistics external function: access, groups and the shape of the answer.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\external\get_course_stats::class)]
final class get_course_stats_test extends \advanced_testcase {
    /**
     * A course with completion, six students in two groups, and a completion for every other student.
     *
     * @return array [course, cmid, groupa, groupb]
     */
    private function course(): array {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['format' => 'rumbos', 'enablecompletion' => 1, 'groupmode' => SEPARATEGROUPS]);
        $page = $gen->create_module('page', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $groupa = $gen->create_group(['courseid' => $course->id, 'name' => 'A']);
        $groupb = $gen->create_group(['courseid' => $course->id, 'name' => 'B']);
        $cm = get_fast_modinfo($course)->get_cm($page->cmid);
        for ($i = 0; $i < 6; $i++) {
            $student = $gen->create_and_enrol($course, 'student');
            $gen->create_group_member(['groupid' => ($i < 3 ? $groupa : $groupb)->id, 'userid' => $student->id]);
            if ($i % 2 === 0) {
                (new \completion_info($course))->update_state($cm, COMPLETION_COMPLETE, $student->id);
            }
        }
        set_config('mincohort', 2, 'format_rumbos');
        \cache_helper::purge_by_definition('format_rumbos', 'coursestats');
        return [$course, (int) $page->cmid, $groupa, $groupb];
    }

    /**
     * Runs the function the way the AJAX layer does: validated, then cleaned against the declared structure.
     *
     * @param int $courseid
     * @param int $groupid
     * @return array
     */
    private function call(int $courseid, int $groupid = 0): array {
        $result = get_course_stats::execute($courseid, $groupid);
        return external_api::clean_returnvalue(get_course_stats::execute_returns(), $result);
    }

    /**
     * There is no user id parameter: the viewer is the caller.
     */
    public function test_parameters(): void {
        $this->assertSame(['courseid', 'groupid', 'refresh'], array_keys(get_course_stats::execute_parameters()->keys));
    }

    /**
     * An editing teacher gets the counts, in the shape the contract declares.
     */
    public function test_teacher_gets_the_counts(): void {
        $this->resetAfterTest();
        [$course, $cmid, $groupa] = $this->course();
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'editingteacher'));

        $all = $this->call($course->id);
        $this->assertSame(6, $all['tracked']);
        $this->assertSame([['cmid' => $cmid, 'completionTracked' => true, 'complete' => 3, 'failed' => 0]], $all['activities']);

        $ina = $this->call($course->id, (int) $groupa->id);
        $this->assertSame(3, $ina['tracked']);
        $this->assertSame(2, $ina['activities'][0]['complete']);
    }

    /**
     * Students are refused.
     */
    public function test_student_is_refused(): void {
        $this->resetAfterTest();
        [$course] = $this->course();
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'student'));

        $this->expectException(\required_capability_exception::class);
        $this->call($course->id);
    }

    /**
     * An editing teacher who was denied the capability is refused too.
     */
    public function test_prohibited_teacher_is_refused(): void {
        $this->resetAfterTest();
        [$course] = $this->course();
        $context = context_course::instance($course->id);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('format/rumbos:viewstats', CAP_PROHIBIT, $roleid, $context->id, true);
        role_assign($roleid, $teacher->id, $context->id);
        $this->setUser($teacher);

        $this->expectException(\required_capability_exception::class);
        $this->call($course->id);
    }

    /**
     * A teacher held to their own group gets only it.
     */
    public function test_restricted_teacher_gets_only_their_group(): void {
        $this->resetAfterTest();
        [$course, , $groupa] = $this->course();
        $this->setUser($this->restricted_teacher($course, $groupa));

        $result = $this->call($course->id);

        $this->assertSame([['id' => (int) $groupa->id, 'name' => 'A']], $result['groups']);
        $this->assertSame(3, $result['tracked']);
    }

    /**
     * A teacher held to their own group is refused the other one.
     */
    public function test_restricted_teacher_is_refused_another_group(): void {
        $this->resetAfterTest();
        [$course, , $groupa, $groupb] = $this->course();
        $this->setUser($this->restricted_teacher($course, $groupa));

        $this->expectException(\invalid_parameter_exception::class);
        $this->call($course->id, (int) $groupb->id);
    }

    /**
     * A non-editing teacher (no access to all groups) who may view the statistics and belongs to one group.
     *
     * @param \stdClass $course
     * @param \stdClass $group
     * @return \stdClass The user.
     */
    private function restricted_teacher(\stdClass $course, \stdClass $group): \stdClass {
        $context = context_course::instance($course->id);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'teacher');
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('format/rumbos:viewstats', CAP_ALLOW, $roleid, $context->id, true);
        role_assign($roleid, $teacher->id, $context->id);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $teacher->id]);
        return $teacher;
    }

    /**
     * A suppressed answer has no activities key at all.
     */
    public function test_suppressed_answer_has_no_activities(): void {
        $this->resetAfterTest();
        [$course] = $this->course();
        set_config('mincohort', 50, 'format_rumbos');
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'editingteacher'));

        $result = $this->call($course->id);

        $this->assertTrue($result['suppressed']);
        $this->assertSame(0, $result['tracked']);
        $this->assertArrayNotHasKey('activities', $result);
    }
}
