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
 * Tests for the completion statistics: who is counted, groups, the cohort threshold, the cache and privacy.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\local\course_stats::class)]
final class course_stats_test extends \advanced_testcase {
    /**
     * Starts every test with the default minimum and an empty cache.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        unset_config('mincohort', 'format_rumbos');
        \cache_helper::purge_by_definition('format_rumbos', 'coursestats');
    }

    /**
     * A course with completion, two tracked activities and one without a condition.
     *
     * @param array $options Course options.
     * @return array [course, cmids (tracked, tracked, untracked)]
     */
    private function course(array $options = []): array {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course($options + ['format' => 'rumbos', 'enablecompletion' => 1]);
        $manual = ['completion' => COMPLETION_TRACKING_MANUAL];
        $first = $gen->create_module('page', ['course' => $course->id] + $manual);
        $second = $gen->create_module('page', ['course' => $course->id] + $manual);
        $plain = $gen->create_module('page', ['course' => $course->id]);
        return [$course, [(int) $first->cmid, (int) $second->cmid, (int) $plain->cmid]];
    }

    /**
     * Creates and enrols students.
     *
     * @param \stdClass $course
     * @param int $number
     * @return \stdClass[]
     */
    private function students(\stdClass $course, int $number): array {
        $students = [];
        for ($i = 0; $i < $number; $i++) {
            $student = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
            $students[] = $student;
        }
        return $students;
    }

    /**
     * Records a completion state straight in the table.
     *
     * @param int $cmid
     * @param \stdClass $user
     * @param int $state
     */
    private function complete(int $cmid, \stdClass $user, int $state): void {
        global $DB;

        $DB->insert_record('course_modules_completion', (object) [
            'coursemoduleid' => $cmid,
            'userid' => $user->id,
            'completionstate' => $state,
            'viewed' => 0,
            'timemodified' => time(),
        ]);
    }

    /**
     * Stats of a course for a user, by activity.
     *
     * @param array $result
     * @return array cmid => [tracked, complete, failed]
     */
    private function by_cmid(array $result): array {
        $byid = [];
        foreach ($result['activities'] as $activity) {
            $byid[$activity['cmid']] = [$activity['completionTracked'], $activity['complete'], $activity['failed']];
        }
        return $byid;
    }

    /**
     * Every state is counted where the contract says, and the rest counts as neither.
     */
    public function test_every_completion_state(): void {
        [$course, $cms] = $this->course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $students = $this->students($course, 8);
        $states = [
            COMPLETION_COMPLETE,
            COMPLETION_COMPLETE_PASS,
            COMPLETION_COMPLETE_PASS,
            COMPLETION_COMPLETE_FAIL,
            COMPLETION_COMPLETE_FAIL_HIDDEN,
            COMPLETION_INCOMPLETE,
        ];
        foreach ($states as $i => $state) {
            $this->complete($cms[0], $students[$i], $state);
        }
        $this->complete($cms[1], $students[0], COMPLETION_COMPLETE);

        $result = course_stats::for_user($course->id, $teacher->id);

        $this->assertFalse($result['suppressed']);
        $this->assertSame(8, $result['tracked']);
        $this->assertSame(
            [$cms[0] => [true, 3, 1], $cms[1] => [true, 1, 0], $cms[2] => [false, 0, 0]],
            $this->by_cmid($result)
        );
        $this->assertSame((int) $course->id, $result['courseId']);
        $this->assertSame(0, $result['groupId']);
        $this->assertSame(1, $result['schemaVersion']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $result['generatedAt']);
    }

    /**
     * Teachers, suspended and deleted users do not count, and neither do their completions.
     */
    public function test_only_active_tracked_students_count(): void {
        global $DB;

        [$course, $cms] = $this->course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $students = $this->students($course, 7);
        foreach ($students as $student) {
            $this->complete($cms[0], $student, COMPLETION_COMPLETE);
        }
        $this->complete($cms[0], $teacher, COMPLETION_COMPLETE);
        $this->getDataGenerator()->enrol_user($students[0]->id, $course->id, 'student', 'manual', 0, 0, ENROL_USER_SUSPENDED);
        $DB->set_field('user', 'deleted', 1, ['id' => $students[1]->id]);

        $result = course_stats::for_user($course->id, $teacher->id);

        $this->assertSame(5, $result['tracked']);
        $this->assertSame([true, 5, 0], $this->by_cmid($result)[$cms[0]]);
    }

    /**
     * A course with nobody to track is suppressed and has no counts.
     */
    public function test_course_without_tracked_users(): void {
        [$course] = $this->course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        $result = course_stats::for_user($course->id, $teacher->id);

        $this->assertTrue($result['suppressed']);
        $this->assertSame(0, $result['tracked']);
        $this->assertArrayNotHasKey('activities', $result);
        $this->assertSame([], $result['groups']);
    }

