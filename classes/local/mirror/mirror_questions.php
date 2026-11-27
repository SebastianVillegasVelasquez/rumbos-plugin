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

/**
 * Makes the Moodle question of one question of the exam.
 *
 * The mapping, and what each type loses, is in docs/NATIVE-QUIZ-MIRROR.md. A choice question, including one pair of a
 * matching item, is a single choice question with the options in the exam's order; a written task is an essay. The
 * questions are made with the same call the import formats use (`qtype::save_question()`), so they are ordinary
 * questions that a teacher can read in the question bank.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mirror_questions {
    /**
     * Saves a question of an exam in a category.
     *
     * @param array $question The validated question ({@see exam::$questions}).
     * @param \stdClass $category The question category (a row of `question_categories`).
     * @return \stdClass The saved question.
     */
    public static function save(array $question, \stdClass $category): \stdClass {
        $form = new \stdClass();
        $form->category = $category->id . ',' . $category->contextid;
        $form->contextid = $category->contextid;
        $form->name = \core_text::substr($question['section'] . ' / ' . $question['id'], 0, 255);
        $form->idnumber = $question['id'];
        $form->questiontext = ['text' => self::text($question), 'format' => FORMAT_HTML];
        $form->generalfeedback = ['text' => '', 'format' => FORMAT_HTML];
        $form->defaultmark = exam::maxmark($question);
        $form->penalty = 0;
        $form->status = \core_question\local\bank\question_version_status::QUESTION_STATUS_READY;

        $qtype = exam::is_written($question) ? self::essay($form) : self::multichoice($form, $question);
        return \question_bank::get_qtype($qtype)->save_question($form, clone $form);
    }

    /**
     * What the student read: the part's instructions, what its questions share, the prompt, which audio of a matching
     * item this is, and for a written task its questions, fields and template. The audio and images are not mirrored;
     * their ids are named so that a teacher can find them.
     *
     * @param array $question
     * @return string HTML.
     */
    private static function text(array $question): string {
        $paragraph = fn(string $text): string => '<p>' . nl2br(s($text), false) . '</p>';
        $html = $question['instructions'] === null ? '' : $paragraph($question['instructions']);

        $shared = $question['shared'];
        if ($shared !== null) {
            $lines = array_filter([$shared['title'], $shared['text'], $shared['alt_text']]);
            if ($lines !== []) {
                $html .= '<blockquote>' . implode('', array_map($paragraph, $lines)) . '</blockquote>';
            }
            if ($shared['image_id'] !== null) {
                $html .= $paragraph(get_string('mirrorimage', 'format_rumbos', $shared['image_id']));
            }
        }
        if ($question['audio_id'] !== null) {
            $html .= $paragraph(get_string('mirroraudio', 'format_rumbos', $question['audio_id']));
        }
        if ($question['prompt'] !== '') {
            $html .= $paragraph($question['prompt']);
        }
        if ($question['clip'] !== null) {
            $html .= $paragraph(get_string('mirrorclip', 'format_rumbos', $question['clip']));
        }
        if ($question['gap_label'] !== null) {
            $html .= $paragraph($question['gap_label']);
        }

        $written = $question['written'] ?? null;
        if ($written !== null) {
            foreach (['questions', 'fields'] as $list) {
                foreach ($written[$list] as $label) {
                    $html .= $paragraph($label);
                }
            }
            if ($written['template'] !== null) {
                $html .= $paragraph($written['template']);
            }
        }
        return $html === '' ? $paragraph($question['id']) : $html;
    }

    /**
     * A single choice question, with the answers in the order of the exam.
     *
     * @param \stdClass $form The form data to complete.
     * @param array $question
     * @return string The question type.
     */
    private static function multichoice(\stdClass $form, array $question): string {
        $form->qtype = 'multichoice';
        $form->single = 1;
        $form->shuffleanswers = 0;
        $form->answernumbering = 'abc';
        $form->showstandardinstruction = 0;
        $form->answer = [];
        $form->fraction = [];
        $form->feedback = [];
        foreach ($question['options'] as $option) {
            $form->answer[] = ['text' => s($option['text']), 'format' => FORMAT_HTML];
            $form->fraction[] = $option['id'] === $question['correct'] ? 1.0 : 0.0;
            $form->feedback[] = ['text' => '', 'format' => FORMAT_HTML];
        }
        self::combined_feedback($form);
        return 'multichoice';
    }

    /**
     * A written task: free text, graded by hand. The mark is the service's; its rubric never reaches Moodle.
     *
     * @param \stdClass $form The form data to complete.
     * @return string The question type.
     */
    private static function essay(\stdClass $form): string {
        $form->qtype = 'essay';
        $form->responseformat = 'plain';
        $form->responserequired = 1;
        $form->responsefieldlines = 12;
        $form->attachments = 0;
        $form->attachmentsrequired = 0;
        $form->maxbytes = 0;
        $form->filetypeslist = '';
        $form->minwordlimit = null;
        $form->maxwordlimit = null;
        $form->minwordenabled = 0;
        $form->maxwordenabled = 0;
        $form->graderinfo = [
            'text' => '<p>' . s(get_string('mirrorgraderinfo', 'format_rumbos')) . '</p>',
            'format' => FORMAT_HTML,
        ];
        $form->responsetemplate = ['text' => '', 'format' => FORMAT_HTML];
        return 'essay';
    }

    /**
     * The combined feedback fields that choice and matching questions require, empty.
     *
     * @param \stdClass $form
     */
    private static function combined_feedback(\stdClass $form): void {
        $form->correctfeedback = ['text' => '', 'format' => FORMAT_HTML];
        $form->partiallycorrectfeedback = ['text' => '', 'format' => FORMAT_HTML];
        $form->incorrectfeedback = ['text' => '', 'format' => FORMAT_HTML];
        $form->shownumcorrect = 0;
    }
}
