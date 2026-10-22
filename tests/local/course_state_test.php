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

use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

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
        $this->assertIsString($state['generatedAt']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $state['generatedAt']);
        $this->assertEqualsWithDelta(time(), strtotime($state['generatedAt']), 5);
        $this->assertEqualsCanonicalizing(
            ['schemaVersion', 'courseId', 'generatedAt', 'courseCompletionEnabled', 'sections', 'activities', 'unavailableCmids'],
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
     * Every field has exactly the JSON type the Rumbos app validates.
     *
     * The app discards a whole state, and with it the host-init message, if one
     * field has another type: a generatedAt sent as an integer instead of a
     * string made every launch time out. This pins the type of each field, for
     * activities that are available, restricted and hidden from the user.
     */
    public function test_every_field_has_the_type_the_app_validates(): void {
        $this->resetAfterTest();
        [$course, $student] = $this->setup_course();
        $generator = $this->getDataGenerator();
        $generator->create_module('page', ['course' => $course->id, 'name' => 'Open', 'completion' => COMPLETION_TRACKING_MANUAL]);
        $generator->create_module('page', [
            'course' => $course->id,
            'name' => 'Later',
            'availability' => json_encode([
                'op' => '&',
                'c' => [['type' => 'date', 'd' => '>=', 't' => strtotime('+1 year')]],
                'showc' => [true],
            ]),
        ]);
        $generator->create_module('page', ['course' => $course->id, 'name' => 'Secret', 'visible' => 0]);

        $state = course_state::for_user($course->id, $student->id);

        $this->assertSame(1, $state['schemaVersion']);
        $this->assertIsInt($state['courseId']);
        $this->assertGreaterThan(0, $state['courseId']);
        $this->assertIsString($state['generatedAt']);
        $this->assertLessThanOrEqual(64, strlen($state['generatedAt']));
        $this->assertIsBool($state['courseCompletionEnabled']);

        $this->assertNotEmpty($state['sections']);
        foreach ($state['sections'] as $section) {
            $this->assertIsInt($section['id']);
            $this->assertIsInt($section['number']);
            $this->assertIsString($section['name']);
        }

        $this->assertCount(2, $state['activities']);
        foreach ($state['activities'] as $activity) {
            $this->assertIsInt($activity['cmid']);
            $this->assertGreaterThan(0, $activity['cmid']);
            $this->assertIsString($activity['name']);
            $this->assertIsString($activity['modname']);
            $this->assertLessThanOrEqual(64, strlen($activity['modname']));
            $this->assertIsInt($activity['sectionId']);
            $this->assertTrue($activity['url'] === null || is_string($activity['url']));
            $this->assertContains($activity['availability'], ['available', 'restricted', 'hidden']);
            $this->assertTrue($activity['availabilityInfo'] === null || is_string($activity['availabilityInfo']));
            $this->assertContains($activity['completion'], ['none', 'incomplete', 'complete', 'failed']);
        }
        $this->assertSame(['available', 'restricted'], array_column($state['activities'], 'availability'));

        $this->assertCount(1, $state['unavailableCmids']);
        $this->assertContainsOnlyInt($state['unavailableCmids']);
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
     * Creates a quiz with one numerical question and a pass grade of 80 out of 100.
     *
     * @param \stdClass $course
     * @param array $settings Completion settings of the quiz.
     * @return \stdClass The quiz instance record.
     */
    private function create_quiz(\stdClass $course, array $settings): \stdClass {
        $quiz = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance(array_merge([
            'course' => $course->id,
            'grade' => 100.0,
            'sumgrades' => 1,
            'questionsperpage' => 0,
        ], $settings));
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category();
        $question = $questiongenerator->create_question('numerical', null, ['category' => $category->id]);
        quiz_add_quiz_question($question->id, $quiz);
        $item = \grade_item::fetch([
            'courseid' => $course->id,
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => $quiz->id,
            'outcomeid' => null,
        ]);
        $item->gradepass = 80;
        $item->update();
        return $quiz;
    }

    /**
     * Starts and finishes a quiz attempt of a user, answering the only (numerical) question.
     *
     * @param \stdClass $quiz The quiz instance record.
     * @param int $userid
     * @param int $attemptnumber
     * @param string $answer "3.14" is right, "0" is wrong.
     */
    private function finish_quiz_attempt(\stdClass $quiz, int $userid, int $attemptnumber, string $answer): void {
        $quizobj = quiz_settings::create((int) $quiz->id, $userid);
        $quba = \question_engine::make_questions_usage_by_activity('mod_quiz', $quizobj->get_context());
        $quba->set_preferred_behaviour($quizobj->get_quiz()->preferredbehaviour);
        // Each attempt a little later than the one before, as real attempts are: Moodle orders them by time.
        $now = time() - 3600 + 600 * $attemptnumber;
        $attempt = quiz_create_attempt($quizobj, $attemptnumber, false, $now, false, $userid);
        quiz_start_new_attempt($quizobj, $quba, $attempt, $attemptnumber, $now);
        quiz_attempt_save_started($quizobj, $quba, $attempt);

        $attemptobj = quiz_attempt::create($attempt->id);
        $attemptobj->process_submitted_actions($now, false, [1 => ['answer' => $answer]]);
        $attemptobj = quiz_attempt::create($attempt->id);
        $attemptobj->process_submit($now, false);
        $attemptobj->process_grade_submission($now);
    }

    /**
     * The completion of every activity of a course for a user, by cmid.
     *
     * @param \stdClass $course
     * @param \stdClass $user
     * @return string[]
     */
    private function completions(\stdClass $course, \stdClass $user): array {
        return array_column(course_state::for_user($course->id, $user->id)['activities'], 'completion', 'cmid');
    }

    /**
     * An activity without a completion condition reports "none", whatever else happens.
     */
    public function test_activity_without_a_completion_condition_is_none(): void {
        $this->resetAfterTest();
        [$course, $student] = $this->setup_course();
        $quiz = $this->create_quiz($course, ['completion' => COMPLETION_TRACKING_NONE]);
        $this->setUser($student);

        $this->finish_quiz_attempt($quiz, (int) $student->id, 1, '3.14');

        $this->assertSame('none', $this->completions($course, $student)[(int) $quiz->cmid]);
    }

    /**
     * Viewing an activity that requires a view is reflected by the very next state.
     */
    public function test_view_is_reflected_immediately(): void {
        $this->resetAfterTest();
        [$course, $student] = $this->setup_course();
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionview' => COMPLETION_VIEW_REQUIRED,
        ]);
        $cmid = (int) $page->cmid;
        $this->setUser($student);
        $this->assertSame('incomplete', $this->completions($course, $student)[$cmid]);

        $cm = get_coursemodule_from_id('page', $cmid, $course->id, false, MUST_EXIST);
        (new \completion_info(get_course($course->id)))->set_module_viewed($cm, $student->id);

        $this->assertSame('complete', $this->completions($course, $student)[$cmid]);
    }

    /**
     * "Receive a grade", "receive a passing grade" and "all attempts used" follow the attempts, each time at once.
     */
    public function test_grade_pass_and_fail_are_reflected_immediately(): void {
        $this->resetAfterTest();
        [$course, $student] = $this->setup_course();
        $grade = $this->create_quiz($course, [
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionusegrade' => 1,
        ]);
        $pass = $this->create_quiz($course, [
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionusegrade' => 1,
            'completionpassgrade' => 1,
        ]);
        $attempts = $this->create_quiz($course, [
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionusegrade' => 1,
            'completionpassgrade' => 1,
            'completionattemptsexhausted' => 1,
            'attempts' => 2,
        ]);
        $this->setUser($student);
        $this->assertSame('incomplete', $this->completions($course, $student)[(int) $pass->cmid]);

        // A wrong answer is a grade below the pass mark: Moodle records it as failed.
        $this->finish_quiz_attempt($pass, (int) $student->id, 1, '0');
        $this->finish_quiz_attempt($grade, (int) $student->id, 1, '0');
        $this->finish_quiz_attempt($attempts, (int) $student->id, 1, '0');
        $states = $this->completions($course, $student);
        $this->assertSame('failed', $states[(int) $pass->cmid]);
        $this->assertSame('failed', $states[(int) $grade->cmid]);

        // A right answer in a new attempt turns the failed quiz into a complete one.
        $this->finish_quiz_attempt($pass, (int) $student->id, 2, '3.14');
        $this->assertSame('complete', $this->completions($course, $student)[(int) $pass->cmid]);

        // The last allowed attempt of the third quiz is wrong: the attempts are used up.
        $this->finish_quiz_attempt($attempts, (int) $student->id, 2, '0');
        $this->assertSame('failed', $this->completions($course, $student)[(int) $attempts->cmid]);
    }

    /**
     * A stale completion cache is never served: the state follows the database.
     *
     * Another web server updating the row leaves this server's cache entry behind; the test
     * writes the row without telling the cache, as that server would.
     */
    public function test_stale_completion_cache_is_not_served(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $student] = $this->setup_course();
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $cmid = (int) $page->cmid;
        $this->setUser($student);
        // This warms the cache with "incomplete".
        $this->assertSame('incomplete', $this->completions($course, $student)[$cmid]);

        $DB->insert_record('course_modules_completion', (object) [
            'coursemoduleid' => $cmid,
            'userid' => $student->id,
            'completionstate' => COMPLETION_COMPLETE,
            'viewed' => 0,
            'timemodified' => time(),
        ]);

        $this->assertSame('complete', $this->completions($course, $student)[$cmid]);
    }

    /**
     * The flag follows the course setting and the site setting; with tracking off every activity is "none".
     */
    public function test_course_completion_enabled_follows_the_settings(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        [$course, $student] = $this->setup_course();
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $cmid = (int) $page->cmid;

        $state = course_state::for_user($course->id, $student->id);
        $this->assertTrue($state['courseCompletionEnabled']);
        $this->assertSame('incomplete', $this->activity($state, $cmid)['completion']);

        $DB->set_field('course', 'enablecompletion', 0, ['id' => $course->id]);
        rebuild_course_cache($course->id, true);
        $state = course_state::for_user($course->id, $student->id);
        $this->assertFalse($state['courseCompletionEnabled']);
        $this->assertSame('none', $this->activity($state, $cmid)['completion']);

        $DB->set_field('course', 'enablecompletion', 1, ['id' => $course->id]);
        rebuild_course_cache($course->id, true);
        $CFG->enablecompletion = 0;
        $this->assertFalse(course_state::for_user($course->id, $student->id)['courseCompletionEnabled']);
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
