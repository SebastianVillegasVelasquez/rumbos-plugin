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

namespace format_rumbos\local\mirror;

use format_rumbos\mirror_fixture;
use mod_quiz\quiz_attempt;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../fixtures/result_testcase.php');
require_once(__DIR__ . '/../../fixtures/mirror_fixture.php');

/**
 * Tests for the native quiz attempts written for attempts of the Rumbos service.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(mirror_attempts::class)]
final class mirror_attempts_test extends \format_rumbos\result_testcase {
    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var exam The fixture exam. */
    private exam $exam;

    /** @var \stdClass The row of the mirror quiz. */
    private \stdClass $mirror;

    #[\Override]
    public static function setUpBeforeClass(): void {
        global $CFG;
        parent::setUpBeforeClass();
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->dirroot . '/mod/quiz/report/reportlib.php');
        require_once($CFG->dirroot . '/mod/quiz/report/statistics/report.php');
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->libdir . '/completionlib.php');
    }

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->course = $this->make_course();
        $definition = mirror_fixture::definition();
        $this->exam = exam::from_definition($definition, mirror_fixture::hash($definition));
        [$this->mirror] = mirror_quiz::ensure($this->course, $this->exam, $this->now);
    }

    /**
     * An attempt of the fixture exam, as the plugin reads it from a statement.
     *
     * @param string $attemptid
     * @param int[] $section Points in each section (listening, reading, use of English, writing, speaking).
     * @param bool $passed What the service decided.
     * @param callable|null $change Changes the claims of the record.
     * @param bool $text Whether the written text travels.
     * @return attempt_data
     */
    private function attempt(
        string $attemptid,
        array $section,
        bool $passed = false,
        ?callable $change = null,
        bool $text = true
    ): attempt_data {
        $record = mirror_fixture::record(mirror_fixture::points($section), null, $text);
        if ($change !== null) {
            $record = $change($record);
        }
        return attempt_data::from_claims((object) json_decode(json_encode($record)), [
            'attempt_id' => $attemptid, 'purpose' => 'check_in', 'finished_at' => 1790000000, 'passed' => $passed,
            'percent' => mirror_fixture::display(mirror_fixture::total105($section)),
        ]);
    }

    /**
     * Writes an attempt for a student.
     *
     * @param \stdClass $user
     * @param attempt_data $attempt
     * @return array The result of {@see mirror_attempts::write()}.
     */
    private function save(\stdClass $user, attempt_data $attempt): array {
        $courseid = (int) $this->course->id;
        return mirror_attempts::write($this->mirror, $this->exam, $attempt, $courseid, (int) $user->id, $this->now);
    }

    /**
     * Writes an attempt of the fixture exam for a student.
     *
     * @param \stdClass $user
     * @param string $attemptid
     * @param int[] $section
     * @param bool $passed
     * @return array
     */
    private function write(\stdClass $user, string $attemptid, array $section, bool $passed = false): array {
        return $this->save($user, $this->attempt($attemptid, $section, $passed));
    }

    /**
     * The quiz attempt written for an attempt of the service.
     *
     * @param string $attemptid
     * @return quiz_attempt
     */
    private function quiz_attempt(string $attemptid): quiz_attempt {
        $row = mirror_store::find_attempt($attemptid);
        return quiz_attempt::create((int) $row->quizattemptid);
    }

    /**
     * The question attempt of a question of the exam.
     *
     * @param quiz_attempt $attemptobj
     * @param string $itemid The question id.
     * @return \question_attempt
     */
    private function qa(quiz_attempt $attemptobj, string $itemid): \question_attempt {
        return $attemptobj->get_question_attempt((int) mirror_store::items((int) $this->mirror->id)[$itemid]->slot);
    }

    /**
     * The attempt is a finished, real (not preview) attempt of the student, at the time the student finished.
     */
    public function test_a_finished_attempt_is_written_for_the_student(): void {
        $user = $this->make_user($this->course);

        $result = $this->write($user, 'att-1', [6, 1, 3, 5, 5]);

        $this->assertSame(['written' => true, 'diverged' => false], $result);
        $attemptobj = $this->quiz_attempt('att-1');
        $row = $attemptobj->get_attempt();
        $this->assertSame(quiz_attempt::FINISHED, $row->state);
        $this->assertEquals($user->id, $row->userid);
        $this->assertEquals(0, $row->preview);
        $this->assertEquals(1, $row->attempt);
        $this->assertEquals(1790000000, $row->timestart);
        $this->assertEquals(1790000000, $row->timefinish);
        $mapping = mirror_store::find_attempt('att-1');
        $this->assertSame('79.76', $mapping->percent);
        $this->assertSame('0', $mapping->passed);
        $this->assertSame('0', $mapping->diverged);
        $this->assertEquals($this->course->id, $mapping->courseid);
    }

    /**
     * The attempt keeps what the student answered, as the question attempt steps the responses report reads.
     */
    public function test_the_real_answers_are_kept(): void {
        $user = $this->make_user($this->course);
        $this->write($user, 'att-1', [6, 1, 3, 5, 5]);
        $attemptobj = $this->quiz_attempt('att-1');

        // Question l-01 earned its point: the right option, "first".
        $this->assertSame('first', $this->qa($attemptobj, 'l-01')->get_response_summary());
        // Question l-02 earned nothing: "first" was chosen and the key is "second".
        $this->assertSame('first', $this->qa($attemptobj, 'l-02')->get_response_summary());
        // A matching pair keeps the place the student chose.
        $this->assertSame('a theft report', $this->qa($attemptobj, 'l-11:1')->get_response_summary());
        // A written task with one box keeps the text; with two, each is named.
        $this->assertSame('The student wrote this for w-01 q1.', $this->qa($attemptobj, 'w-01')->get_response_summary());
        $spoken = $this->qa($attemptobj, 's-02')->get_response_summary();
        $this->assertStringContainsString('Your opening: The student wrote this for s-02 f1.', $spoken);
        $this->assertStringContainsString('Your close: The student wrote this for s-02 f2.', $spoken);
        // Every question has its steps: started, answered, finished; a written task has the manual grade too.
        $this->assertGreaterThanOrEqual(3, count(iterator_to_array($this->qa($attemptobj, 'u-01')->get_full_step_iterator())));
        $this->assertGreaterThanOrEqual(4, count(iterator_to_array($this->qa($attemptobj, 'w-01')->get_full_step_iterator())));
    }

    /**
     * Each pair of a matching item is a question with its own mark, which Moodle's own grading gets right.
     */
    public function test_each_matching_pair_has_its_own_mark(): void {
        $user = $this->make_user($this->course);
        $this->write($user, 'att-1', [3, 0, 0, 0, 0]);
        $attemptobj = $this->quiz_attempt('att-1');

        foreach ([0 => 25 / 7, 1 => 25 / 7, 2 => 25 / 7, 3 => 0.0, 4 => 0.0] as $clip => $mark) {
            $qa = $this->qa($attemptobj, 'l-11:' . $clip);
            $this->assertEqualsWithDelta($mark, $qa->get_mark(), 0.0000001, (string) $clip);
            // No manual grade was needed: started, answered, finished.
            $this->assertCount(3, iterator_to_array($qa->get_full_step_iterator()));
        }
    }

    /**
     * When the written text was not allowed to travel, the task has the service's mark, no response, and a comment that
     * says why. When the size limit forced it out, the comment says that.
     */
    public function test_a_written_task_without_its_text_says_why(): void {
        $one = $this->make_user($this->course);
        $two = $this->make_user($this->course);

        $this->save($one, $this->attempt('att-1', [0, 0, 0, 4, 2], false, null, false));
        $this->save($two, $this->attempt('att-2', [0, 0, 0, 3, 0], false, function (array $record) {
            return ['truncated' => true] + $record;
        }, false));

        foreach (['att-1' => ['mirrortextnotsent', 12.0], 'att-2' => ['mirrortexttruncated', 9.0]] as $id => [$why, $mark]) {
            $qa = $this->qa($this->quiz_attempt($id), 'w-01');
            $this->assertEmpty($qa->get_response_summary(), $id);
            $this->assertEqualsWithDelta($mark, $qa->get_mark(), 0.0000001, $id);
            $this->assertStringContainsString(s(get_string($why, 'format_rumbos')), $qa->get_manual_comment()[0], $id);
        }
    }

    /**
     * Written tasks are graded by hand with the service's mark, scaled to what the task is worth.
     */
    public function test_a_written_task_gets_the_services_mark(): void {
        $user = $this->make_user($this->course);
        $this->write($user, 'att-1', [0, 0, 0, 4, 2]);
        $attemptobj = $this->quiz_attempt('att-1');

        // Writing is worth 15 marks over 5 points; 4 points are 12 marks. Speaking: 2 points are 6 marks.
        $this->assertEqualsWithDelta(12.0, $this->qa($attemptobj, 'w-01')->get_mark(), 0.0000001);
        $this->assertEqualsWithDelta(6.0, $this->qa($attemptobj, 's-02')->get_mark(), 0.0000001);
        $this->assertTrue($this->qa($attemptobj, 'w-01')->get_state()->is_graded());
        $this->assertEqualsWithDelta(18.0, $attemptobj->get_sum_marks(), 0.0000001);
        $this->assertStringContainsString(
            get_string('mirrormarkcomment', 'format_rumbos'),
            $this->qa($attemptobj, 'w-01')->get_manual_comment()[0]
        );
    }

    /**
     * Where the service and Moodle would grade a choice differently, the service's mark stands.
     */
    public function test_the_services_mark_overrides_moodles_automatic_one(): void {
        $user = $this->make_user($this->course);
        // The student chose a wrong option of r-01 and the service gave the point; the right one of r-02 and no point.
        $this->save($user, $this->attempt('att-1', [0, 1, 0, 0, 0], false, function (array $record) {
            foreach ($record['answers'] as &$answer) {
                if ($answer['question_id'] === 'r-01') {
                    $answer['answer'] = 'b';
                } else if ($answer['question_id'] === 'r-02') {
                    $answer['answer'] = 'c';
                }
            }
            return $record;
        }));
        $attemptobj = $this->quiz_attempt('att-1');

        $this->assertSame('second', $this->qa($attemptobj, 'r-01')->get_response_summary());
        $this->assertEqualsWithDelta(25 / 3, $this->qa($attemptobj, 'r-01')->get_mark(), 0.0000001);
        $this->assertEqualsWithDelta(0.0, $this->qa($attemptobj, 'r-02')->get_mark(), 0.0000001);
        $this->assertSame('third', $this->qa($attemptobj, 'r-02')->get_response_summary());
    }

    /**
     * A question the student left blank earned nothing and has no response.
     */
    public function test_an_unanswered_question_is_blank(): void {
        $user = $this->make_user($this->course);

        $this->save($user, $this->attempt('att-1', [0, 0, 0, 0, 0], false, function (array $record) {
            foreach ($record['answers'] as &$answer) {
                $answer['answer'] = null;
            }
            return $record;
        }));

        $qa = $this->qa($this->quiz_attempt('att-1'), 'l-01');
        $this->assertNull($qa->get_response_summary());
        $this->assertEqualsWithDelta(0.0, $qa->get_mark(), 0.0000001);
    }

    /**
     * The same attempt of the service is written once, however often it is sent.
     */
    public function test_a_resent_attempt_is_not_written_twice(): void {
        global $DB;
        $user = $this->make_user($this->course);
        $this->write($user, 'att-1', [6, 1, 3, 5, 5]);
        $attempts = $DB->count_records('quiz_attempts', ['quiz' => $this->mirror->quizid]);
        $usages = $DB->count_records('question_usages');

        $result = $this->write($user, 'att-1', [6, 1, 3, 5, 5]);

        $this->assertSame(['written' => false, 'diverged' => false], $result);
        $this->assertSame($attempts, $DB->count_records('quiz_attempts', ['quiz' => $this->mirror->quizid]));
        $this->assertSame($usages, $DB->count_records('question_usages'));
        $this->assertSame(1, $DB->count_records(mirror_store::ATTEMPTS, ['attemptid' => 'att-1']));
    }

    /**
     * A second attempt of the same student at the same exam is the second attempt of the quiz.
     */
    public function test_a_second_attempt_is_numbered_two(): void {
        $user = $this->make_user($this->course);
        $this->write($user, 'att-1', [2, 1, 1, 1, 1]);

        $this->write($user, 'att-2', [7, 3, 3, 5, 5]);

        $this->assertEquals(2, $this->quiz_attempt('att-2')->get_attempt()->attempt);
        $this->assertEquals(1, $this->quiz_attempt('att-1')->get_attempt()->attempt);
    }

    /**
     * A previous attempt of the student is never taken for a preview and deleted.
     */
    public function test_attempts_of_other_students_are_untouched(): void {
        global $DB;
        $one = $this->make_user($this->course);
        $two = $this->make_user($this->course);
        $this->write($one, 'att-1', [6, 1, 3, 5, 5]);

        $this->write($two, 'att-2', [0, 0, 0, 0, 0]);

        $this->assertSame(2, $DB->count_records('quiz_attempts', ['quiz' => $this->mirror->quizid, 'preview' => 0]));
        $first = mirror_store::find_attempt('att-1');
        $this->assertSame($one->id, $DB->get_field('quiz_attempts', 'userid', ['id' => $first->quizattemptid]));
    }

    /**
     * The total of the quiz attempt is the service's percent for values below, at and above the threshold, with the
     * rounding differences the spike measured, and the service's own decision is stored as it was sent.
     */
    public function test_the_threshold_fixtures(): void {
        $rows = [];
        foreach (mirror_fixture::threshold_cases() as $name => $section) {
            $user = $this->make_user($this->course);
            $total = mirror_fixture::total105($section);
            $expected = mirror_fixture::display($total);
            // The service's rule is strictly above 80: exactly 80 fails.
            $passed = $total > 8400;

            $result = $this->write($user, 'att-' . $name, $section, $passed);

            $row = $this->quiz_attempt('att-' . $name)->get_attempt();
            $quiz = $this->quiz_attempt('att-' . $name)->get_quiz();
            $moodle = $row->sumgrades * 100 / $quiz->sumgrades;
            $rows[$name] = sprintf(
                '%-6s %-9s exact %.6f  service %s  moodle %.5f  diff %.7f  passed %s',
                $name,
                implode('/', $section),
                $total / 105,
                $expected,
                $moodle,
                abs($moodle - $total / 105),
                $passed ? 'yes' : 'no'
            );
            $this->assertFalse($result['diverged'], $name);
            // The total is the exact percent, to the five decimals Moodle stores.
            $this->assertEqualsWithDelta($total / 105, $moodle, 0.000005, $name);
            $this->assertSame($expected, number_format(round($moodle, 2), 2, '.', ''), $name);
            $this->assertSame($passed ? '1' : '0', mirror_store::find_attempt('att-' . $name)->passed, $name);
        }
        $this->assertSame('80.00', mirror_fixture::display(mirror_fixture::total105(mirror_fixture::threshold_cases()['at'])));
        $this->assertSame('0', mirror_store::find_attempt('att-at')->passed);
        $this->assertSame('1', mirror_store::find_attempt('att-above')->passed);
        $this->assertSame('0', mirror_store::find_attempt('att-below')->passed);
        fwrite(STDERR, "\n" . implode("\n", $rows) . "\n");
    }

    /**
     * An exact percent that the marks do not add up to is flagged. The attempt is still written, with the service's
     * percent as its total: the service is right.
     */
    public function test_a_percent_that_the_points_do_not_make_is_flagged(): void {
        $user = $this->make_user($this->course);

        $result = $this->save($user, $this->attempt('att-1', [6, 1, 3, 5, 5], false, function (array $record) {
            return ['percent_exact' => '101/2'] + $record;
        }));

        $this->assertSame(['written' => true, 'diverged' => true], $result);
        $this->assertSame('1', mirror_store::find_attempt('att-1')->diverged);
        $this->assertEqualsWithDelta(50.5, $this->quiz_attempt('att-1')->get_sum_marks(), 0.00001);
    }

    /**
     * An attempt for an item the mirror quiz does not have is a transient failure and writes nothing.
     */
    public function test_an_incomplete_mapping_writes_nothing(): void {
        global $DB;
        $user = $this->make_user($this->course);
        $DB->delete_records(mirror_store::ITEMS, ['mirrorid' => $this->mirror->id, 'itemid' => 'u-03']);

        try {
            $this->write($user, 'att-1', [6, 1, 3, 5, 5]);
            $this->fail('An incomplete mapping was accepted');
        } catch (\format_rumbos\local\result_rejection $rejection) {
            $this->assertSame([503, 'exam_not_mirrored'], [$rejection->status, $rejection->reason]);
        }
        $this->assertSame(0, $DB->count_records('quiz_attempts', ['quiz' => $this->mirror->quizid]));
        $this->assertNull(mirror_store::find_attempt('att-1'));
    }

    /**
     * The mirror adds no grade, and Moodle's inclusive grade to pass could not have completed the course: with
     * exactly 80 the service says fail and neither the gradebook nor the completion moves.
     */
    public function test_exactly_at_the_threshold_completes_nothing_and_adds_no_grade(): void {
        $user = $this->make_user($this->course);
        $items = count(\grade_item::fetch_all(['courseid' => $this->course->id]));
        $at = mirror_fixture::threshold_cases()['at'];

        $this->write($user, 'att-at', $at, false);

        $quiz = $this->quiz_attempt('att-at')->get_quiz();
        $this->assertEqualsWithDelta(80.0, $this->quiz_attempt('att-at')->get_sum_marks(), 0.00001);
        $this->assertEquals(0, $quiz->grade);
        $this->assertFalse(\grade_item::fetch(['courseid' => $this->course->id, 'itemtype' => 'mod', 'itemmodule' => 'quiz']));
        $this->assertCount($items, \grade_item::fetch_all(['courseid' => $this->course->id]));
        $this->assertNull($this->grade_cents($this->course, $user));
        $this->assertFalse($this->is_complete($this->course, $user));

        // The control: a grade item with a pass mark of 80 calls 80 a pass, because Moodle's rule is inclusive. This
        // is what a quiz with a grade to pass would have done, and why the mirror has none.
        $item = new \grade_item([
            'courseid' => $this->course->id, 'itemtype' => 'manual', 'itemname' => 'control', 'gradetype' => GRADE_TYPE_VALUE,
            'grademax' => 100, 'gradepass' => 80,
        ]);
        $item->insert();
        $item->update_final_grade($user->id, 80);
        $this->assertTrue(\grade_grade::fetch(['itemid' => $item->id, 'userid' => $user->id])->is_passed($item));
    }

    /**
     * The course's grade total has the one grade, "Rumbos result", and the mirror attempts do not add to it.
     */
    public function test_the_course_total_is_not_counted_twice(): void {
        $user = $this->make_user($this->course);
        $courseitem = \grade_item::fetch_course_item($this->course->id);

        // The passback records the result as it does in production.
        $result = \format_rumbos\local\result_course::grade_item($this->course->id);
        $result->update_final_grade($user->id, 79.76);
        $this->write($user, 'att-1', [6, 1, 3, 5, 5]);
        $this->write($user, 'att-2', [7, 3, 3, 5, 5]);
        \grade_regrade_final_grades($this->course->id);

        $total = \grade_grade::fetch(['itemid' => $courseitem->id, 'userid' => $user->id]);
        $this->assertEqualsWithDelta(79.76, $total->finalgrade, 0.00001);
        $this->assertEqualsWithDelta(100.0, $courseitem->grademax, 0.00001);
    }

    /**
     * Moodle's own statistics and responses data work over mirrored attempts: facility, discrimination and the answers.
     */
    public function test_moodle_computes_its_statistics_over_the_mirror(): void {
        global $DB;
        $cases = [[7, 3, 3, 5, 5], [6, 3, 2, 4, 4], [5, 2, 2, 3, 4], [4, 2, 1, 3, 2], [3, 1, 1, 2, 2], [1, 0, 0, 1, 0]];
        foreach ($cases as $i => $section) {
            $this->write($this->make_user($this->course), 'att-' . $i, $section);
        }

        $quiz = $DB->get_record('quiz', ['id' => $this->mirror->quizid], '*', MUST_EXIST);
        $quiz->cmid = $this->mirror->cmid;
        $report = new \quiz_statistics_report();
        $questions = $report->load_and_initialise_questions_for_calculations($quiz);
        [$quizstats, $questionstats] = $report->get_all_stats_and_analysis(
            $quiz,
            $quiz->grademethod,
            \question_attempt::ALL_TRIES,
            new \core\dml\sql_join(),
            $questions
        );

        $this->assertEquals(6, $quizstats->s());
        $slots = mirror_store::items((int) $this->mirror->id);
        // The item u-01 was earned by the attempts with at least one use-of-English point: five of six.
        $this->assertEqualsWithDelta(5 / 6, $questionstats->for_slot((int) $slots['u-01']->slot)->facility, 0.0001);
        // The item u-03 was earned only by the attempt that had all three.
        $this->assertEqualsWithDelta(1 / 6, $questionstats->for_slot((int) $slots['u-03']->slot)->facility, 0.0001);
        // An item that the better students earned discriminates positively.
        $this->assertGreaterThan(0, $questionstats->for_slot((int) $slots['r-02']->slot)->discriminationindex);
        $this->assertGreaterThan(0, $quizstats->standarddeviation);
        // The answers are there for the responses report: a summary per question of every attempt.
        $row = mirror_store::find_attempt('att-0');
        $first = quiz_attempt::create((int) $row->quizattemptid);
        foreach ($first->get_slots() as $slot) {
            $this->assertNotNull($first->get_question_attempt($slot)->get_response_summary());
        }
    }

    /**
     * Writing an attempt leaves no trace of the service identity in the session.
     */
    public function test_the_current_user_is_restored(): void {
        global $USER;
        $user = $this->make_user($this->course);
        $this->setUser($user);

        $this->write($user, 'att-1', [6, 1, 3, 5, 5]);

        $this->assertEquals($user->id, $USER->id);
    }
}
