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
 * Tests for the CourseState computation.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\local\course_state::class)]
final class course_state_test extends \advanced_testcase {
    /**
     * Creates a Rumbos course with a student and an editing teacher.
     *
     * @return array [course, student, teacher]
     */
    private function setup_course(): array {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['format' => 'rumbos', 'enablecompletion' => 1, 'numsections' => 2]);
        $student = $gen->create_user();
        $teacher = $gen->create_user();
        $gen->enrol_user($student->id, $course->id, 'student');
        $gen->enrol_user($teacher->id, $course->id, 'editingteacher');
        return [$course, $student, $teacher];
    }

    /**
     * Finds an activity in a state by cmid.
     *
     * @param array $state
     * @param int $cmid
     * @return array|null
     */
    private function activity(array $state, int $cmid): ?array {
        foreach ($state['activities'] as $activity) {
            if ($activity['cmid'] === $cmid) {
                return $activity;
            }
        }
        return null;
    }

    /**
     * The envelope matches the contract.
     */
    public function test_envelope(): void {
        $this->resetAfterTest();
        [$course, $student] = $this->setup_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Visible page']);

        $state = course_state::for_user($course->id, $student->id);

        $this->assertSame(1, $state['schemaVersion']);
        $this->assertSame((int) $course->id, $state['courseId']);
        $this->assertEqualsWithDelta(time(), $state['generatedAt'], 5);
        $this->assertEqualsCanonicalizing(
            ['schemaVersion', 'courseId', 'generatedAt', 'sections', 'activities', 'unavailableCmids'],
            array_keys($state)
        );
        $this->assertEqualsCanonicalizing(['id', 'number', 'name'], array_keys($state['sections'][0]));
        $activity = $this->activity($state, (int) $page->cmid);
        $this->assertEqualsCanonicalizing(
            ['cmid', 'name', 'modname', 'sectionId', 'url', 'availability', 'availabilityInfo', 'completion'],
            array_keys($activity)
        );
        $this->assertSame('Visible page', $activity['name']);
        $this->assertSame('page', $activity['modname']);
        $this->assertSame('available', $activity['availability']);
        $this->assertNull($activity['availabilityInfo']);
        $this->assertSame('none', $activity['completion']);
        $this->assertStringContainsString('/mod/page/view.php?id=' . $page->cmid, $activity['url']);
    }

    /**
     * A student never sees hidden activities, nor their names; only the id is listed.
     */
    public function test_student_does_not_see_hidden_activities(): void {
        $this->resetAfterTest();
        [$course, $student] = $this->setup_course();
        $hidden = $this->getDataGenerator()->create_module(
            'page',
            ['course' => $course->id, 'name' => 'Secret exam answers', 'visible' => 0]
        );

        $state = course_state::for_user($course->id, $student->id);

        $this->assertNull($this->activity($state, (int) $hidden->cmid));
        $this->assertSame([(int) $hidden->cmid], $state['unavailableCmids']);
        $this->assertStringNotContainsString('Secret exam answers', json_encode($state));
    }

    /**
     * A teacher sees hidden activities, flagged as hidden.
     */
    public function test_teacher_sees_hidden_activities_as_hidden(): void {
        $this->resetAfterTest();
        [$course, , $teacher] = $this->setup_course();
        $hidden = $this->getDataGenerator()->create_module(
            'page',
            ['course' => $course->id, 'name' => 'Draft page', 'visible' => 0]
        );

        $state = course_state::for_user($course->id, $teacher->id);

        $activity = $this->activity($state, (int) $hidden->cmid);
        $this->assertSame('hidden', $activity['availability']);
        $this->assertSame('Draft page', $activity['name']);
        $this->assertNotEmpty($activity['url']);
        $this->assertSame([], $state['unavailableCmids']);
    }

    /**
     * Restricted-but-shown activities expose plain-text info and no URL.
     */
    public function test_restricted_activity_exposes_plain_text_info_only(): void {
        $this->resetAfterTest();
        [$course, $student] = $this->setup_course();
        $future = strtotime('+1 year');
        $restricted = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'name' => 'Later page',
            'availability' => json_encode([
                'op' => '&',
                'c' => [['type' => 'date', 'd' => '>=', 't' => $future]],
                'showc' => [true],
            ]),
        ]);

        $state = course_state::for_user($course->id, $student->id);

        $activity = $this->activity($state, (int) $restricted->cmid);
        $this->assertSame('restricted', $activity['availability']);
        $this->assertNull($activity['url']);
        $this->assertNotSame('', $activity['availabilityInfo']);
        $this->assertSame(strip_tags($activity['availabilityInfo']), $activity['availabilityInfo']);
        $this->assertStringNotContainsString('<', $activity['availabilityInfo']);
        $this->assertSame([], $state['unavailableCmids']);
    }

    /**
     * Restricted activities that Moodle hides entirely appear only as ids.
     */
    public function test_restricted_and_hidden_by_rule_is_only_an_id(): void {
        $this->resetAfterTest();
        [$course, $student] = $this->setup_course();
        $restricted = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'name' => 'Invisible until later',
            'availability' => json_encode([
                'op' => '&',
                'c' => [['type' => 'date', 'd' => '>=', 't' => strtotime('+1 year')]],
                'showc' => [false],
            ]),
        ]);

        $state = course_state::for_user($course->id, $student->id);

        $this->assertNull($this->activity($state, (int) $restricted->cmid));
        $this->assertSame([(int) $restricted->cmid], $state['unavailableCmids']);
        $this->assertStringNotContainsString('Invisible until later', json_encode($state));
        $this->assertContainsOnly('int', $state['unavailableCmids']);
    }

    /**
     * Moodle completion states map onto the contract vocabulary.
     */
    public function test_completion_states_map_correctly(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $student] = $this->setup_course();
        $expected = [
            'incomplete' => null,
            'complete' => COMPLETION_COMPLETE,
            'complete-pass' => COMPLETION_COMPLETE_PASS,
            'complete-fail' => COMPLETION_COMPLETE_FAIL,
        ];
        $cmids = [];
        foreach (array_keys($expected) as $key) {
            $page = $this->getDataGenerator()->create_module('page', [
                'course' => $course->id,
                'name' => $key,
                'completion' => COMPLETION_TRACKING_MANUAL,
            ]);
            $cmids[$key] = (int) $page->cmid;
        }
        $completioninfo = new \completion_info(get_course($course->id));
        foreach ($expected as $key => $completionstate) {
            if ($completionstate === null) {
                continue;
            }
            $cm = get_coursemodule_from_id('page', $cmids[$key], $course->id, false, MUST_EXIST);
            $completioninfo->internal_set_data($cm, (object) [
                'id' => 0,
                'coursemoduleid' => $cm->id,
                'userid' => $student->id,
                'completionstate' => $completionstate,
                'viewed' => 0,
                'overrideby' => null,
                'timemodified' => time(),
            ]);
        }
        \cache::make('core', 'completion')->purge();
        rebuild_course_cache($course->id, true);

        $state = course_state::for_user($course->id, $student->id);

        $this->assertSame('incomplete', $this->activity($state, $cmids['incomplete'])['completion']);
        $this->assertSame('complete', $this->activity($state, $cmids['complete'])['completion']);
        $this->assertSame('complete', $this->activity($state, $cmids['complete-pass'])['completion']);
        $this->assertSame('failed', $this->activity($state, $cmids['complete-fail'])['completion']);
    }

    /**
     * Labels are excluded, and not even listed as unavailable.
     */
    public function test_labels_are_excluded(): void {
        $this->resetAfterTest();
        [$course, $student] = $this->setup_course();
        $label = $this->getDataGenerator()->create_module('label', ['course' => $course->id, 'name' => 'A label']);
        $hiddenlabel = $this->getDataGenerator()->create_module(
            'label',
            ['course' => $course->id, 'name' => 'Hidden label', 'visible' => 0]
        );

        $state = course_state::for_user($course->id, $student->id);

        $this->assertNull($this->activity($state, (int) $label->cmid));
        $this->assertSame([], $state['activities']);
        $this->assertNotContains((int) $hiddenlabel->cmid, $state['unavailableCmids']);
    }

    /**
     * Sections the user cannot see are not listed.
     */
    public function test_hidden_sections_are_not_listed_for_students(): void {
        $this->resetAfterTest();
        [$course, $student, $teacher] = $this->setup_course();
        $section = $this->getDataGenerator()->create_course_section(['course' => $course->id, 'section' => 1]);
        \core_courseformat\formatactions::section($course->id)
            ->set_visibility(get_fast_modinfo($course->id)->get_section_info(1), false);

        $studentstate = course_state::for_user($course->id, $student->id);
        $teacherstate = course_state::for_user($course->id, $teacher->id);

        $this->assertNotContains(1, array_column($studentstate['sections'], 'number'));
        $this->assertContains(1, array_column($teacherstate['sections'], 'number'));
        $this->assertNotEmpty($section);
    }

    /**
     * Names are reduced to plain text.
     */
    public function test_section_names_are_plain_text(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $student] = $this->setup_course();
        $DB->set_field('course_sections', 'name', 'Intro <b>one</b>  &amp; two', ['course' => $course->id, 'section' => 1]);
        rebuild_course_cache($course->id, true);

        $state = course_state::for_user($course->id, $student->id);

        $names = array_column($state['sections'], 'name', 'number');
        $this->assertSame('Intro one & two', $names[1]);
    }
}