    /**
     * Below the minimum nothing but the fact is sent; at the minimum the counts appear.
     */
    public function test_cohort_threshold(): void {
        [$course, $cms] = $this->course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $students = $this->students($course, 4);
        $this->complete($cms[0], $students[0], COMPLETION_COMPLETE);

        $result = course_stats::for_user($course->id, $teacher->id);
        $this->assertTrue($result['suppressed']);
        $this->assertSame(5, $result['minCohort']);
        $this->assertSame(0, $result['tracked']);
        $this->assertArrayNotHasKey('activities', $result);

        set_config('mincohort', 4, 'format_rumbos');
        $result = course_stats::for_user($course->id, $teacher->id);
        $this->assertFalse($result['suppressed']);
        $this->assertSame(4, $result['tracked']);
        $this->assertSame([true, 1, 0], $this->by_cmid($result)[$cms[0]]);
    }

    /**
     * Course completion off: nothing is tracked, whatever the activities say.
     */
    public function test_completion_disabled_in_the_course(): void {
        global $DB;

        [$course, $cms] = $this->course();
        $DB->set_field('course', 'enablecompletion', 0, ['id' => $course->id]);
        rebuild_course_cache($course->id, true);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->students($course, 5);

        $result = course_stats::for_user($course->id, $teacher->id);

        $this->assertSame([$cms[0] => [false, 0, 0], $cms[1] => [false, 0, 0], $cms[2] => [false, 0, 0]], $this->by_cmid($result));
    }

