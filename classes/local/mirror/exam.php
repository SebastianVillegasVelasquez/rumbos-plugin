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

/**
 * An exam definition of the Rumbos service, as the mirror needs it.
 *
 * It reads the `definition` object of the service's definition statement (schema 1). The service has already split the
 * exam into **questions**, one per scorable unit: a choice item is one, a matching item is one per pair, a written task
 * is one. Each question says what it is worth, `weight_points`, as an exact fraction of the 100 of the total, so this
 * class scores nothing: it checks the shape and hands the questions on in order.
 *
 * It is the one place that reads that shape. The definition holds the answer key; it never holds a rubric or a
 * listening transcript.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class exam {
    /** @var int The version of the definition object this class reads. */
    public const SCHEMA = 1;

    /** @var string[] The question types that are answered by choosing one option. */
    public const CHOICE_TYPES = ['listen_choose', 'mcq', 'gap_choice', self::MATCH_TYPE];

    /** @var string One pair of a matching item: the options are the places. */
    public const MATCH_TYPE = 'match_pair';

    /** @var string[] The question types that are answered by writing, and scored by the service's rubric. */
    public const WRITTEN_TYPES = ['written_answers', 'template_text', 'form_fields'];

    /** @var string[] The purposes an exam may have. */
    public const PURPOSES = ['check_in', 'final'];

    /** @var string The pattern of a definition hash: SHA-256 in lower-case hexadecimal. */
    public const HASH_PATTERN = '/^[0-9a-f]{64}$/D';

    /** @var int Most sections accepted: a form of the service has 5. */
    public const MAX_SECTIONS = 20;

    /** @var int Most questions accepted: the largest form of the service has 48. */
    public const MAX_QUESTIONS = 300;

    /** @var int Longest text accepted in any field, in characters. */
    public const MAX_TEXT = 20000;

    /** @var string The pattern of an identifier. A matching question's id is `<item id>:<clip index>`. */
    private const ID = '/^[A-Za-z0-9._:-]{1,64}$/D';

    /** @var float How far the weights may be from adding up to 100: they are fractions read into floats. */
    private const WEIGHT_TOLERANCE = 0.000001;

    /** @var string The service's exam form id. */
    public string $formid;

    /** @var string The definition hash: the version of the exam the mirror keeps one quiz for. */
    public string $version;

    /** @var string The title. */
    public string $title;

    /** @var string `check_in` or `final`. */
    public string $purpose;

    /**
     * @var array[] The questions in order. Each: `id`, `item_id`, `type`, `prompt`, `max_points`, `weight` (its share
     *      of 100), `section`, `instructions`, `shared` (null or `title`, `text`, `alt_text`, `image_id`), `audio_id`,
     *      `gap_label`, `clip`, and either `options` and `correct` or `written`.
     */
    public array $questions;

    /**
     * Use {@see self::from_definition()}.
     *
     * @param string $formid
     * @param string $version
     * @param string $title
     * @param string $purpose
     * @param array[] $questions
     */
    private function __construct(string $formid, string $version, string $title, string $purpose, array $questions) {
        $this->formid = $formid;
        $this->version = $version;
        $this->title = $title;
        $this->purpose = $purpose;
        $this->questions = $questions;
    }

    /**
     * Validates a definition object and builds the exam.
     *
     * Nothing in it is evaluated: the text is only ever stored and shown escaped.
     *
     * @param array $data The `definition` claim, decoded from JSON as an associative array.
     * @param string $hash The `definition_hash` claim that names it.
     * @return self
     * @throws result_rejection 422 `invalid_definition:<where>` when anything is missing, of the wrong type or
     *      inconsistent.
     */
    public static function from_definition(array $data, string $hash): self {
        if (preg_match(self::HASH_PATTERN, $hash) !== 1) {
            throw self::invalid('definition_hash');
        }
        if (($data['schema'] ?? null) !== self::SCHEMA) {
            throw self::invalid('schema');
        }
        $formid = self::id($data['form_id'] ?? null, 'form_id');
        $title = self::text($data['title'] ?? null, 'title');
        $purpose = $data['purpose'] ?? null;
        if (!in_array($purpose, self::PURPOSES, true)) {
            throw self::invalid('purpose');
        }

        $questions = [];
        $weights = 0.0;
        foreach (self::items_of($data['sections'] ?? null, 'sections', self::MAX_SECTIONS) as $section) {
            $key = self::id($section['key'] ?? null, 'section.key');
            foreach (self::items_of($section['parts'] ?? null, 'section.parts', self::MAX_QUESTIONS) as $part) {
                $context = [
                    'section' => $key,
                    'instructions' => self::optional_text($part['instructions'] ?? null, 'part.instructions'),
                    'shared' => self::shared($part['shared'] ?? null),
                ];
                foreach (self::items_of($part['questions'] ?? null, 'part.questions', self::MAX_QUESTIONS) as $raw) {
                    $question = self::question($raw) + $context;
                    if (isset($questions[$question['id']])) {
                        throw self::invalid('question_id.duplicate');
                    }
                    $questions[$question['id']] = $question;
                    $weights += $question['weight'];
                    if (count($questions) > self::MAX_QUESTIONS) {
                        throw self::invalid('questions');
                    }
                }
            }
        }
        if ($questions === [] || abs($weights - 100) > self::WEIGHT_TOLERANCE) {
            throw self::invalid('weights');
        }
        return new self($formid, $hash, $title, $purpose, array_values($questions));
    }

    /**
     * The question with an id.
     *
     * @param string $questionid
     * @return array|null
     */
    public function question_by_id(string $questionid): ?array {
        foreach ($this->questions as $question) {
            if ($question['id'] === $questionid) {
                return $question;
            }
        }
        return null;
    }

    /**
     * Whether a question is answered by writing.
     *
     * @param array $question A validated question.
     * @return bool
     */
    public static function is_written(array $question): bool {
        return in_array($question['type'], self::WRITTEN_TYPES, true);
    }

    /**
     * What a question is worth in the mirror quiz: its share of 100, to the seven decimals Moodle stores.
     *
     * @param array $question A validated question.
     * @return float
     */
    public static function maxmark(array $question): float {
        return round($question['weight'], 7);
    }

    /**
     * A fraction as the service writes it, `5/3` or `2`.
     *
     * @param mixed $value
     * @return float|null Null when it is not one.
     */
    public static function fraction(mixed $value): ?float {
        if (!is_string($value) || preg_match('/^(\d{1,9})(?:\/([1-9]\d{0,8}))?$/D', $value, $parts) !== 1) {
            return null;
        }
        return (int) $parts[1] / (int) ($parts[2] ?? 1);
    }

    /**
     * Validates one question.
     *
     * @param array $raw
     * @return array
     * @throws result_rejection 422
     */
    private static function question(array $raw): array {
        $id = self::id($raw['question_id'] ?? null, 'question_id');
        $type = $raw['type'] ?? null;
        $maxpoints = $raw['max_points'] ?? null;
        $weight = self::fraction($raw['weight_points'] ?? null);
        if (!is_int($maxpoints) || $maxpoints < 1 || $maxpoints > 100) {
            throw self::invalid($id . '.max_points');
        }
        if ($weight === null || $weight <= 0 || $weight > 100) {
            throw self::invalid($id . '.weight_points');
        }
        $clip = $raw['clip'] ?? null;
        $question = [
            'id' => $id,
            'item_id' => self::id($raw['item_id'] ?? null, $id . '.item_id'),
            'type' => $type,
            'prompt' => self::text($raw['prompt'] ?? null, $id . '.prompt', true),
            'max_points' => $maxpoints,
            'weight' => $weight,
            'audio_id' => self::optional_text($raw['audio_id'] ?? null, $id . '.audio_id'),
            'gap_label' => self::optional_text($raw['gap_label'] ?? null, $id . '.gap_label'),
            'clip' => is_int($clip) ? $clip : null,
        ];

        if (in_array($type, self::CHOICE_TYPES, true)) {
            $options = [];
            foreach (self::items_of($raw['options'] ?? null, $id . '.options', 30) as $option) {
                $oid = self::id($option['id'] ?? null, $id . '.option.id');
                if (isset($options[$oid])) {
                    throw self::invalid($id . '.options');
                }
                $options[$oid] = ['id' => $oid, 'text' => self::text($option['text'] ?? null, $id . '.option.text')];
            }
            $correct = $raw['correct'] ?? null;
            if (count($options) < 2 || !is_string($correct) || !isset($options[$correct])) {
                throw self::invalid($id . '.correct');
            }
            return $question + ['options' => array_values($options), 'correct' => $correct];
        }
        if (in_array($type, self::WRITTEN_TYPES, true)) {
            $written = $raw['written'] ?? null;
            if (!is_array($written)) {
                throw self::invalid($id . '.written');
            }
            $words = fn($value) => is_int($value) && $value > 0 ? $value : null;
            return $question + ['written' => [
                'fields' => self::labels($written['fields'] ?? [], $id . '.written.fields'),
                'questions' => self::labels($written['questions'] ?? [], $id . '.written.questions'),
                'template' => self::optional_text($written['template'] ?? null, $id . '.written.template'),
                'min_words' => $words($written['min_words'] ?? null),
                'max_words' => $words($written['max_words'] ?? null),
            ]];
        }
        throw self::invalid($id . '.type');
    }

    /**
     * The fields or questions of a written task, as texts by id.
     *
     * @param mixed $raw
     * @param string $where
     * @return array<string, string>
     */
    private static function labels(mixed $raw, string $where): array {
        if ($raw === []) {
            return [];
        }
        $labels = [];
        foreach (self::items_of($raw, $where, 60) as $label) {
            $labels[self::id($label['id'] ?? null, $where)] = self::text($label['text'] ?? null, $where, true);
        }
        return $labels;
    }

    /**
     * The passage or image a part's questions share.
     *
     * @param mixed $raw
     * @return array|null
     */
    private static function shared(mixed $raw): ?array {
        if ($raw === null) {
            return null;
        }
        if (!is_array($raw)) {
            throw self::invalid('part.shared');
        }
        $shared = [];
        foreach (['title', 'text', 'image_id', 'alt_text'] as $name) {
            $shared[$name] = self::optional_text($raw[$name] ?? null, 'part.shared.' . $name);
        }
        return $shared;
    }

    /**
     * A list of one or more objects.
     *
     * @param mixed $raw
     * @param string $where
     * @param int $max Most entries.
     * @return array[]
     */
    private static function items_of(mixed $raw, string $where, int $max): array {
        if (!is_array($raw) || !array_is_list($raw) || $raw === [] || count($raw) > $max) {
            throw self::invalid($where);
        }
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                throw self::invalid($where);
            }
        }
        return $raw;
    }

    /**
     * An identifier.
     *
     * @param mixed $value
     * @param string $where
     * @return string
     */
    private static function id(mixed $value, string $where): string {
        if (!is_string($value) || preg_match(self::ID, $value) !== 1) {
            throw self::invalid($where);
        }
        return $value;
    }

    /**
     * A text that must be there.
     *
     * @param mixed $value
     * @param string $where
     * @param bool $mayempty Whether an empty text is acceptable.
     * @return string
     */
    private static function text(mixed $value, string $where, bool $mayempty = false): string {
        if (!is_string($value) || (!$mayempty && trim($value) === '') || \core_text::strlen($value) > self::MAX_TEXT) {
            throw self::invalid($where);
        }
        return $value;
    }

    /**
     * A text that may be missing.
     *
     * @param mixed $value
     * @param string $where
     * @return string|null
     */
    private static function optional_text(mixed $value, string $where): ?string {
        return $value === null || $value === '' ? null : self::text($value, $where);
    }

    /**
     * The refusal of a definition that is not valid.
     *
     * @param string $where Which part.
     * @return result_rejection
     */
    private static function invalid(string $where): result_rejection {
        return new result_rejection(422, 'invalid_definition:' . $where);
    }
}
