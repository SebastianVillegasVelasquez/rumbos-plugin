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

use core_external\external_api;
use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

/**
 * Tests for the quiz result function: access, the shape of the answer, and that it never says more than it may.
 *
 * The rules for what the result holds are tested in {@see \format_rumbos\local\quiz_result_test}.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\external\get_quiz_result::class)]
final class get_quiz_result_test extends \advanced_testcase {
    /**
     * A Rumbos course with a graded quiz and two students, the first with a perfect finished attempt.
     *
     * @return array [course, cmid, student, otherstudent]
     */
    private function setup_quiz(): array {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'rumbos']);
        $student = $generator->create_and_enrol($course, 'student');
        $other = $generator->create_and_enrol($course, 'student');
        $quiz = $generator->get_plugin_generator('mod_quiz')->create_instance(
            ['course' => $course->id, 'grade' => 100.0, 'sumgrades' => 1]
        );
        $questions = $generator->get_plugin_generator('core_question');
        $category = $questions->create_question_category();
        $question = $questions->create_question('numerical', null, ['category' => $category->id]);
        quiz_add_quiz_question($question->id, $quiz);

        $quizobj = quiz_settings::create($quiz->id, $student->id);
        $quba = \question_engine::make_questions_usage_by_activity('mod_quiz', $quizobj->get_context());
        $quba->set_preferred_behaviour($quizobj->get_quiz()->preferredbehaviour);
        $time = time();
        $attempt = quiz_create_attempt($quizobj, 1, false, $time, false, $student->id);
        quiz_start_new_attempt($quizobj, $quba, $attempt, 1, $time);
        quiz_attempt_save_started($quizobj, $quba, $attempt);
        $attemptobj = quiz_attempt::create($attempt->id);
        $attemptobj->process_submitted_actions($time, false, [1 => ['answer' => '3.14']]);
        $attemptobj->process_submit($time, false);
        $attemptobj->process_grade_submission($time);

        $cmid = (int) get_coursemodule_from_instance('quiz', $quiz->id)->id;
        return [$course, $cmid, $student, $other];
    }

    /**
     * Runs the function the way the AJAX layer does: validated, then cleaned against its declared structure.
     *
     * @param int $courseid
     * @param int $cmid
     * @return array
     */
    private function call(int $courseid, int $cmid): array {
        return external_api::clean_returnvalue(
            get_quiz_result::execute_returns(),
            get_quiz_result::execute($courseid, $cmid)
        );
    }

    /**
     * The student's own result, in the shape of the contract.
     */
    public function test_a_student_gets_their_result(): void {
        [$course, $cmid, $student] = $this->setup_quiz();
        $this->setUser($student);

        $reply = $this->call($course->id, $cmid);

        $this->assertSame('', $reply['error']);
        $this->assertSame(
            ['attempts', 'finishedAttempts', 'inProgress', 'percent', 'pendingGrading', 'passedThreshold'],
            array_keys($reply['result'])
        );
        $this->assertSame(1, $reply['result']['finishedAttempts']);
        $this->assertEquals(100.0, $reply['result']['percent']);
        $this->assertTrue($reply['result']['passedThreshold']);
    }

    /**
     * Another student, without attempts, gets nothing of the first one's: there is no user parameter to ask with.
     */
    public function test_another_student_gets_only_their_own(): void {
        [$course, $cmid, , $other] = $this->setup_quiz();
        $this->setUser($other);

        $reply = $this->call($course->id, $cmid);

        $this->assertSame(0, $reply['result']['attempts']);
        $this->assertNull($reply['result']['percent']);
        $this->assertNull($reply['result']['passedThreshold']);
        $this->assertSame(['courseid', 'cmid'], array_keys(get_quiz_result::execute_parameters()->keys));
    }

    /**
     * A grade the teacher hid does not reach the student through this function either.
     */
    public function test_a_hidden_grade_is_not_leaked(): void {
        [$course, $cmid, $student] = $this->setup_quiz();
        $cm = get_coursemodule_from_id('quiz', $cmid);
        $item = \grade_item::fetch(
            ['itemtype' => 'mod', 'itemmodule' => 'quiz', 'iteminstance' => $cm->instance, 'courseid' => $course->id]
        );
        $item->set_hidden(1);
        $this->setUser($student);

        $reply = $this->call($course->id, $cmid);

        $this->assertNull($reply['result']['percent']);
        $this->assertNull($reply['result']['passedThreshold']);
        $this->assertStringNotContainsString('100', json_encode($reply['result']), 'no number that is the grade');
    }

    /**
     * An error answer has no result at all.
     */
    public function test_an_error_has_no_result(): void {
        [$course, , $student] = $this->setup_quiz();
        $this->setUser($student);

        $reply = $this->call($course->id, 999999);

        $this->assertSame(['error' => 'not_found'], $reply);
    }

    /**
     * Somebody who may not view the quiz is told so.
     */
    public function test_capability_error(): void {
        global $DB;
        [$course, $cmid, $student] = $this->setup_quiz();
        assign_capability(
            'mod/quiz:view',
            CAP_PROHIBIT,
            $DB->get_field('role', 'id', ['shortname' => 'student']),
            \core\context\course::instance($course->id)->id,
            true
        );
        $this->setUser($student);

        $this->assertSame(['error' => 'capability'], $this->call($course->id, $cmid));
    }

    /**
     * Somebody with no access to the course cannot ask.
     */
    public function test_it_needs_access_to_the_course(): void {
        [$course, $cmid] = $this->setup_quiz();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\moodle_exception::class);
        $this->call($course->id, $cmid);
    }

    /**
     * Someone logged in as the student gets what the student gets, and no more.
     */
    public function test_log_in_as_gets_what_the_student_gets(): void {
        [$course, $cmid, $student] = $this->setup_quiz();
        $this->setAdminUser();
        \core\session\manager::loginas($student->id, \context_system::instance());

        $reply = $this->call($course->id, $cmid);

        $this->assertSame(1, $reply['result']['finishedAttempts']);
    }
}