    /**
     * A course with groups: three students in group A, two in B, one in neither, and a teacher who sees every group.
     *
     * @return array [course, cms, teacher, groupa, groupb]
     */
    private function grouped_course(): array {
        [$course, $cms] = $this->course(['groupmode' => SEPARATEGROUPS]);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $groupa = $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => 'Group A']);
        $groupb = $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => 'Group B']);
        $students = $this->students($course, 6);
        foreach ([0, 1, 2] as $i) {
            $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $students[$i]->id]);
        }
        foreach ([3, 4] as $i) {
            $this->getDataGenerator()->create_group_member(['groupid' => $groupb->id, 'userid' => $students[$i]->id]);
        }
        foreach ($students as $student) {
            $this->complete($cms[0], $student, COMPLETION_COMPLETE);
        }
        $this->complete($cms[1], $students[0], COMPLETION_COMPLETE_FAIL);
        return [$course, $cms, $teacher, $groupa, $groupb];
    }

    /**
     * A teacher with access to all groups sees them all and can look at each one.
     */
    public function test_teacher_with_all_groups_access(): void {
        set_config('mincohort', 2, 'format_rumbos');
        [$course, $cms, $teacher, $groupa, $groupb] = $this->grouped_course();

        $all = course_stats::for_user($course->id, $teacher->id);
        $this->assertSame(['Group A', 'Group B'], array_column($all['groups'], 'name'));
        $this->assertSame(6, $all['tracked']);
        $this->assertSame([true, 6, 0], $this->by_cmid($all)[$cms[0]]);

        $ina = course_stats::for_user($course->id, $teacher->id, (int) $groupa->id);
        $this->assertSame((int) $groupa->id, $ina['groupId']);
        $this->assertSame(3, $ina['tracked']);
        $this->assertSame([true, 0, 1], $this->by_cmid($ina)[$cms[1]]);

        $inb = course_stats::for_user($course->id, $teacher->id, (int) $groupb->id);
        $this->assertSame(2, $inb['tracked']);
        $this->assertSame([true, 0, 0], $this->by_cmid($inb)[$cms[1]]);
    }

    /**
     * A teacher without access to all groups gets only their own, and cannot ask for another.
     */
    public function test_teacher_restricted_to_their_group(): void {
        set_config('mincohort', 2, 'format_rumbos');
        [$course, $cms, , $groupa, $groupb] = $this->grouped_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'teacher');
        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $teacher->id]);

        $result = course_stats::for_user($course->id, $teacher->id);
        $this->assertSame([['id' => (int) $groupa->id, 'name' => 'Group A']], $result['groups']);
        $this->assertSame(3, $result['tracked'], 'All groups means all the groups the teacher is in');
        $this->assertSame([true, 3, 0], $this->by_cmid($result)[$cms[0]]);

        try {
            course_stats::for_user($course->id, $teacher->id, (int) $groupb->id);
            $this->fail('A group the teacher does not belong to must be refused');
        } catch (\invalid_parameter_exception $e) {
            $this->assertStringContainsString('groupid', $e->getMessage());
        }
        try {
            course_stats::for_user($course->id, $teacher->id, 987654);
            $this->fail('A group that does not exist must be refused');
        } catch (\invalid_parameter_exception $e) {
            $this->assertStringContainsString('groupid', $e->getMessage());
        }
    }

    /**
     * A restricted teacher who is in no group sees nobody: the answer fails closed.
     */
    public function test_restricted_teacher_without_groups_sees_nobody(): void {
        set_config('mincohort', 1, 'format_rumbos');
        [$course] = $this->grouped_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'teacher');

        $result = course_stats::for_user($course->id, $teacher->id);

        $this->assertSame([], $result['groups']);
        $this->assertSame(0, $result['tracked']);
        $this->assertTrue($result['suppressed']);
    }

    /**
     * Without group mode a teacher without access to all groups is not held back, as in the core reports.
     */
    public function test_group_restriction_applies_only_in_group_mode(): void {
        set_config('mincohort', 1, 'format_rumbos');
        [$course] = $this->course(['groupmode' => NOGROUPS]);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'teacher');
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => 'Only']);
        $this->students($course, 2);

        $result = course_stats::for_user($course->id, $teacher->id);

        $this->assertSame([['id' => (int) $group->id, 'name' => 'Only']], $result['groups']);
        $this->assertSame(2, $result['tracked']);
    }

    /**
     * A cached result is served until it is refreshed, and a refresh is limited to one every 30 seconds.
     */
    public function test_cache_and_refresh(): void {
        [$course, $cms] = $this->course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $students = $this->students($course, 5);
        $this->complete($cms[0], $students[0], COMPLETION_COMPLETE);

        $first = course_stats::for_user($course->id, $teacher->id);
        $this->assertSame([true, 1, 0], $this->by_cmid($first)[$cms[0]]);

        $this->complete($cms[0], $students[1], COMPLETION_COMPLETE);
        $cached = course_stats::for_user($course->id, $teacher->id);
        $this->assertSame([true, 1, 0], $this->by_cmid($cached)[$cms[0]], 'Served from the cache');

        $toosoon = course_stats::for_user($course->id, $teacher->id, 0, true);
        $this->assertSame([true, 1, 0], $this->by_cmid($toosoon)[$cms[0]], 'A refresh right after a computation is ignored');

        // Age the entry, as if it had been computed a minute ago.
        $cache = \cache::make('format_rumbos', 'coursestats');
        $key = $course->id . '_all_5';
        $entry = $cache->get($key);
        $this->assertIsArray($entry);
        $entry['computedat'] -= course_stats::MIN_REFRESH_INTERVAL + 1;
        $cache->set($key, $entry);

        $this->assertSame([true, 1, 0], $this->by_cmid(course_stats::for_user($course->id, $teacher->id))[$cms[0]]);
        $refreshed = course_stats::for_user($course->id, $teacher->id, 0, true);
        $this->assertSame([true, 2, 0], $this->by_cmid($refreshed)[$cms[0]], 'An old enough entry is recomputed on request');
    }

    /**
     * The result and the cache hold no user data: only the keys of the contract and no name, id or e-mail of anyone.
     */
    public function test_no_personal_data_in_the_result_or_the_cache(): void {
        set_config('mincohort', 1, 'format_rumbos');
        [$course, $cms, $teacher] = $this->grouped_course();

        $result = course_stats::for_user($course->id, $teacher->id);
        course_stats::for_user($course->id, $teacher->id, 0, true);

        $this->assertEqualsCanonicalizing(
            ['schemaVersion', 'generatedAt', 'courseId', 'groupId', 'groups', 'tracked', 'minCohort', 'suppressed', 'activities'],
            array_keys($result)
        );
        foreach ($result['activities'] as $activity) {
            $this->assertSame(['cmid', 'completionTracked', 'complete', 'failed'], array_keys($activity));
        }
        $dump = json_encode($result);
        $cached = \cache::make('format_rumbos', 'coursestats')->get($course->id . '_all_1');
        $this->assertIsArray($cached);
        $this->assertEqualsCanonicalizing(['computedat', 'tracked', 'counts'], array_keys($cached));
        $dump .= json_encode($cached);
        foreach (get_enrolled_users(\core\context\course::instance($course->id)) as $user) {
            foreach (['username', 'firstname', 'lastname', 'email'] as $field) {
                $this->assertStringNotContainsString((string) $user->$field, $dump, $field);
            }
        }
        $this->assertSame([true, 6, 0], $this->by_cmid($result)[$cms[0]]);
    }

    /**
     * The number of reads does not depend on the number of students or activities.
     */
    public function test_query_count_is_constant(): void {
        global $DB;

        set_config('mincohort', 1, 'format_rumbos');
        [$course, $cms] = $this->course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->students($course, 3);
        course_stats::for_user($course->id, $teacher->id);
        \cache_helper::purge_by_definition('format_rumbos', 'coursestats');
        $before = $DB->perf_get_reads();
        course_stats::for_user($course->id, $teacher->id);
        $small = $DB->perf_get_reads() - $before;

        foreach ($this->students($course, 40) as $student) {
            $this->complete($cms[0], $student, COMPLETION_COMPLETE);
        }
        \cache_helper::purge_by_definition('format_rumbos', 'coursestats');
        $before = $DB->perf_get_reads();
        course_stats::for_user($course->id, $teacher->id);
        $large = $DB->perf_get_reads() - $before;

        $this->assertSame($small, $large);
    }
}
