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
 * Makes the Moodle question of an exam item.
 *
 * The mapping, and what each type loses, is in docs/NATIVE-QUIZ-MIRROR.md. The questions are made with the same call
 * the import formats use (`qtype::save_question()`), so they are ordinary questions that a teacher can read in the
 * question bank.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mirror_questions {
    /**
     * Saves the question of an item in a category.
     *
     * @param array $section The section of the exam the item is in.
     * @param array $item The validated item.
     * @param \stdClass $category The question category (a row of `question_categories`).
     * @return \stdClass The saved question.
     */
    public static function save(array $section, array $item, \stdClass $category): \stdClass {
        $maxmark = exam::maxmark($section, $item);
        $form = new \stdClass();
        $form->category = $category->id . ',' . $category->contextid;
        $form->contextid = $category->contextid;
        $form->name = \core_text::substr($section['key'] . ' / ' . $item['item_id'], 0, 255);
        $form->idnumber = $item['item_id'];
        $form->questiontext = ['text' => self::text($item), 'format' => FORMAT_HTML];
        $form->generalfeedback = ['text' => '', 'format' => FORMAT_HTML];
        $form->defaultmark = $maxmark;
        $form->penalty = 0;
        $form->status = \core_question\local\bank\question_version_status::QUESTION_STATUS_READY;

        $qtype = match (true) {
            $item['type'] === exam::MATCH_TYPE => self::match($form, $item),
            in_array($item['type'], exam::WRITTEN_TYPES, true) => self::essay($form, $item),
            default => self::multichoice($form, $item),
        };
        return \question_bank::get_qtype($qtype)->save_question($form, clone $form);
    }

    /**
     * The text a student would have read: the shared passage, the audio's transcript (the audio is not mirrored), the
     * prompt and, for a task with a template, the template.
     *
     * @param array $item
     * @return string HTML.
     */
    private static function text(array $item): string {
        $html = '';
        if ($item['shared'] !== null) {
            $html .= '<blockquote>' . nl2br(s($item['shared']), false) . '</blockquote>';
        }
        if ($item['transcript'] !== null) {
            $html .= '<p><em>' . get_string('mirrortranscript', 'format_rumbos') . '</em> '
                . nl2br(s($item['transcript']), false) . '</p>';
        }
        $html .= '<p>' . nl2br(s($item['prompt']), false) . '</p>';
        if (($item['template'] ?? null) !== null) {
            $html .= '<p>' . nl2br(s($item['template']), false) . '</p>';
        }
        return $html;
    }

    /**
     * A single choice question, with the answers in the order of the exam.
     *
     * @param \stdClass $form The form data to complete.
     * @param array $item
     * @return string The question type.
     */
    private static function multichoice(\stdClass $form, array $item): string {
        $form->qtype = 'multichoice';
        $form->single = 1;
        $form->shuffleanswers = 0;
        $form->answernumbering = 'abc';
        $form->showstandardinstruction = 0;
        $form->answer = [];
        $form->fraction = [];
        $form->feedback = [];
        foreach ($item['options'] as $option) {
            $form->answer[] = ['text' => s($option['text']), 'format' => FORMAT_HTML];
            $form->fraction[] = $option['id'] === $item['key'] ? 1.0 : 0.0;
            $form->feedback[] = ['text' => '', 'format' => FORMAT_HTML];
        }
        self::combined_feedback($form);
        return 'multichoice';
    }

    /**
     * A matching question: each clip's transcript is a stem and each place a choice. A place that no clip belongs to
     * is a choice with no stem, which Moodle allows.
     *
     * @param \stdClass $form The form data to complete.
     * @param array $item
     * @return string The question type.
     */
    private static function match(\stdClass $form, array $item): string {
        $form->qtype = 'match';
        $form->shuffleanswers = 0;
        $form->subquestions = [];
        $form->subanswers = [];
        $places = array_column($item['places'], 'text', 'id');
        $used = [];
        foreach ($item['clips'] as $index => $transcript) {
            $form->subquestions[] = ['text' => s($transcript), 'format' => FORMAT_HTML];
            $form->subanswers[] = $places[$item['key'][$index]];
            $used[$item['key'][$index]] = true;
        }
        foreach ($places as $id => $text) {
            if (!isset($used[(string) $id])) {
                $form->subquestions[] = ['text' => '', 'format' => FORMAT_HTML];
                $form->subanswers[] = $text;
            }
        }
        self::combined_feedback($form);
        return 'match';
    }

    /**
     * A written task: free text, graded by hand. The service's rubric is named in the information for graders.
     *
     * @param \stdClass $form The form data to complete.
     * @param array $item
     * @return string The question type.
     */
    private static function essay(\stdClass $form, array $item): string {
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
            'text' => '<p>' . s(get_string('mirrorrubric', 'format_rumbos', (object) [
                'rubric' => $item['rubric'],
                'max' => format_float($item['max_points'], -1),
            ])) . '</p>',
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
