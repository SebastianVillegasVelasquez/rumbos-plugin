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
 * written task, a manual grade sets the service's mark. Moodle's total is therefore a copy of the service's percent and
 * is never what decides anything: not the pass (the service sends `passed`), not the course completion (only the
 * passback marks it).
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mirror_attempts {
    /** @var float How far a mark may be from the service's before a manual grade replaces it. */
    private const MARK_TOLERANCE = 0.00000005;

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
     *      the quiz total did not equal the service's percent.
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
        $resolved = $attempt->resolve($exam);
        $items = mirror_store::items((int) $mirror->id);
        if (array_diff(array_keys($resolved), array_keys($items)) !== []) {
            // The mapping misses an item: the quiz is not what the exam says. The definition has to come again.
            throw new result_rejection(503, 'exam_not_mirrored');
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            $diverged = service_identity::run(function () use (
                $mirror,
                $exam,
                $attempt,
                $resolved,
                $items,
                $courseid,
                $userid,
                $now
            ) {
                $quizattemptid = self::create($mirror, $exam, $attempt, $resolved, $items, $userid);
                $diverged = self::total_differs($quizattemptid, $attempt->percent);
                mirror_store::insert_attempt((int) $mirror->id, $attempt, $courseid, $userid, $quizattemptid, $diverged, $now);
                return $diverged;
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
     * @param array $resolved Item id => `points`, `response` and `comment` (see {@see attempt_data::resolve()}).
     * @param \stdClass[] $items The mapping of the quiz by item id.
     * @param int $userid
     * @return int The id of the quiz attempt.
     */
    private static function create(
        \stdClass $mirror,
        exam $exam,
        attempt_data $attempt,
        array $resolved,
        array $items,
        int $userid
    ): int {
        global $DB;

        $quizobj = quiz_settings::create((int) $mirror->quizid, $userid);
        $quiz = $quizobj->get_quiz();

        $previous = quiz_get_user_attempts($quiz->id, $userid, 'all', true);
        $last = $previous ? end($previous) : false;
        $number = $last ? $last->attempt + 1 : 1;

        $quba = \question_engine::make_questions_usage_by_activity('mod_quiz', $quizobj->get_context());
        $quba->set_preferred_behaviour($quiz->preferredbehaviour);
        // Never a preview: the attempt counts as the student's own.
        $row = quiz_create_attempt($quizobj, $number, $last, $attempt->started, false, $userid);
        quiz_start_new_attempt($quizobj, $quba, $row, $number, $attempt->started);
        quiz_attempt_save_started($quizobj, $quba, $row);
        // Moodle stamps the start with the time of the call: the attempt began when the student began.
        $DB->set_field('quiz_attempts', 'timestart', $attempt->started, ['id' => $row->id]);

        $attemptobj = quiz_attempt::create($row->id);
        $post = self::answers($attemptobj, $exam, $items, $resolved);
        $attemptobj->process_submitted_actions($attempt->finished, false, $post);
        $attemptobj = quiz_attempt::create($row->id);
        $attemptobj->process_submit($attempt->finished, false, $attempt->finished);
        $attemptobj = quiz_attempt::create($row->id);
        $attemptobj->process_grade_submission($attempt->finished);

        self::set_marks(quiz_attempt::create($row->id), $items, $resolved, $attempt);
        return (int) $row->id;
    }

    /**
     * The form data that a student's browser would have sent for these answers.
     *
     * @param quiz_attempt $attemptobj
     * @param exam $exam
     * @param \stdClass[] $items The mapping by item id.
     * @param array $resolved
     * @return array Field name => value, with the question prefix.
     */
    private static function answers(quiz_attempt $attemptobj, exam $exam, array $items, array $resolved): array {
        $itembyslot = [];
        foreach ($items as $itemid => $item) {
            $itembyslot[(int) $item->slot] = $itemid;
        }

        $post = [];
        foreach ($attemptobj->get_slots() as $slot) {
            $qa = $attemptobj->get_question_attempt($slot);
            $prefix = $qa->get_field_prefix();
            $post[$prefix . ':sequencecheck'] = $qa->get_sequence_check_count();
            $itemid = $itembyslot[$slot];
            $response = $resolved[$itemid]['response'];
            if ($response === null) {
                continue;
            }
            foreach (self::fields($qa->get_question(), $exam->item($itemid), $response) as $name => $value) {
                $post[$prefix . $name] = $value;
            }
        }
        return $post;
    }

    /**
     * The fields of one question for a response.
     *
     * @param \question_definition $question The question as Moodle loaded it for the attempt.
     * @param array $item The exam's item.
     * @param string|array $response A choice: the option id. A match: clip index => place id. A written task: the text.
     * @return array
     */
    private static function fields(\question_definition $question, array $item, string|array $response): array {
        if ($question instanceof \qtype_multichoice_single_question) {
            // The answers are not shuffled, so the position of an answer is the position of its option.
            return ['answer' => (string) array_search($response, array_column($item['options'], 'id'), true)];
        }
        if ($question instanceof \qtype_match_question) {
            $fields = [];
            $texts = array_column($item['places'], 'text', 'id');
            $stemorder = $question->get_stem_order();
            $choiceorder = $question->get_choice_order();
            $stemids = array_keys($question->stems);
            foreach ($response as $clip => $placeid) {
                $position = array_search($stemids[$clip] ?? null, $stemorder);
                $choiceno = array_search($texts[$placeid] ?? null, $question->choices, true);
                $selected = array_search($choiceno, $choiceorder);
                if ($position === false || $choiceno === false || $selected === false) {
                    throw new result_rejection(422, 'invalid_attempt:' . $item['item_id'] . '.response');
                }
                $fields['sub' . $position] = (string) $selected;
            }
            return $fields;
        }
        if ($question instanceof \qtype_essay_question) {
            return ['answer' => $response, 'answerformat' => FORMAT_PLAIN];
        }
        throw new \coding_exception('Unexpected question type in a mirror quiz: ' . get_class($question));
    }

    /**
     * Makes every mark the service's.
     *
     * @param quiz_attempt $attemptobj A graded attempt.
     * @param \stdClass[] $items The mapping by item id.
     * @param array $resolved
     * @param attempt_data $attempt
     */
    private static function set_marks(quiz_attempt $attemptobj, array $items, array $resolved, attempt_data $attempt): void {
        global $DB;

        $quba = \question_engine::load_questions_usage_by_activity((int) $attemptobj->get_attempt()->uniqueid);
        $changed = false;
        foreach ($items as $itemid => $item) {
            $expected = round($item->maxmark * $resolved[$itemid]['points'] / $item->units, 7);
            $qa = $quba->get_question_attempt((int) $item->slot);
            $have = $qa->get_mark();
            if ($have !== null && abs($have - $expected) <= self::MARK_TOLERANCE) {
                continue;
            }
            $comment = $resolved[$itemid]['comment'] ?? get_string('mirrormarkcomment', 'format_rumbos');
            $qa->manual_grade(s($comment), $expected, FORMAT_HTML, $attempt->finished);
            $changed = true;
        }
        if (!$changed) {
            return;
        }
        \question_engine::save_questions_usage_by_activity($quba);
        $row = $attemptobj->get_attempt();
        $DB->set_field('quiz_attempts', 'sumgrades', $quba->get_total_mark(), ['id' => $row->id]);
        $DB->set_field('quiz_attempts', 'timemodified', $attempt->finished, ['id' => $row->id]);
        $attemptobj->get_quizobj()->get_grade_calculator()->recompute_final_grade((int) $row->userid);
    }

    /**
     * Whether the total of a quiz attempt, as a percentage rounded to two decimals, is not the service's percent.
     *
     * @param int $quizattemptid
     * @param string $percent The service's percent.
     * @return bool
     */
    private static function total_differs(int $quizattemptid, string $percent): bool {
        global $DB;

        $sumgrades = $DB->get_field('quiz_attempts', 'sumgrades', ['id' => $quizattemptid]);
        $quizsum = $DB->get_field_sql(
            'SELECT q.sumgrades FROM {quiz} q JOIN {quiz_attempts} qa ON qa.quiz = q.id WHERE qa.id = ?',
            [$quizattemptid]
        );
        if ($sumgrades === null || (float) $quizsum <= 0) {
            return true;
        }
        $moodle = number_format(round((float) $sumgrades * 100 / (float) $quizsum, 2), 2, '.', '');
        return $moodle !== $percent;
    }
}
