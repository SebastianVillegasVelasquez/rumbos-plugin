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
 * Tests for what a student may be told about their quiz results.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\local\quiz_result::class)]
final class quiz_result_test extends \advanced_testcase {
    /**
     * A Rumbos course with a graded one-question quiz and an enrolled student.
     *
     * @param array $quizsettings Overrides for the quiz settings.
     * @param string $qtype The question type: "numerical" (answer 3.14, graded at once) or "essay" (graded by hand).
     * @return array [course, quiz, student]
     */
    private function setup_quiz(array $quizsettings = [], string $qtype = 'numerical'): array {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'rumbos']);
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');

        $quiz = $generator->get_plugin_generator('mod_quiz')->create_instance(
            ['course' => $course->id, 'grade' => 100.0, 'sumgrades' => 1, 'attempts' => 0] + $quizsettings
        );
        $questions = $generator->get_plugin_generator('core_question');
        $category = $questions->create_question_category();
        $question = $questions->create_question($qtype, $qtype === 'essay' ? 'editor' : null, ['category' => $category->id]);
        quiz_add_quiz_question($question->id, $quiz);

        return [$course, $quiz, $student];
    }

    /**
     * Makes an attempt for a user.
     *
     * @param \stdClass $quiz
     * @param \stdClass $user
     * @param int $number The attempt number.
     * @param string $how "finish" (answered and handed in), "inprogress", "abandon" or "preview".
     * @param string $answer What to answer; "3.14" is right for the numerical question.
     */
    private function attempt(\stdClass $quiz, \stdClass $user, int $number, string $how, string $answer = '3.14'): void {
        $quizobj = quiz_settings::create($quiz->id, $user->id);
        $quba = \question_engine::make_questions_usage_by_activity('mod_quiz', $quizobj->get_context());
        $quba->set_preferred_behaviour($quizobj->get_quiz()->preferredbehaviour);
        $time = time();

        $attempt = quiz_create_attempt($quizobj, $number, false, $time, $how === 'preview', $user->id);
        quiz_start_new_attempt($quizobj, $quba, $attempt, $number, $time);
        quiz_attempt_save_started($quizobj, $quba, $attempt);
        $attemptobj = quiz_attempt::create($attempt->id);
        if ($how === 'inprogress') {
            return;
        }
        if ($how === 'abandon') {
            $attemptobj->process_abandon($time, true);
            return;
        }
        $slotanswer = ['answer' => $answer];
        if ($quizobj->get_question($attemptobj->get_question_attempt(1)->get_question_id())->qtype === 'essay') {
            $slotanswer['answerformat'] = FORMAT_HTML;
        }
        $attemptobj->process_submitted_actions($time, false, [1 => $slotanswer]);
        $attemptobj->process_submit($time, false);
        $attemptobj->process_grade_submission($time);
    }

    /**
     * The result of a student in the quiz.
     *
     * @param \stdClass $course
     * @param \stdClass $quiz
     * @param \stdClass $user
     * @return array
     */
    private function reply_for(\stdClass $course, \stdClass $quiz, \stdClass $user): array {
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id);
        return quiz_result::for_user($course->id, $cm->id, $user->id);
    }

    /**
     * Shorthand for the QuizResult of a call that must have succeeded.
     *
     * @param \stdClass $course
     * @param \stdClass $quiz
     * @param \stdClass $user
     * @return array
     */
    private function ok(\stdClass $course, \stdClass $quiz, \stdClass $user): array {
        $reply = $this->reply_for($course, $quiz, $user);
        $this->assertSame('', $reply['error']);
        return $reply['result'];
    }

    /**
     * A student who has not started the quiz has nothing to report.
     */
    public function test_no_attempts(): void {
        [$course, $quiz, $student] = $this->setup_quiz();

        $this->assertSame([
            'attempts' => 0, 'finishedAttempts' => 0, 'inProgress' => false,
            'percent' => null, 'pendingGrading' => false, 'passedThreshold' => null,
        ], $this->ok($course, $quiz, $student));
    }

    /**
     * An attempt in progress is counted as open and gives no grade.
     */
    public function test_an_attempt_in_progress(): void {
        [$course, $quiz, $student] = $this->setup_quiz();
        $this->attempt($quiz, $student, 1, 'inprogress');

        $result = $this->ok($course, $quiz, $student);

        $this->assertSame(1, $result['attempts']);
        $this->assertSame(0, $result['finishedAttempts']);
        $this->assertTrue($result['inProgress']);
        $this->assertNull($result['percent']);
        $this->assertNull($result['passedThreshold']);
    }

    /**
     * A finished attempt gives the percentage and, against the default threshold of 80, the outcome.
     */
    public function test_a_finished_attempt_passes_or_fails_the_threshold(): void {
        [$course, $quiz, $student] = $this->setup_quiz();
        $this->attempt($quiz, $student, 1, 'finish', '3.14');

        $result = $this->ok($course, $quiz, $student);
        $this->assertSame(1, $result['finishedAttempts']);
        $this->assertFalse($result['inProgress']);
        $this->assertEquals(100.0, $result['percent']);
        $this->assertFalse($result['pendingGrading']);
        $this->assertTrue($result['passedThreshold']);

        [$course, $quiz, $other] = $this->setup_quiz();
        $this->attempt($quiz, $other, 1, 'finish', '0');
        $result = $this->ok($course, $quiz, $other);
        $this->assertEquals(0.0, $result['percent']);
        $this->assertFalse($result['passedThreshold']);
    }

    /**
     * The threshold of the course decides the outcome, and 0 means there is none.
     */
    public function test_the_threshold_of_the_course(): void {
        [$course, $quiz, $student] = $this->setup_quiz();
        $this->attempt($quiz, $student, 1, 'finish', '3.14');
        $format = course_get_format($course);

        $format->update_course_format_options(['rumbos_quizthreshold' => 100]);
        $this->assertTrue($this->ok($course, $quiz, $student)['passedThreshold']);

        $format->update_course_format_options(['rumbos_quizthreshold' => 0]);
        $result = $this->ok($course, $quiz, $student);
        $this->assertNull($result['passedThreshold']);
        $this->assertEquals(100.0, $result['percent']);
    }

    /**
     * The grade is the one the gradebook holds for the grading method of the quiz.
     *
     * @param string $method The grading method (the quiz constants are strings).
     * @param float $expected The percentage after one right and one wrong attempt, in that order.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('grading_methods')]
    public function test_the_grading_method_is_honoured(string $method, float $expected): void {
        [$course, $quiz, $student] = $this->setup_quiz(['grademethod' => (int) $method]);
        $this->attempt($quiz, $student, 1, 'finish', '3.14');
        $this->attempt($quiz, $student, 2, 'finish', '0');

        $result = $this->ok($course, $quiz, $student);

        $this->assertSame(2, $result['finishedAttempts']);
        $this->assertEquals($expected, $result['percent']);
    }

    /**
     * The four grading methods.
     *
     * @return array
     */
    public static function grading_methods(): array {
        return [
            'highest' => [QUIZ_GRADEHIGHEST, 100.0],
            'average' => [QUIZ_GRADEAVERAGE, 50.0],
            'first' => [QUIZ_ATTEMPTFIRST, 100.0],
            'last' => [QUIZ_ATTEMPTLAST, 0.0],
        ];
    }

    /**
     * An abandoned attempt and a teacher's preview are not finished attempts.
     */
    public function test_abandoned_attempts_and_previews_are_not_finished(): void {
        [$course, $quiz, $student] = $this->setup_quiz();
        $this->attempt($quiz, $student, 1, 'abandon');
        $this->attempt($quiz, $student, 2, 'preview');

        $result = $this->ok($course, $quiz, $student);

        $this->assertSame(1, $result['attempts'], 'the abandoned one counts as an attempt, the preview does not');
        $this->assertSame(0, $result['finishedAttempts']);
        $this->assertFalse($result['inProgress']);
        $this->assertNull($result['percent']);
    }

    /**
     * A finished attempt that a teacher still has to grade is reported as pending, with no outcome.
     */
    public function test_pending_manual_grading(): void {
        [$course, $quiz, $student] = $this->setup_quiz([], 'essay');
        $this->attempt($quiz, $student, 1, 'finish', 'An essay.');

        $result = $this->ok($course, $quiz, $student);

        $this->assertSame(1, $result['finishedAttempts']);
        $this->assertTrue($result['pendingGrading']);
        $this->assertNull($result['percent']);
        $this->assertNull($result['passedThreshold']);
    }

    /**
     * Another attempt already graded does not clear the pending flag, and the outcome stays unknown.
     */
    public function test_pending_wins_over_an_earlier_graded_attempt(): void {
        [$course, $quiz, $student] = $this->setup_quiz([], 'numerical');
        $this->attempt($quiz, $student, 1, 'finish', '3.14');
        global $DB;
        // Simulate an attempt that is finished but has no mark yet.
        $DB->set_field('quiz_attempts', 'sumgrades', null, ['quiz' => $quiz->id, 'userid' => $student->id]);

        $result = $this->ok($course, $quiz, $student);

        $this->assertTrue($result['pendingGrading']);
        $this->assertNull($result['passedThreshold']);
    }

    /**
     * A grade hidden in the gradebook is never given: the item, the single grade, or a "hidden until" date.
     *
     * @param string $how What to hide.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('hidden_grades')]
    public function test_a_hidden_grade_is_not_given(string $how): void {
        [$course, $quiz, $student] = $this->setup_quiz();
        $this->attempt($quiz, $student, 1, 'finish', '3.14');
        $this->assertEquals(100.0, $this->ok($course, $quiz, $student)['percent'], 'visible before hiding it');

        $item = $this->grade_item($course, $quiz);
        switch ($how) {
            case 'item':
                $item->set_hidden(1);
                break;
            case 'grade':
                $grade = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $student->id]);
                $grade->set_hidden(1);
                break;
            case 'itemuntil':
                $item->set_hidden(time() + DAYSECS);
                break;
            case 'gradeuntil':
                $grade = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $student->id]);
                $grade->set_hidden(time() + DAYSECS);
                break;
        }

        $result = $this->ok($course, $quiz, $student);

        $this->assertNull($result['percent']);
        $this->assertNull($result['passedThreshold'], 'the outcome would give the grade away');
        $this->assertSame(1, $result['finishedAttempts'], 'the attempts are not secret');
    }

    /**
     * What can be hidden.
     *
     * @return array
     */
    public static function hidden_grades(): array {
        return ['item' => ['item'], 'grade' => ['grade'], 'item until' => ['itemuntil'], 'grade until' => ['gradeuntil']];
    }

    /**
     * A hidden-until date in the past no longer hides the grade.
     */
    public function test_a_hidden_until_date_in_the_past_shows_the_grade(): void {
        [$course, $quiz, $student] = $this->setup_quiz();
        $this->attempt($quiz, $student, 1, 'finish', '3.14');
        $item = $this->grade_item($course, $quiz);
        $item->set_hidden(time() - DAYSECS);

        $this->assertEquals(100.0, $this->ok($course, $quiz, $student)['percent']);
    }

    /**
     * The quiz's own review options decide too: no marks after the attempt means no grade here.
     */
    public function test_review_options_that_hide_the_marks_hide_the_grade(): void {
        global $DB;
        [$course, $quiz, $student] = $this->setup_quiz();
        $this->attempt($quiz, $student, 1, 'finish', '3.14');
        $DB->set_field('quiz', 'reviewmarks', 0, ['id' => $quiz->id]);
        $DB->set_field('quiz', 'reviewmaxmarks', 0, ['id' => $quiz->id]);

        $result = $this->ok($course, $quiz, $student);

        $this->assertNull($result['percent']);
        $this->assertNull($result['passedThreshold']);
        $this->assertSame(1, $result['finishedAttempts']);
    }

    /**
     * A course that does not show grades to students gives none.
     */
    public function test_a_course_that_hides_grades_gives_none(): void {
        [$course, $quiz, $student] = $this->setup_quiz();
        $this->attempt($quiz, $student, 1, 'finish', '3.14');
        update_course((object) ['id' => $course->id, 'showgrades' => 0]);

        $this->assertNull($this->ok($course, $quiz, $student)['percent']);
    }

    /**
     * A role that may not view grades gets none.
     */
    public function test_a_user_who_may_not_view_grades_gets_none(): void {
        [$course, $quiz, $student] = $this->setup_quiz();
        $this->attempt($quiz, $student, 1, 'finish', '3.14');
        assign_capability(
            'moodle/grade:view',
            CAP_PROHIBIT,
            $this->get_student_role_id(),
            \core\context\course::instance($course->id)->id,
            true
        );

        $this->assertNull($this->ok($course, $quiz, $student)['percent']);
    }

    /**
     * A quiz without a grade gives no percentage.
     */
    public function test_an_ungraded_quiz_gives_no_percentage(): void {
        global $DB;
        [$course, $quiz, $student] = $this->setup_quiz();
        $this->attempt($quiz, $student, 1, 'finish', '3.14');
        $DB->set_field('quiz', 'grade', 0, ['id' => $quiz->id]);

        $result = $this->ok($course, $quiz, $student);

        $this->assertNull($result['percent']);
        $this->assertSame(1, $result['finishedAttempts']);
    }

    /**
     * The answer is about the user it is asked for, never about anybody else.
     */
    public function test_other_users_are_never_mixed_in(): void {
        [$course, $quiz, $student] = $this->setup_quiz();
        $other = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($other->id, $course->id, 'student');
        $this->attempt($quiz, $other, 1, 'finish', '3.14');
        $this->attempt($quiz, $other, 2, 'inprogress');

        $mine = $this->ok($course, $quiz, $student);

        $this->assertSame(0, $mine['attempts']);
        $this->assertFalse($mine['inProgress']);
        $this->assertNull($mine['percent']);
        $this->assertSame(
            ['attempts', 'finishedAttempts', 'inProgress', 'percent', 'pendingGrading', 'passedThreshold'],
            array_keys($mine)
        );
    }

    /**
     * An activity that is missing, is not a quiz, is in another course or is hidden is "not found".
     */
    public function test_not_found(): void {
        [$course, $quiz, $student] = $this->setup_quiz();
        $generator = $this->getDataGenerator();
        $page = $generator->create_module('page', ['course' => $course->id]);
        $othercourse = $generator->create_course();
        $otherquiz = $generator->get_plugin_generator('mod_quiz')->create_instance(['course' => $othercourse->id]);
        $hidden = $generator->get_plugin_generator('mod_quiz')->create_instance(['course' => $course->id, 'visible' => 0]);
        $hiddencm = get_coursemodule_from_instance('quiz', $hidden->id);
        $othercm = get_coursemodule_from_instance('quiz', $otherquiz->id);

        foreach ([999999, $page->cmid, $othercm->id, $hiddencm->id] as $cmid) {
            $this->assertSame(
                ['error' => 'not_found', 'result' => null],
                quiz_result::for_user($course->id, $cmid, $student->id),
                (string) $cmid
            );
        }
    }

    /**
     * Someone who sees the activity but may not view the quiz is told so.
     */
    public function test_capability(): void {
        [$course, $quiz, $student] = $this->setup_quiz();
        assign_capability(
            'mod/quiz:view',
            CAP_PROHIBIT,
            $this->get_student_role_id(),
            \core\context\course::instance($course->id)->id,
            true
        );

        $this->assertSame(['error' => 'capability', 'result' => null], $this->reply_for($course, $quiz, $student));
    }

    /**
     * Someone with no access to the course gets no result, and nothing that says whether the quiz exists.
     */
    public function test_an_outsider_gets_nothing(): void {
        [$course, $quiz] = $this->setup_quiz();
        $outsider = $this->getDataGenerator()->create_user();

        $reply = $this->reply_for($course, $quiz, $outsider);

        $this->assertNull($reply['result']);
        $this->assertContains($reply['error'], ['not_found', 'capability']);
    }

    /**
     * The gradebook item of a quiz.
     *
     * @param \stdClass $course
     * @param \stdClass $quiz
     * @return \grade_item
     */
    private function grade_item(\stdClass $course, \stdClass $quiz): \grade_item {
        return \grade_item::fetch(
            ['itemtype' => 'mod', 'itemmodule' => 'quiz', 'iteminstance' => $quiz->id, 'courseid' => $course->id]
        );
    }

    /**
     * The id of the student role.
     *
     * @return int
     */
    private function get_student_role_id(): int {
        global $DB;
        return (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
    }
}
