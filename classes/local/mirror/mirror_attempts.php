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

use format_rumbos\local\result_rejection;
use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;

/**
 * Writes a finished native quiz attempt for an attempt of the Rumbos service.
 *
 * The student never took this quiz in Moodle. The attempt is written the way Moodle's own tests and web services do it
 * (create, start, save the answers, submit, grade), so that it has real question attempt steps and every report that
 * reads them works. Then the marks are made to be the service's: where Moodle's automatic mark differs, and always for a
 * written task, a manual grade sets the service's mark. The attempt's total is the service's exact percent, and it is
 * never what decides anything: not the pass (the service sends `passed`), not the course completion (only the
 * passback marks it).
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mirror_attempts {
    /** @var float How far a mark may be from the service's before a manual grade replaces it. */
    private const MARK_TOLERANCE = 0.00000005;

    /** @var float How far the sum of the marks may be from the exact percent: the rounding of up to 300 marks. */
    private const TOTAL_TOLERANCE = 0.0001;

    /**
     * Writes the attempt, once.
     *
     * Everything is one database transaction: when anything fails nothing is left behind and the caller answers a
     * transient error so that the service sends the attempt again.
     *
     * @param \stdClass $mirror The row of the mirror quiz.
     * @param exam $exam The exam the attempt was taken on.
     * @param attempt_data $attempt
     * @param int $courseid
     * @param int $userid The student, already checked.
     * @param int $now
     * @return array{written: bool, diverged: bool} Whether this call wrote it (false: it was written before) and whether
     *      the marks of the questions did not add up to the service's exact percent.
     * @throws result_rejection 422 when the attempt does not fit the exam, 503 when the quiz is not complete.
     */
    public static function write(
        \stdClass $mirror,
        exam $exam,
        attempt_data $attempt,
        int $courseid,
        int $userid,
        int $now
    ): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        if (mirror_store::find_attempt($attempt->attemptid) !== null) {
            return ['written' => false, 'diverged' => false];
        }
        $attempt->check_against($exam);
        $items = mirror_store::items((int) $mirror->id);
        if (array_diff(array_keys($attempt->entries), array_keys($items)) !== []) {
            // The mapping misses an item: the quiz is not what the exam says. The definition has to come again.
            throw new result_rejection(503, 'exam_not_mirrored');
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            $diverged = service_identity::run(function () use ($mirror, $exam, $attempt, $items, $courseid, $userid, $now) {
                [$id, $differs] = self::create($mirror, $exam, $attempt, $items, $userid);
                mirror_store::insert_attempt((int) $mirror->id, $attempt, $courseid, $userid, $id, $differs, $now);
                return $differs;
            });
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // Nothing is half-written, and the database is usable again before anybody logs or reads.
            try {
                $transaction->rollback($e);
            } catch (\Throwable $rolledback) {
                $e = $rolledback;
            }
            if ($e instanceof \dml_write_exception && mirror_store::find_attempt($attempt->attemptid) !== null) {
                // The same attempt arrived twice at the same moment and the other request wrote it.
                return ['written' => false, 'diverged' => false];
            }
            throw $e;
        }
        return ['written' => true, 'diverged' => $diverged];
    }

    /**
     * Creates, answers, submits and grades the attempt, then sets the service's marks.
     *
     * @param \stdClass $mirror
     * @param exam $exam
     * @param attempt_data $attempt
     * @param \stdClass[] $items The mapping of the quiz by question id.
     * @param int $userid
     * @return array{0: int, 1: bool} The id of the quiz attempt, and whether its marks did not add up.
     */
    private static function create(
        \stdClass $mirror,
        exam $exam,
        attempt_data $attempt,
        array $items,
        int $userid
    ): array {
        global $DB;

        $quizobj = quiz_settings::create((int) $mirror->quizid, $userid);
        $quiz = $quizobj->get_quiz();

        $previous = quiz_get_user_attempts($quiz->id, $userid, 'all', true);
        $last = $previous ? end($previous) : false;
        $number = $last ? $last->attempt + 1 : 1;

        $quba = \question_engine::make_questions_usage_by_activity('mod_quiz', $quizobj->get_context());
        $quba->set_preferred_behaviour($quiz->preferredbehaviour);
        // Never a preview: the attempt counts as the student's own.
        $row = quiz_create_attempt($quizobj, $number, $last, $attempt->finished, false, $userid);
        quiz_start_new_attempt($quizobj, $quba, $row, $number, $attempt->finished);
        quiz_attempt_save_started($quizobj, $quba, $row);
        // The statement does not say when the student began, so the attempt has no duration.
        $DB->set_field('quiz_attempts', 'timestart', $attempt->finished, ['id' => $row->id]);

        $attemptobj = quiz_attempt::create($row->id);
        $post = self::answers($attemptobj, $exam, $items, $attempt);
        $attemptobj->process_submitted_actions($attempt->finished, false, $post);
        $attemptobj = quiz_attempt::create($row->id);
        $attemptobj->process_submit($attempt->finished, false, $attempt->finished);
        $attemptobj = quiz_attempt::create($row->id);
        $attemptobj->process_grade_submission($attempt->finished);

        $diverged = self::set_marks(quiz_attempt::create($row->id), $items, $attempt);
        return [(int) $row->id, $diverged];
    }

    /**
     * The form data that a student's browser would have sent for these answers.
     *
     * @param quiz_attempt $attemptobj
     * @param exam $exam
     * @param \stdClass[] $items The mapping by question id.
     * @param attempt_data $attempt
     * @return array Field name => value, with the question prefix.
     */
    private static function answers(quiz_attempt $attemptobj, exam $exam, array $items, attempt_data $attempt): array {
        $idbyslot = [];
        foreach ($items as $questionid => $item) {
            $idbyslot[(int) $item->slot] = (string) $questionid;
        }

        $post = [];
        foreach ($attemptobj->get_slots() as $slot) {
            $qa = $attemptobj->get_question_attempt($slot);
            $prefix = $qa->get_field_prefix();
            $post[$prefix . ':sequencecheck'] = $qa->get_sequence_check_count();
            $id = $idbyslot[$slot];
            foreach (self::fields($exam->question_by_id($id), $attempt->entries[$id]) as $name => $value) {
                $post[$prefix . $name] = $value;
            }
        }
        return $post;
    }

    /**
     * The fields of one question for what the student answered.
     *
     * @param array $question The exam's question.
     * @param array $entry The attempt's entry for it.
     * @return array Empty when there is no answer to record.
     */
    private static function fields(array $question, array $entry): array {
        if (!$entry['written']) {
            if ($entry['answer'] === null) {
                return [];
            }
            // The answers are not shuffled, so the position of an answer is the position of its option.
            return ['answer' => (string) array_search($entry['answer'], array_column($question['options'], 'id'), true)];
        }
        if ($entry['text'] === null) {
            return [];
        }
        $labels = $question['written']['questions'] + $question['written']['fields'];
        $lines = [];
        foreach ($entry['text'] as $name => $value) {
            // Moodle has one box for all the boxes the student saw: each is named, unless there was only one.
            $lines[] = count($entry['text']) === 1 ? $value : ($labels[$name] ?? $name) . ': ' . $value;
        }
        return ['answer' => implode("\n\n", $lines), 'answerformat' => FORMAT_PLAIN];
    }

    /**
     * Makes every mark the service's, and the attempt's total its exact percent.
     *
     * A question is worth its share of 100 and earns `points / max_points` of it. Where Moodle's own mark differs, and
     * always for a written task, a manual grade sets the service's. The total is then taken from the exact percent, not
     * from the sum of the rounded marks.
     *
     * @param quiz_attempt $attemptobj A graded attempt.
     * @param \stdClass[] $items The mapping by question id.
     * @param attempt_data $attempt
     * @return bool Whether the marks of the questions did not add up to the exact percent.
     */
    private static function set_marks(quiz_attempt $attemptobj, array $items, attempt_data $attempt): bool {
        global $DB;

        $quba = \question_engine::load_questions_usage_by_activity((int) $attemptobj->get_attempt()->uniqueid);
        $changed = false;
        foreach ($items as $questionid => $item) {
            $entry = $attempt->entries[$questionid];
            $expected = round($item->maxmark * $entry['points'] / $item->units, 7);
            $qa = $quba->get_question_attempt((int) $item->slot);
            $have = $qa->get_mark();
            if ($have !== null && abs($have - $expected) <= self::MARK_TOLERANCE) {
                continue;
            }
            $comment = 'mirrormarkcomment';
            if ($entry['written'] && $entry['text'] === null && !$attempt->textincluded) {
                $comment = $attempt->truncated ? 'mirrortexttruncated' : 'mirrortextnotsent';
            }
            $qa->manual_grade(s(get_string($comment, 'format_rumbos')), $expected, FORMAT_HTML, $attempt->finished);
            $changed = true;
        }
        if ($changed) {
            \question_engine::save_questions_usage_by_activity($quba);
        }

        $row = $attemptobj->get_attempt();
        $quizsum = (float) $attemptobj->get_quizobj()->get_quiz()->sumgrades;
        $total = $attempt->exact * $quizsum / 100;
        $diverged = $quizsum <= 0 || abs((float) $quba->get_total_mark() - $total) > self::TOTAL_TOLERANCE;
        $DB->set_field('quiz_attempts', 'sumgrades', round($total, 5), ['id' => $row->id]);
        $DB->set_field('quiz_attempts', 'timemodified', $attempt->finished, ['id' => $row->id]);
        $attemptobj->get_quizobj()->get_grade_calculator()->recompute_final_grade((int) $row->userid);
        return $diverged;
    }
}
