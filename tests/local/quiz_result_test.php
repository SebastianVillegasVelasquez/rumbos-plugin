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
            'previewAttempts' => 0, 'canAttempt' => true, 'reason' => 'no_attempts',
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
        $this->assertSame(0, $mine['previewAttempts']);
        $this->assertSame(
            ['attempts', 'finishedAttempts', 'inProgress', 'percent', 'pendingGrading', 'passedThreshold',
                'previewAttempts', 'canAttempt', 'reason'],
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
     * Starts and finishes (or leaves open) an attempt the way the quiz pages do, as the user who is logged in.
     *
     * Whether it is a preview is then up to Moodle: the capability mod/quiz:preview of the current user decides, as it
     * does in startattempt.php, and not anything the test says.
     *
     * @param \stdClass $quiz
     * @param bool $finish Whether to hand it in ("Submit all and finish") or leave it open.
     */
    private function attempt_like_the_web(\stdClass $quiz, bool $finish): void {
        global $USER;
        $quizobj = quiz_settings::create($quiz->id, $USER->id);
        $attempt = quiz_prepare_and_start_new_attempt($quizobj, 1, false);
        $attemptobj = quiz_attempt::create($attempt->id);
        $attemptobj->process_submitted_actions(time(), false, [1 => ['answer' => '3.14']]);
        if ($finish) {
            $attemptobj->process_attempt(time(), true, false, 0);
        }
    }

    /**
     * A user with a role that has mod/quiz:preview and not mod/quiz:attempt in the course of the quiz.
     *
     * @param \stdClass $course
     * @param string $role "editingteacher" or "teacher".
     * @return \stdClass
     */
    private function enrol_teacher(\stdClass $course, string $role = 'editingteacher'): \stdClass {
        return $this->getDataGenerator()->create_and_enrol($course, $role);
    }

    /**
     * What Moodle does for somebody who may preview: the finished quiz is a preview, not an attempt.
     *
     * This is the production incident: the teacher finishes the quiz and the result says nothing was finished.
     */
    public function test_a_teacher_who_finishes_the_quiz_has_a_preview_not_an_attempt(): void {
        [$course, $quiz] = $this->setup_quiz();
        $teacher = $this->enrol_teacher($course);
        $this->setUser($teacher);
        $this->attempt_like_the_web($quiz, true);

        $result = $this->ok($course, $quiz, $teacher);

        $this->assertSame(0, $result['attempts']);
        $this->assertSame(0, $result['finishedAttempts']);
        $this->assertFalse($result['inProgress']);
        $this->assertNull($result['percent']);
        $this->assertSame(1, $result['previewAttempts']);
        $this->assertFalse($result['canAttempt']);
        $this->assertSame('cannot_attempt', $result['reason']);
    }

    /**
     * A preview that was left open is still a preview: it is not "in progress" in the contract's sense.
     */
    public function test_a_preview_left_open_is_not_in_progress(): void {
        [$course, $quiz] = $this->setup_quiz();
        $teacher = $this->enrol_teacher($course, 'teacher');
        $this->setUser($teacher);
        $this->attempt_like_the_web($quiz, false);

        $result = $this->ok($course, $quiz, $teacher);

        $this->assertFalse($result['inProgress']);
        $this->assertSame(0, $result['attempts']);
        $this->assertSame(1, $result['previewAttempts']);
    }

    /**
     * A manager at the site level, with no enrolment, behaves like a teacher: previews only.
     */
    public function test_a_manager_has_previews(): void {
        global $DB;
        [$course, $quiz] = $this->setup_quiz();
        $manager = $this->getDataGenerator()->create_user();
        role_assign(
            $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST),
            $manager->id,
            \core\context\system::instance()->id
        );
        $this->setUser($manager);
        $this->attempt_like_the_web($quiz, true);

        $result = $this->ok($course, $quiz, $manager);

        $this->assertSame([0, 0, 1, false, 'cannot_attempt'], [
            $result['attempts'], $result['finishedAttempts'], $result['previewAttempts'], $result['canAttempt'], $result['reason'],
        ]);
    }

    /**
     * A site administrator holds mod/quiz:attempt (they can do anything) and mod/quiz:preview, and the preview wins:
     * the attempt is a preview, and the reason is "preview_only", not "cannot_attempt".
     */
    public function test_an_administrator_has_previews_and_can_attempt(): void {
        [$course, $quiz] = $this->setup_quiz();
        $this->setAdminUser();
        $this->attempt_like_the_web($quiz, true);

        $result = $this->ok($course, $quiz, get_admin());

        $this->assertSame([0, 0, 1, true, 'preview_only'], [
            $result['attempts'], $result['finishedAttempts'], $result['previewAttempts'], $result['canAttempt'], $result['reason'],
        ]);
    }

    /**
     * A teacher who switches the role to Student attempts the quiz for real: the switch takes mod/quiz:preview away.
     */
    public function test_a_teacher_with_the_role_switched_to_student_has_real_attempts(): void {
        [$course, $quiz] = $this->setup_quiz();
        $teacher = $this->enrol_teacher($course);
        $this->setUser($teacher);
        role_switch($this->get_student_role_id(), \core\context\course::instance($course->id));
        $this->attempt_like_the_web($quiz, true);

        $result = $this->ok($course, $quiz, $teacher);

        $this->assertSame([1, 1, 0, true, 'ok'], [
            $result['attempts'], $result['finishedAttempts'], $result['previewAttempts'], $result['canAttempt'], $result['reason'],
        ]);
        $this->assertEquals(100.0, $result['percent']);
    }

    /**
     * The role switch is a property of the session, so the same user back in their own role is "cannot_attempt" again,
     * with the real attempt they made while switched still counted in the numbers of the v5.8 contract.
     */
    public function test_the_precedence_puts_cannot_attempt_before_ok(): void {
        [$course, $quiz] = $this->setup_quiz();
        $teacher = $this->enrol_teacher($course);
        $this->setUser($teacher);
        role_switch($this->get_student_role_id(), \core\context\course::instance($course->id));
        $this->attempt_like_the_web($quiz, true);
        role_switch(0, \core\context\course::instance($course->id));

        $result = $this->ok($course, $quiz, $teacher);

        $this->assertSame(1, $result['finishedAttempts']);
        $this->assertFalse($result['canAttempt']);
        $this->assertSame('cannot_attempt', $result['reason']);
    }

    /**
     * A switched administrator who had an open preview from before keeps using it: Moodle resumes the unfinished
     * attempt whatever it is, so what is finished after the switch is a preview and the result says so.
     */
    public function test_a_switched_administrator_resumes_the_earlier_preview(): void {
        [$course, $quiz] = $this->setup_quiz();
        $this->setAdminUser();
        $this->attempt_like_the_web($quiz, false);
        role_switch($this->get_student_role_id(), \core\context\course::instance($course->id));
        $quizobj = quiz_settings::create($quiz->id, get_admin()->id);
        [$currentattemptid] = quiz_validate_new_attempt($quizobj, $quizobj->get_access_manager(time()), false, -1, false);
        quiz_attempt::create($currentattemptid)->process_attempt(time(), true, false, 0);

        $result = $this->ok($course, $quiz, get_admin());

        $this->assertSame([0, 0, 1, true, 'preview_only'], [
            $result['attempts'], $result['finishedAttempts'], $result['previewAttempts'], $result['canAttempt'], $result['reason'],
        ]);
    }

    /**
     * Every reason with a user and a quiz that produce it, and nothing else about it.
     *
     * @param string $reason The reason expected.
     * @param string $setup The scenario: see {@see self::build_scenario()}.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('reasons')]
    public function test_each_reason(string $reason, string $setup): void {
        [$course, $quiz, $user] = $this->build_scenario($setup);

        $result = $this->ok($course, $quiz, $user);

        $this->assertSame($reason, $result['reason']);
    }

    /**
     * Every reason with its scenario.
     *
     * @return array
     */
    public static function reasons(): array {
        return [
            'ok' => ['ok', 'finished'],
            'ok, with another attempt open' => ['ok', 'finished_and_open'],
            'in progress' => ['in_progress', 'open'],
            'in progress, overdue' => ['in_progress', 'overdue'],
            'not graded yet, by hand' => ['not_graded_yet', 'essay'],
            'not graded yet, handed in and not processed' => ['not_graded_yet', 'submitted'],
            'preview only' => ['preview_only', 'preview'],
            'no attempts' => ['no_attempts', 'none'],
            'no attempts, only an abandoned one' => ['no_attempts', 'abandoned'],
            'cannot attempt' => ['cannot_attempt', 'teacher'],
        ];
    }

    /**
     * The rule of precedence, on its own: one row for each way the first condition that holds wins.
     *
     * @param bool $canattempt
     * @param array $counts What the user has; the rest are zero.
     * @param string $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('precedence')]
    public function test_the_precedence(bool $canattempt, array $counts, string $expected): void {
        $tally = $counts + [
            'finished' => 0, 'pendingfinished' => 0, 'inprogress' => 0, 'overdue' => 0, 'submitted' => 0, 'previews' => 0,
        ];

        $this->assertSame($expected, quiz_result::reason($canattempt, $tally));
    }

    /**
     * Combinations that settle the order of the reasons.
     *
     * @return array
     */
    public static function precedence(): array {
        return [
            'no capability beats everything' => [false, ['finished' => 2, 'inprogress' => 1, 'previews' => 3], 'cannot_attempt'],
            'finished beats open' => [true, ['finished' => 1, 'inprogress' => 1], 'ok'],
            'finished beats previews' => [true, ['finished' => 1, 'previews' => 2], 'ok'],
            'a graded one among the ungraded is ok' => [true, ['finished' => 2, 'pendingfinished' => 1], 'ok'],
            'open beats ungraded' => [true, ['finished' => 1, 'pendingfinished' => 1, 'overdue' => 1], 'in_progress'],
            'open beats previews' => [true, ['overdue' => 1, 'previews' => 1], 'in_progress'],
            'ungraded beats previews' => [true, ['finished' => 1, 'pendingfinished' => 1, 'previews' => 1], 'not_graded_yet'],
            'handed in beats previews' => [true, ['submitted' => 1, 'previews' => 1], 'not_graded_yet'],
            'previews beat nothing' => [true, ['previews' => 1], 'preview_only'],
            'nothing' => [true, [], 'no_attempts'],
        ];
    }

    /**
     * What each reason looks like in the rest of the result, so the fields cannot contradict the reason.
     *
     * @param string $reason The reason expected.
     * @param string $setup The scenario.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('reasons')]
    public function test_the_reason_agrees_with_the_counts(string $reason, string $setup): void {
        [$course, $quiz, $user] = $this->build_scenario($setup);

        $result = $this->ok($course, $quiz, $user);

        $this->assertSame($result['reason'] === 'cannot_attempt', !$result['canAttempt']);
        if ($result['reason'] === 'ok') {
            $this->assertGreaterThan(0, $result['finishedAttempts']);
        }
        if ($result['reason'] === 'in_progress') {
            $this->assertTrue($result['inProgress']);
            $this->assertSame(0, $result['finishedAttempts']);
        }
        if ($result['reason'] === 'preview_only') {
            $this->assertSame(0, $result['attempts']);
            $this->assertGreaterThan(0, $result['previewAttempts']);
        }
        if ($result['reason'] === 'no_attempts') {
            $this->assertSame(0, $result['finishedAttempts']);
            $this->assertFalse($result['inProgress']);
            $this->assertSame(0, $result['previewAttempts']);
        }
    }

    /**
     * A hidden grade changes the percentage and the outcome, and nothing else: the reason is the same with the grade
     * hidden as with it shown, and no grade appears under any reason.
     *
     * @param string $reason The reason expected.
     * @param string $setup The scenario.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('reasons')]
    public function test_a_hidden_grade_changes_no_reason_and_leaks_nothing(string $reason, string $setup): void {
        [$course, $quiz, $user] = $this->build_scenario($setup);
        $shown = $this->ok($course, $quiz, $user);

        $this->grade_item($course, $quiz)->set_hidden(1);
        $hidden = $this->ok($course, $quiz, $user);

        $this->assertSame($reason, $hidden['reason']);
        $this->assertSame($shown['reason'], $hidden['reason']);
        $this->assertNull($hidden['percent']);
        $this->assertNull($hidden['passedThreshold']);
        $this->assertSame($shown['finishedAttempts'], $hidden['finishedAttempts']);
        $this->assertSame($shown['previewAttempts'], $hidden['previewAttempts']);
        $this->assertStringNotContainsString('100', json_encode($hidden));
    }

    /**
     * Builds a user in front of a quiz in a given situation.
     *
     * @param string $setup finished, finished_and_open, open, overdue, essay (finished, waiting for a teacher),
     *      submitted (handed in, not processed), preview (a student with only a preview), none, abandoned, or teacher
     *      (somebody who previews: no mod/quiz:attempt).
     * @return array [course, quiz, user]
     */
    private function build_scenario(string $setup): array {
        global $DB;
        [$course, $quiz, $student] = $this->setup_quiz([], $setup === 'essay' ? 'essay' : 'numerical');
        $user = $student;
        switch ($setup) {
            case 'finished':
                $this->attempt($quiz, $user, 1, 'finish');
                break;
            case 'finished_and_open':
                $this->attempt($quiz, $user, 1, 'finish');
                $this->attempt($quiz, $user, 2, 'inprogress');
                break;
            case 'open':
                $this->attempt($quiz, $user, 1, 'inprogress');
                break;
            case 'overdue':
                $this->attempt($quiz, $user, 1, 'inprogress');
                $DB->set_field('quiz_attempts', 'state', quiz_attempt::OVERDUE, ['quiz' => $quiz->id, 'userid' => $user->id]);
                break;
            case 'essay':
                $this->attempt($quiz, $user, 1, 'finish', 'An essay.');
                break;
            case 'submitted':
                $this->attempt($quiz, $user, 1, 'inprogress');
                $DB->set_field('quiz_attempts', 'state', quiz_attempt::SUBMITTED, ['quiz' => $quiz->id, 'userid' => $user->id]);
                break;
            case 'preview':
                $this->attempt($quiz, $user, 1, 'preview');
                break;
            case 'abandoned':
                $this->attempt($quiz, $user, 1, 'abandon');
                break;
            case 'teacher':
                $user = $this->enrol_teacher($course);
                $this->attempt($quiz, $user, 1, 'preview');
                break;
        }
        return [$course, $quiz, $user];
    }

    /**
     * Previews are counted apart and never added to the real attempts, however many there are.
     */
    public function test_previews_and_real_attempts_stay_apart(): void {
        [$course, $quiz, $student] = $this->setup_quiz();
        $this->attempt($quiz, $student, 1, 'finish');
        $this->attempt($quiz, $student, 2, 'preview');
        $this->attempt($quiz, $student, 3, 'preview');

        $result = $this->ok($course, $quiz, $student);

        $this->assertSame(1, $result['attempts']);
        $this->assertSame(1, $result['finishedAttempts']);
        $this->assertSame(2, $result['previewAttempts']);
        $this->assertSame('ok', $result['reason']);
        $this->assertEquals(100.0, $result['percent'], 'a preview never changes the grade');
    }

    /**
     * An attempt that was never started is neither a preview nor an attempt.
     */
    public function test_an_unstarted_attempt_is_nothing(): void {
        global $DB;
        [$course, $quiz, $student] = $this->setup_quiz();
        $this->attempt($quiz, $student, 1, 'inprogress');
        $DB->set_field('quiz_attempts', 'state', quiz_attempt::NOT_STARTED, ['quiz' => $quiz->id, 'userid' => $student->id]);

        $result = $this->ok($course, $quiz, $student);

        $this->assertSame([0, 0, 'no_attempts'], [$result['attempts'], $result['previewAttempts'], $result['reason']]);
    }

    /**
     * The previews and the capability are the user's own: the previews of a teacher do not show in a student's result.
     */
    public function test_the_previews_of_others_are_not_mine(): void {
        [$course, $quiz, $student] = $this->setup_quiz();
        $teacher = $this->enrol_teacher($course);
        $this->attempt($quiz, $teacher, 1, 'preview');
        $this->attempt($quiz, $teacher, 2, 'preview');

        $mine = $this->ok($course, $quiz, $student);
        $theirs = $this->ok($course, $quiz, $teacher);

        $this->assertSame([0, true, 'no_attempts'], [$mine['previewAttempts'], $mine['canAttempt'], $mine['reason']]);
        $this->assertSame([2, false, 'cannot_attempt'], [$theirs['previewAttempts'], $theirs['canAttempt'], $theirs['reason']]);
    }

    /**
     * The answer for a user who is not allowed to see the quiz carries none of the new fields either.
     */
    public function test_an_error_has_none_of_the_new_fields(): void {
        [$course, $quiz, $student] = $this->setup_quiz();
        $this->attempt($quiz, $student, 1, 'preview');

        $reply = quiz_result::for_user($course->id, 999999, $student->id);

        $this->assertSame(['error' => 'not_found', 'result' => null], $reply);
    }

    /**
     * What a consumer of v5.8 reads is the same as before: the six fields it knows, with the same values, whatever the
     * new ones say. The new fields are only added.
     */
    public function test_a_v58_consumer_reads_the_same_values(): void {
        [$course, $quiz, $student] = $this->setup_quiz();
        $this->attempt($quiz, $student, 1, 'finish');
        $this->attempt($quiz, $student, 2, 'inprogress');
        $this->attempt($quiz, $student, 3, 'preview');

        $result = $this->ok($course, $quiz, $student);
        $v58 = array_intersect_key($result, array_flip(
            ['attempts', 'finishedAttempts', 'inProgress', 'percent', 'pendingGrading', 'passedThreshold']
        ));

        $this->assertSame(
            ['attempts' => 2, 'finishedAttempts' => 1, 'inProgress' => true, 'percent' => 100.0,
                'pendingGrading' => false, 'passedThreshold' => true],
            $v58
        );
        $this->assertSame(
            ['attempts', 'finishedAttempts', 'inProgress', 'percent', 'pendingGrading', 'passedThreshold'],
            array_slice(array_keys($result), 0, 6),
            'the old fields keep their names and their order; the new ones come after'
        );
    }

    /**
     * The check support reads: the capabilities, the attempts by state, the hidden-grade flag and the reason.
     */
    public function test_the_check_for_support(): void {
        global $DB;
        [$course, $quiz, $student] = $this->setup_quiz();
        $cmid = (int) get_coursemodule_from_instance('quiz', $quiz->id)->id;
        $this->attempt($quiz, $student, 1, 'finish');
        $this->attempt($quiz, $student, 2, 'inprogress');
        $this->attempt($quiz, $student, 3, 'abandon');
        $this->attempt($quiz, $student, 4, 'preview');
        $this->attempt($quiz, $student, 5, 'inprogress');
        $DB->set_field('quiz_attempts', 'state', quiz_attempt::OVERDUE, ['quiz' => $quiz->id, 'attempt' => 5]);
        $this->grade_item($course, $quiz)->set_hidden(1);

        $reply = quiz_result::diagnose($course->id, $cmid, $student->id, get_admin()->id);

        $this->assertSame('', $reply['error']);
        $check = $reply['check'];
        $this->assertSame(
            ['finished' => 1, 'inprogress' => 1, 'overdue' => 1, 'submitted' => 0, 'abandoned' => 1, 'preview' => 1],
            $check['counts']
        );
        $this->assertTrue($check['canAttempt']);
        $this->assertFalse($check['canPreview']);
        $this->assertTrue($check['enrolled']);
        $this->assertTrue($check['visible']);
        $this->assertTrue($check['hiddenGrade']);
        $this->assertSame('grade_hidden', $check['percentWithheldBy']);
        $this->assertSame('ok', $check['reason']);
        $this->assertSame($this->ok($course, $quiz, $student), $check['contract']['result'], 'the same answer the app gets');
    }

    /**
     * The check tells a teacher's preview from a student's attempt, which is the point of it.
     */
    public function test_the_check_sees_a_teacher_with_previews(): void {
        [$course, $quiz] = $this->setup_quiz();
        $cmid = (int) get_coursemodule_from_instance('quiz', $quiz->id)->id;
        $teacher = $this->enrol_teacher($course);
        $this->setUser($teacher);
        $this->attempt_like_the_web($quiz, true);

        $check = quiz_result::diagnose($course->id, $cmid, $teacher->id, get_admin()->id)['check'];

        $this->assertSame(1, $check['counts']['preview']);
        $this->assertSame(0, $check['counts']['finished']);
        $this->assertFalse($check['canAttempt']);
        $this->assertTrue($check['canPreview']);
        $this->assertSame('cannot_attempt', $check['reason']);
    }

    /**
     * A user who is not enrolled, or to whom the quiz is hidden, is told apart.
     */
    public function test_the_check_sees_enrolment_and_visibility(): void {
        [$course, $quiz, $student] = $this->setup_quiz();
        $cmid = (int) get_coursemodule_from_instance('quiz', $quiz->id)->id;
        $outsider = $this->getDataGenerator()->create_user();
        set_coursemodule_visible($cmid, 0);

        $asstudent = quiz_result::diagnose($course->id, $cmid, $student->id, get_admin()->id)['check'];
        $asoutsider = quiz_result::diagnose($course->id, $cmid, $outsider->id, get_admin()->id)['check'];

        $this->assertFalse($asstudent['visible']);
        $this->assertTrue($asstudent['enrolled']);
        $this->assertFalse($asoutsider['enrolled']);
        $this->assertSame(['error' => 'not_found', 'result' => null], $asstudent['contract']);
    }

    /**
     * Only somebody who may see grades or edit the course can ask about another user.
     */
    public function test_the_check_is_for_graders_and_editors_only(): void {
        [$course, $quiz, $student] = $this->setup_quiz();
        $cmid = (int) get_coursemodule_from_instance('quiz', $quiz->id)->id;
        $other = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $outsider = $this->getDataGenerator()->create_user();
        $teacher = $this->enrol_teacher($course);
        $nonediting = $this->enrol_teacher($course, 'teacher');

        foreach ([$other, $outsider, $student] as $viewer) {
            try {
                quiz_result::diagnose($course->id, $cmid, $student->id, $viewer->id);
                $this->fail('a user who may not see grades or edit the course got the check');
            } catch (\required_capability_exception $e) {
                $this->assertTrue(true);
            }
        }
        foreach ([$teacher, $nonediting] as $viewer) {
            $this->assertSame('', quiz_result::diagnose($course->id, $cmid, $student->id, $viewer->id)['error']);
        }
    }

    /**
     * Either capability is enough: editing the course without seeing grades, or seeing grades without editing.
     */
    public function test_the_check_needs_either_capability(): void {
        [$course, $quiz, $student] = $this->setup_quiz();
        $cmid = (int) get_coursemodule_from_instance('quiz', $quiz->id)->id;
        $coursecontext = \core\context\course::instance($course->id);

        $editor = $this->getDataGenerator()->create_user();
        $editorrole = $this->getDataGenerator()->create_role();
        assign_capability('format/rumbos:editmap', CAP_ALLOW, $editorrole, \core\context\system::instance()->id);
        $this->getDataGenerator()->enrol_user($editor->id, $course->id, $editorrole);
        $this->assertFalse(has_capability('moodle/grade:viewall', $coursecontext, $editor->id), 'precondition');

        $grader = $this->enrol_teacher($course, 'teacher');
        $this->assertFalse(has_capability('format/rumbos:editmap', $coursecontext, $grader->id), 'precondition');
        $this->assertTrue(has_capability('moodle/grade:viewall', $coursecontext, $grader->id), 'precondition');

        foreach ([$editor, $grader] as $viewer) {
            $this->assertSame('', quiz_result::diagnose($course->id, $cmid, $student->id, $viewer->id)['error']);
        }
    }

    /**
     * The check is a read: it stores nothing and logs nothing.
     */
    public function test_the_check_logs_nothing(): void {
        [$course, $quiz, $student] = $this->setup_quiz();
        $cmid = (int) get_coursemodule_from_instance('quiz', $quiz->id)->id;
        $this->attempt($quiz, $student, 1, 'finish');
        global $DB;
        $sink = $this->redirectEvents();
        $tables = ['quiz_attempts', 'format_rumbos_flow', 'grade_grades', 'grade_items'];
        $before = array_map(fn($table) => $DB->count_records($table), $tables);

        quiz_result::diagnose($course->id, $cmid, $student->id, get_admin()->id);

        $this->assertCount(0, $sink->get_events());
        $this->assertSame($before, array_map(fn($table) => $DB->count_records($table), $tables));
    }

    /**
     * A course module that is not a quiz of the course is "not found", not an exception.
     */
    public function test_the_check_of_something_that_is_not_a_quiz(): void {
        [$course, , $student] = $this->setup_quiz();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $this->assertSame(
            ['error' => 'not_found', 'check' => null],
            quiz_result::diagnose($course->id, $page->cmid, $student->id, get_admin()->id)
        );
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
