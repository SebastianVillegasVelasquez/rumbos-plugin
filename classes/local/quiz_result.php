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
 * attempts are ignored. A preview (an attempt of somebody who holds mod/quiz:preview, which Moodle flags in
 * quiz_attempts.preview) is never a real attempt: it is not counted as one and it never reaches the gradebook,
 * but it is counted apart in "previewAttempts", so that the result can say why a user who "finished" the quiz
 * has no finished attempt.
 *
 * Besides the numbers the result carries a "reason" (see {@see self::reason()}) that tells the app, and whoever
 * gives support, why the result is what it is. Everything is derived when it is read; nothing is stored.
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

    /** @var string The user has no mod/quiz:attempt in the activity: whatever they do there is not an attempt. */
    public const REASON_CANNOT_ATTEMPT = 'cannot_attempt';

    /** @var string At least one real attempt is finished and has its mark. */
    public const REASON_OK = 'ok';

    /** @var string A real attempt is open (in progress or overdue) and none is finished. */
    public const REASON_IN_PROGRESS = 'in_progress';

    /** @var string The finished attempts all wait for manual grading, or one was handed in and is not processed. */
    public const REASON_NOT_GRADED_YET = 'not_graded_yet';

    /** @var string The user has previews and nothing else. */
    public const REASON_PREVIEW_ONLY = 'preview_only';

    /** @var string There is no real attempt that is finished, open or waiting for grading. */
    public const REASON_NO_ATTEMPTS = 'no_attempts';

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
     * What the support person needs to know about why a user's quiz result is what it is.
     *
     * It is about ANOTHER user, so it is for people who may see other users' grades or edit the course: the viewer
     * needs moodle/grade:viewall or format/rumbos:editmap in the course, or this throws. It reads only; nothing is
     * logged or stored.
     *
     * @param int $courseid
     * @param int $cmid
     * @param int $subjectid The user the question is about.
     * @param int $viewerid The user who asks.
     * @return array {error: string, check: ?array}; error is empty when there is a check. The check holds the access
     *      of the user to the quiz, the attempts by state, whether the grade is hidden, why a percentage is withheld
     *      (empty when it is not), the reason, and "contract": what {@see self::for_user()} answers for that user.
     *      Capabilities are the user's own, so a role switched in a browser session is not seen from here.
     * @throws \required_capability_exception When the viewer may neither see grades nor edit the course.
     */
    public static function diagnose(int $courseid, int $cmid, int $subjectid, int $viewerid): array {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->libdir . '/gradelib.php');

        $coursecontext = context_course::instance($courseid);
        if (!has_any_capability(['moodle/grade:viewall', 'format/rumbos:editmap'], $coursecontext, $viewerid)) {
            throw new \required_capability_exception($coursecontext, 'moodle/grade:viewall', 'nopermissions', '');
        }
        try {
            [$course, $viewercm] = get_course_and_cm_from_cmid($cmid, 'quiz', $courseid, $viewerid);
        } catch (\moodle_exception $e) {
            return ['error' => self::ERROR_NOT_FOUND, 'check' => null];
        }
        $context = context_module::instance($viewercm->id);
        $quiz = $DB->get_record('quiz', ['id' => $viewercm->instance], '*', MUST_EXIST);
        $subjectcm = get_fast_modinfo($course, $subjectid)->get_cm($viewercm->id);

        $tally = self::tally((int) $quiz->id, $subjectid);
        $canattempt = has_capability('mod/quiz:attempt', $context, $subjectid);
        [$item, $grade] = self::gradebook_entry($course, $quiz, $subjectid);
        $withheld = $tally['finished'] ?
            self::percent($course, $quiz, $subjectid, $tally['finishedrows'])[1] : 'no_finished_attempt';

        return ['error' => '', 'check' => [
            'quiz' => (int) $quiz->id,
            'enrolled' => is_enrolled($coursecontext, $subjectid, '', true),
            'canView' => has_capability('mod/quiz:view', $context, $subjectid),
            'visible' => (bool) $subjectcm->uservisible,
            'canAttempt' => $canattempt,
            'canPreview' => has_capability('mod/quiz:preview', $context, $subjectid),
            'counts' => [
                'finished' => $tally['finished'],
                'inprogress' => $tally['inprogress'],
                'overdue' => $tally['overdue'],
                'submitted' => $tally['submitted'],
                'abandoned' => $tally['abandoned'],
                'preview' => $tally['previews'],
            ],
            'pendingGrading' => $tally['pending'],
            'hiddenGrade' => (bool) ($item && $item->hidden) || (bool) ($grade && $grade->hidden),
            'percentWithheldBy' => $withheld,
            'reason' => self::reason($canattempt, $tally),
            'contract' => self::for_user($courseid, $cmid, $subjectid),
        ]];
    }

    /**
     * Builds the QuizResult of the contract.
     *
     * @param \stdClass $course
     * @param \cm_info $cm
     * @param int $userid
     * @return array attempts, finishedAttempts, inProgress, percent, pendingGrading, passedThreshold (the contract up
     *      to v5.8, unchanged) and previewAttempts, canAttempt, reason (v5.9).
     */
    private static function compute(\stdClass $course, \cm_info $cm, int $userid): array {
        global $DB;

        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
        $tally = self::tally((int) $quiz->id, $userid);
        $canattempt = has_capability('mod/quiz:attempt', context_module::instance($cm->id), $userid);

        $percent = $tally['finished'] ? self::percent($course, $quiz, $userid, $tally['finishedrows'])[0] : null;
        $threshold = flow_config::get_quiz_threshold((int) $course->id);
        $passed = null;
        if ($threshold > 0 && $percent !== null && !$tally['pending']) {
            $passed = $percent >= $threshold;
        }

        return [
            'attempts' => $tally['attempts'],
            'finishedAttempts' => $tally['finished'],
            'inProgress' => $tally['inprogress'] + $tally['overdue'] > 0,
            'percent' => $percent,
            'pendingGrading' => $tally['pending'],
            'passedThreshold' => $passed,
            'previewAttempts' => $tally['previews'],
            'canAttempt' => $canattempt,
            'reason' => self::reason($canattempt, $tally),
        ];
    }

    /**
     * Counts the attempts of a user in a quiz by what they are.
     *
     * Real attempts and previews are kept apart; an attempt that was never started is neither.
     *
     * @param int $quizid
     * @param int $userid
     * @return array attempts (real attempts, not counting the unstarted), finished, pendingfinished (finished with no
     *      mark yet), inprogress, overdue, submitted, abandoned, previews, pending (the flag of the contract) and
     *      finishedrows (the finished real attempts).
     */
    private static function tally(int $quizid, int $userid): array {
        $tally = [
            'attempts' => 0, 'finished' => 0, 'pendingfinished' => 0, 'inprogress' => 0, 'overdue' => 0,
            'submitted' => 0, 'abandoned' => 0, 'previews' => 0, 'pending' => false, 'finishedrows' => [],
        ];
        foreach (quiz_get_user_attempts($quizid, $userid, 'all', true) as $attempt) {
            if ($attempt->state === \mod_quiz\quiz_attempt::NOT_STARTED) {
                continue;
            }
            if (!empty($attempt->preview)) {
                $tally['previews']++;
                continue;
            }
            $tally['attempts']++;
            switch ($attempt->state) {
                case \mod_quiz\quiz_attempt::FINISHED:
                    $tally['finished']++;
                    $tally['finishedrows'][] = $attempt;
                    // A finished attempt with no mark is waiting for a teacher to grade some question.
                    if ($attempt->sumgrades === null) {
                        $tally['pendingfinished']++;
                        $tally['pending'] = true;
                    }
                    break;
                case \mod_quiz\quiz_attempt::IN_PROGRESS:
                    $tally['inprogress']++;
                    break;
                case \mod_quiz\quiz_attempt::OVERDUE:
                    $tally['overdue']++;
                    break;
                case \mod_quiz\quiz_attempt::SUBMITTED:
                    // Handed in but not yet processed: not finished, and the grade is not there yet.
                    $tally['submitted']++;
                    $tally['pending'] = true;
                    break;
                case \mod_quiz\quiz_attempt::ABANDONED:
                    $tally['abandoned']++;
                    break;
            }
        }
        return $tally;
    }

    /**
     * Why the result is what it is: the first of these that holds.
     *
     *  1. cannot_attempt: the user has no mod/quiz:attempt in the activity (a teacher, a manager, a role without it);
     *  2. ok: a real attempt is finished and has its mark;
     *  3. in_progress: a real attempt is open (in progress or overdue);
     *  4. not_graded_yet: the finished real attempts all wait for manual grading, or one was handed in and is not
     *     processed yet;
     *  5. preview_only: the user has previews and nothing else;
     *  6. no_attempts: none of the above, which includes a user whose only attempts were abandoned.
     *
     * The reason says nothing about the grade: "ok" can come with a null percentage (a hidden grade).
     *
     * @param bool $canattempt Whether the user holds mod/quiz:attempt in the activity.
     * @param array $tally What {@see self::tally()} returned.
     * @return string One of the REASON_ constants.
     */
    public static function reason(bool $canattempt, array $tally): string {
        if (!$canattempt) {
            return self::REASON_CANNOT_ATTEMPT;
        }
        if ($tally['finished'] > $tally['pendingfinished']) {
            return self::REASON_OK;
        }
        if ($tally['inprogress'] + $tally['overdue'] > 0) {
            return self::REASON_IN_PROGRESS;
        }
        if ($tally['finished'] + $tally['submitted'] > 0) {
            return self::REASON_NOT_GRADED_YET;
        }
        if ($tally['previews'] > 0) {
            return self::REASON_PREVIEW_ONLY;
        }
        return self::REASON_NO_ATTEMPTS;
    }

    /**
     * The gradebook item of the quiz and the user's grade in it.
     *
     * @param \stdClass $course
     * @param \stdClass $quiz
     * @param int $userid
     * @return array [item, grade]; either can be null.
     */
    private static function gradebook_entry(\stdClass $course, \stdClass $quiz, int $userid): array {
        $grades = grade_get_grades($course->id, 'mod', 'quiz', $quiz->id, $userid);
        $item = $grades->items[0] ?? null;
        return [$item, $item->grades[$userid] ?? null];
    }

    /**
     * The grade as a percentage, or null when Moodle would not show it to the student.
     *
     * @param \stdClass $course
     * @param \stdClass $quiz
     * @param int $userid
     * @param \stdClass[] $finished The user's finished attempts; not empty.
     * @return array [percent, withheldby]: percent is 0 to 100 with two decimals, or null; withheldby is empty
     *      when there is a percentage, else the first check that held it back (for support, never sent to the app).
     */
    private static function percent(\stdClass $course, \stdClass $quiz, int $userid, array $finished): array {
        if (empty($course->showgrades)) {
            return [null, 'course_hides_grades'];
        }
        if (!has_capability('moodle/grade:view', context_course::instance($course->id), $userid)) {
            return [null, 'cannot_view_grades'];
        }
        if (!quiz_has_grades($quiz)) {
            return [null, 'quiz_ungraded'];
        }

        // The marks must be showable for every finished attempt, as the quiz page requires for the final grade.
        [, $alloptions] = quiz_get_combined_reviewoptions($quiz, $finished);
        if ($alloptions->marks < display_options::MARK_AND_MAX) {
            return [null, 'review_options_hide_marks'];
        }

        [$item, $grade] = self::gradebook_entry($course, $quiz, $userid);
        if (!$item || !$grade) {
            return [null, 'no_grade_yet'];
        }
        if ($item->hidden || $grade->hidden) {
            return [null, 'grade_hidden'];
        }
        // Null when there is no grade yet, false when the gradebook failed to compute it.
        if ($grade->grade === null || $grade->grade === false) {
            return [null, 'no_grade_yet'];
        }
        $range = (float) $item->grademax - (float) $item->grademin;
        if ($range <= 0) {
            return [null, 'no_grade_range'];
        }
        $percent = ((float) $grade->grade - (float) $item->grademin) / $range * 100;
        return [round(min(100.0, max(0.0, $percent)), 2), ''];
    }
}
