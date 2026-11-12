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

use core\context\course as context_course;
use core\context\module as context_module;
use mod_quiz\question\display_options;

/**
 * What a student may be told about their own results in a quiz.
 *
 * The result goes to the student's own browser, so the rule is simple: never more than Moodle itself would show
 * that student. The grade is given only when ALL of these hold, and otherwise {@see self::result()} reports it
 * as null:
 *  - the course shows grades to students (the course setting) and the user may view their grades;
 *  - the grade is not hidden in the gradebook: neither the item, nor the grade, nor a "hidden until" date;
 *  - the quiz's own review options show the marks for every finished attempt (the same test the quiz page
 *    makes before it prints "Your final grade");
 *  - the quiz is graded, and the user has a finished attempt.
 *
 * The grade itself is the one the gradebook holds: it already follows the grading method of the quiz (highest,
 * average, first or last attempt) and any override a teacher made. Attempt states count as the quiz does:
 * only the "finished" state is a finished attempt; "in progress" and "overdue" mean one is open; "abandoned"
 * attempts and previews are ignored.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_result {
    /** @var string The activity does not exist, is not a quiz in that course, or the user cannot see it. */
    public const ERROR_NOT_FOUND = 'not_found';

    /** @var string The user may not view the quiz. */
    public const ERROR_CAPABILITY = 'capability';

    /**
     * The result of a user in a quiz.
     *
     * @param int $courseid
     * @param int $cmid
     * @param int $userid The current user: it is never taken from the client.
     * @return array {error: string, result: ?array}; error is empty when there is a result.
     */
    public static function for_user(int $courseid, int $cmid, int $userid): array {
        global $CFG;

        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->libdir . '/gradelib.php');

        try {
            [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'quiz', $courseid, $userid);
        } catch (\moodle_exception $e) {
            return ['error' => self::ERROR_NOT_FOUND, 'result' => null];
        }
        if (!empty($cm->deletioninprogress)) {
            return ['error' => self::ERROR_NOT_FOUND, 'result' => null];
        }
        // Before the visibility test: Moodle counts a missing view capability as "not visible" too.
        if (!has_capability('mod/quiz:view', context_module::instance($cm->id), $userid)) {
            return ['error' => self::ERROR_CAPABILITY, 'result' => null];
        }
        if (!$cm->uservisible) {
            return ['error' => self::ERROR_NOT_FOUND, 'result' => null];
        }

        return ['error' => '', 'result' => self::compute($course, $cm, $userid)];
    }

    /**
     * Builds the QuizResult of the contract.
     *
     * @param \stdClass $course
     * @param \cm_info $cm
     * @param int $userid
     * @return array attempts, finishedAttempts, inProgress, percent, pendingGrading, passedThreshold.
     */
    private static function compute(\stdClass $course, \cm_info $cm, int $userid): array {
        global $DB;

        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
        $attempts = array_filter(
            quiz_get_user_attempts($quiz->id, $userid, 'all', false),
            fn($attempt) => $attempt->state !== \mod_quiz\quiz_attempt::NOT_STARTED
        );

        $finished = [];
        $inprogress = false;
        $pending = false;
        foreach ($attempts as $attempt) {
            switch ($attempt->state) {
                case \mod_quiz\quiz_attempt::FINISHED:
                    $finished[] = $attempt;
                    // A finished attempt with no mark is waiting for a teacher to grade some question.
                    $pending = $pending || $attempt->sumgrades === null;
                    break;
                case \mod_quiz\quiz_attempt::IN_PROGRESS:
                case \mod_quiz\quiz_attempt::OVERDUE:
                    $inprogress = true;
                    break;
                case \mod_quiz\quiz_attempt::SUBMITTED:
                    // Handed in but not yet processed: not finished, and the grade is not there yet.
                    $pending = true;
                    break;
            }
        }

        $percent = $finished ? self::visible_percent($course, $quiz, $userid, $finished) : null;
        $threshold = flow_config::get_quiz_threshold((int) $course->id);
        $passed = null;
        if ($threshold > 0 && $percent !== null && !$pending) {
            $passed = $percent >= $threshold;
        }

        return [
            'attempts' => count($attempts),
            'finishedAttempts' => count($finished),
            'inProgress' => $inprogress,
            'percent' => $percent,
            'pendingGrading' => $pending,
            'passedThreshold' => $passed,
        ];
    }

    /**
     * The grade as a percentage, or null when Moodle would not show it to the student.
     *
     * @param \stdClass $course
     * @param \stdClass $quiz
     * @param int $userid
     * @param \stdClass[] $finished The user's finished attempts; not empty.
     * @return float|null 0 to 100, with two decimals.
     */
    private static function visible_percent(\stdClass $course, \stdClass $quiz, int $userid, array $finished): ?float {
        if (empty($course->showgrades) || !has_capability('moodle/grade:view', context_course::instance($course->id), $userid)) {
            return null;
        }
        if (!quiz_has_grades($quiz)) {
            return null;
        }

        // The marks must be showable for every finished attempt, as the quiz page requires for the final grade.
        [, $alloptions] = quiz_get_combined_reviewoptions($quiz, $finished);
        if ($alloptions->marks < display_options::MARK_AND_MAX) {
            return null;
        }

        $grades = grade_get_grades($course->id, 'mod', 'quiz', $quiz->id, $userid);
        $item = $grades->items[0] ?? null;
        $grade = $item->grades[$userid] ?? null;
        if (!$item || !$grade || $item->hidden || $grade->hidden) {
            return null;
        }
        // Null when there is no grade yet, false when the gradebook failed to compute it.
        if ($grade->grade === null || $grade->grade === false) {
            return null;
        }
        $range = (float) $item->grademax - (float) $item->grademin;
        if ($range <= 0) {
            return null;
        }
        $percent = ((float) $grade->grade - (float) $item->grademin) / $range * 100;
        return round(min(100.0, max(0.0, $percent)), 2);
    }
}
