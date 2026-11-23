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

use mod_quiz\quiz_settings;

/**
 * Makes, finds and locks the native quiz that mirrors one version of a Rumbos exam.
 *
 * The rules that make the mirror safe are all here:
 *
 * - **One quiz per version.** A new content version of a form gets a new quiz with its own questions. Moodle does not
 *   let questions change under existing attempts, so an old attempt keeps the questions it was taken on.
 * - **Pinned questions.** Every slot is pinned to the version of the question that was made, so a teacher who edits
 *   the question in the bank cannot change the quiz or its statistics.
 * - **No second grade.** The quiz has a maximum grade of 0, which makes Moodle create no grade item for it at all. The
 *   one grade of the course stays the "Rumbos result" item of the passback. The marks of the attempts are still there
 *   for the statistics and the responses report. See docs/NATIVE-QUIZ-MIRROR-SPIKE.md for the measurements.
 * - **No grade to pass, no completion.** Moodle's pass mark is inclusive and the service's is strict, so the quiz has
 *   neither a pass mark nor a completion rule: only the passback completes a course.
 * - **Hidden and not attemptable.** The quiz is hidden from students and the capabilities to view and attempt it are
 *   prohibited for the student roles, and both are applied again every time the quiz is used (a restored copy of a
 *   course does not bring the permission overrides back).
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mirror_quiz {
    /** @var string[] The role archetypes that must never take a mirror quiz. */
    public const LOCKED_ARCHETYPES = ['student', 'guest'];

    /** @var string[] The capabilities prohibited for them in the quiz. */
    public const LOCKED_CAPABILITIES = ['mod/quiz:attempt', 'mod/quiz:view', 'mod/quiz:reviewmyattempts'];

    /**
     * The mirror quiz of an exam version in a course, made when it is not there.
     *
     * A mapping whose quiz was deleted is dropped and the quiz is made again. A quiz that exists is locked again.
     *
     * @param \stdClass $course The course (a row of `course`).
     * @param exam $exam
     * @param int $now
     * @return array{0: \stdClass, 1: bool} The row of the mapping and whether the quiz was made by this call.
     */
    public static function ensure(\stdClass $course, exam $exam, int $now): array {
        global $DB;

        $row = mirror_store::find_quiz((int) $course->id, $exam->formid, $exam->version);
        if ($row !== null) {
            if (self::exists($row)) {
                self::lock((int) $row->cmid);
                return [$row, false];
            }
            // The quiz was deleted behind the plugin's back: forget it and make it again.
            mirror_store::delete_quiz((int) $row->id);
        }

        try {
            $transaction = $DB->start_delegated_transaction();
            $row = self::make($course, $exam, $now);
            $transaction->allow_commit();
        } catch (\dml_write_exception $e) {
            // Another request made the same version at the same moment and won.
            $row = mirror_store::find_quiz((int) $course->id, $exam->formid, $exam->version);
            if ($row === null) {
                throw $e;
            }
            return [$row, false];
        }
        return [$row, true];
    }

    /**
     * Whether the quiz of a mapping is still there.
     *
     * @param \stdClass $row A row of the mirror quiz table.
     * @return bool
     */
    public static function exists(\stdClass $row): bool {
        global $DB;

        return $DB->record_exists('course_modules', ['id' => $row->cmid, 'instance' => $row->quizid, 'deletioninprogress' => 0])
            && $DB->record_exists('quiz', ['id' => $row->quizid]);
    }

    /**
     * Hides the quiz and prohibits attempting and viewing it for the student roles. Idempotent.
     *
     * @param int $cmid
     */
    public static function lock(int $cmid): void {
        global $DB;

        $cm = $DB->get_record('course_modules', ['id' => $cmid], 'id, course, visible, visibleoncoursepage', MUST_EXIST);
        if ($cm->visible || $cm->visibleoncoursepage) {
            // The course format actions replace the global functions that Moodle 5.2 deprecated.
            \core_courseformat\formatactions::cm((int) $cm->course)->set_visibility($cmid, 0, 0);
        }
        $context = \context_module::instance($cmid);
        foreach (self::LOCKED_ARCHETYPES as $archetype) {
            foreach (get_archetype_roles($archetype) as $role) {
                foreach (self::LOCKED_CAPABILITIES as $capability) {
                    assign_capability($capability, CAP_PROHIBIT, $role->id, $context->id, true);
                }
            }
        }
    }

    /**
     * Makes the quiz, its questions and the mapping, in the transaction of the caller.
     *
     * @param \stdClass $course
     * @param exam $exam
     * @param int $now
     * @return \stdClass The row of the mapping.
     */
    private static function make(\stdClass $course, exam $exam, int $now): \stdClass {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $quiz = service_identity::run(fn() => self::add_quiz($course, $exam));
        $context = \context_module::instance($quiz->coursemodule);
        $category = question_get_default_category($context->id, true);

        $mirrorid = mirror_store::insert_quiz((int) $course->id, $exam, (int) $quiz->coursemodule, (int) $quiz->instance, $now);
        $quizrecord = $DB->get_record('quiz', ['id' => $quiz->instance], '*', MUST_EXIST);

        $made = [];
        service_identity::run(function () use ($exam, $category, $quizrecord, $mirrorid, &$made) {
            $slot = 0;
            foreach ($exam->items() as ['section' => $section, 'item' => $item]) {
                $question = mirror_questions::save($section, $item, $category);
                $maxmark = exam::maxmark($section, $item);
                quiz_add_quiz_question($question->id, $quizrecord, 0, $maxmark);
                $slot++;
                $made[] = [$item['item_id'], $slot, (int) $question->id, $maxmark, exam::units($item)];
            }
        });

        $quizobj = quiz_settings::create((int) $quiz->instance);
        $quizobj->get_grade_calculator()->recompute_quiz_sumgrades();

        $structure = \mod_quiz\structure::create_for_quiz($quizobj);
        foreach ($made as [$itemid, $slot, $questionid, $maxmark, $units]) {
            $slotid = $DB->get_field('quiz_slots', 'id', ['quizid' => $quiz->instance, 'slot' => $slot], MUST_EXIST);
            $version = $DB->get_field('question_versions', 'version', ['questionid' => $questionid], MUST_EXIST);
            $structure->update_slot_version((int) $slotid, (int) $version);
            mirror_store::insert_item($mirrorid, $itemid, $slot, $questionid, $maxmark, $units);
        }

        self::lock((int) $quiz->coursemodule);
        mirror_store::log('definition', 'applied', 200, 'created', [
            'formid' => $exam->formid,
            'version' => $exam->version,
            'courseid' => (int) $course->id,
            'detail' => 'cmid ' . $quiz->coursemodule,
        ], $now);
        return mirror_store::get_quiz($mirrorid);
    }

    /**
     * Adds the hidden quiz to the course, with no grade, no pass mark and no completion.
     *
     * @param \stdClass $course
     * @param exam $exam
     * @return \stdClass What `add_moduleinfo()` returns: the module info with `coursemodule` and `instance`.
     */
    private static function add_quiz(\stdClass $course, exam $exam): \stdClass {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $info = (object) [
            'modulename' => 'quiz',
            'module' => $DB->get_field('modules', 'id', ['name' => 'quiz'], MUST_EXIST),
            'course' => $course->id,
            'section' => 0,
            'visible' => 0,
            'visibleoncoursepage' => 0,
            'name' => \core_text::substr(get_string('mirrorquizname', 'format_rumbos', (object) [
                'purpose' => get_string('mirrorpurpose_' . $exam->purpose, 'format_rumbos'),
                'title' => $exam->title,
                'version' => \core_text::substr($exam->version, 0, 12),
            ]), 0, 255),
            'introeditor' => ['text' => '<p>' . s(get_string('mirrorquizintro', 'format_rumbos')) . '</p>',
                'format' => FORMAT_HTML, 'itemid' => 0],
            'showdescription' => 1,
            'cmidnumber' => '',
            'groupmode' => 0,
            'completion' => COMPLETION_TRACKING_NONE,
            // No maximum grade: Moodle then makes no grade item, so the gradebook has nothing to double count.
            'grade' => 0,
            'sumgrades' => 0,
            'gradepass' => 0,
            'grademethod' => QUIZ_GRADEHIGHEST,
            'attempts' => 0,
            'timeopen' => 0,
            'timeclose' => 0,
            'timelimit' => 0,
            'overduehandling' => 'autosubmit',
            'graceperiod' => 0,
            'preferredbehaviour' => 'deferredfeedback',
            'canredoquestions' => 0,
            'attemptonlast' => 0,
            'decimalpoints' => 2,
            'questiondecimalpoints' => -1,
            'questionsperpage' => 1,
            'navmethod' => QUIZ_NAVMETHOD_FREE,
            'shuffleanswers' => 0,
            'quizpassword' => '',
            'subnet' => '',
            'browsersecurity' => '',
            'delay1' => 0,
            'delay2' => 0,
            'showuserpicture' => 0,
            'showblocks' => 0,
        ];
        foreach (['during', 'immediately', 'open', 'closed'] as $when) {
            foreach (
                ['attempt', 'correctness', 'maxmarks', 'marks', 'specificfeedback', 'generalfeedback', 'rightanswer',
                    'overallfeedback'] as $what
            ) {
                $info->{$what . $when} = 1;
            }
        }
        return add_moduleinfo($info, $course);
    }
}
