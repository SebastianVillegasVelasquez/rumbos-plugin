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
 * An exam definition as the mirror needs it: the sections with their weights and the items that are scored.
 *
 * It is the one place that decides how many marks an item is worth, with the rule of the Rumbos service: the total is
 * the sum over sections of `weight x points earned / points available`, as a percentage, and a matching item counts as
 * one point per pair. The quiz it makes has a total of exactly 100 marks, so a Moodle total reads as a percentage.
 *
 * The wire format that fills this class is NOT agreed yet: the service has not published its export contract. Only
 * {@see self::from_array()} reads the provisional shape (the one of the service's form files, see
 * docs/NATIVE-QUIZ-MIRROR.md); everything else works on the validated object.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class exam {
    /** @var string[] The item types that are mirrored and are answered by choosing. */
    public const CHOICE_TYPES = ['listen_choose', 'mcq', 'gap_choice'];

    /** @var string[] The item types that are answered by writing, and graded by the service's rubric. */
    public const WRITTEN_TYPES = ['written_answers', 'template_text', 'form_fields'];

    /** @var string The matching item. */
    public const MATCH_TYPE = 'match_audio';

    /** @var string[] The purposes an exam may have. */
    public const PURPOSES = ['check_in', 'final'];

    /** @var int Most sections, items and options accepted: a form has 5 sections and 44 items. */
    public const MAX_SECTIONS = 12;

    /** @var int Most items in an exam. */
    public const MAX_ITEMS = 200;

    /** @var int Longest text accepted in any field, in characters. */
    public const MAX_TEXT = 8000;

    /** @var string The pattern of every identifier. */
    private const ID = '/^[A-Za-z0-9._-]{1,64}$/';

    /** @var string The service's exam form id. */
    public string $formid;

    /** @var string The content version of the form. */
    public string $version;

    /** @var string The title. */
    public string $title;

    /** @var string The purpose. */
    public string $purpose;

    /** @var array The sections: each `key`, `weight`, `delivery` and `items` (validated item arrays). */
    public array $sections;

    /**
     * Use {@see self::from_array()}.
     *
     * @param string $formid The service's exam form id.
     * @param string $version The content version of the form (a hash in the service).
     * @param string $title
     * @param string $purpose `check_in` or `final`.
     * @param array $sections Each `key`, `weight`, `delivery` and `items` (validated item arrays).
     */
    private function __construct(string $formid, string $version, string $title, string $purpose, array $sections) {
        $this->formid = $formid;
        $this->version = $version;
        $this->title = $title;
        $this->purpose = $purpose;
        $this->sections = $sections;
    }

    /**
     * Validates a definition and builds it.
     *
     * @param array $data The definition, decoded from JSON as an associative array.
     * @return self
     * @throws result_rejection 422 `invalid_definition:<where>` when anything is missing, of the wrong type or
     *      inconsistent. Nothing is trusted: the text is only ever stored, never evaluated.
     */
    public static function from_array(array $data): self {
        $formid = self::id($data['form_id'] ?? null, 'form_id');
        $version = self::id($data['content_version'] ?? null, 'content_version');
        $title = self::text($data['title'] ?? null, 'title', 255);
        $purpose = $data['purpose'] ?? null;
        if (!in_array($purpose, self::PURPOSES, true)) {
            throw self::invalid('purpose');
        }
        $rawsections = $data['sections'] ?? null;
        if (
            !is_array($rawsections) || !array_is_list($rawsections) || $rawsections === []
                || count($rawsections) > self::MAX_SECTIONS
        ) {
            throw self::invalid('sections');
        }

        $sections = [];
        $seen = [];
        $weights = 0;
        $count = 0;
        foreach ($rawsections as $rawsection) {
            if (!is_array($rawsection)) {
                throw self::invalid('sections');
            }
            $key = self::id($rawsection['key'] ?? null, 'section.key');
            $weight = $rawsection['weight'] ?? null;
            if (!is_int($weight) || $weight < 1 || $weight > 100) {
                throw self::invalid('section.weight');
            }
            $rawitems = $rawsection['items'] ?? null;
            if (!is_array($rawitems) || !array_is_list($rawitems) || $rawitems === []) {
                throw self::invalid('section.items');
            }
            $items = [];
            foreach ($rawitems as $rawitem) {
                $item = self::validate_item($rawitem);
                if (isset($seen[$item['item_id']])) {
                    throw self::invalid('item_id.duplicate');
                }
                $seen[$item['item_id']] = true;
                $items[] = $item;
                $count++;
            }
            if ($count > self::MAX_ITEMS) {
                throw self::invalid('items');
            }
            $delivery = $rawsection['delivery'] ?? null;
            if ($delivery !== null && !is_string($delivery)) {
                throw self::invalid('section.delivery');
            }
            $sections[] = ['key' => $key, 'weight' => $weight, 'delivery' => $delivery, 'items' => $items];
            $weights += $weight;
        }
        if ($weights !== 100) {
            throw self::invalid('weights');
        }
        return new self($formid, $version, $title, $purpose, $sections);
    }

    /**
     * The items in order, each with the section it belongs to.
     *
     * @return \Generator<int, array{section: array, item: array}>
     */
    public function items(): \Generator {
        foreach ($this->sections as $section) {
            foreach ($section['items'] as $item) {
                yield ['section' => $section, 'item' => $item];
            }
        }
    }

    /**
     * The item with an id.
     *
     * @param string $itemid
     * @return array|null
     */
    public function item(string $itemid): ?array {
        foreach ($this->items() as $pair) {
            if ($pair['item']['item_id'] === $itemid) {
                return $pair['item'];
            }
        }
        return null;
    }

    /**
     * How many points an item is worth in the service's scoring: one per pair of a matching item, the rubric's maximum
     * for a written task, one for anything else.
     *
     * @param array $item A validated item.
     * @return int|float
     */
    public static function units(array $item): int|float {
        return match (true) {
            $item['type'] === self::MATCH_TYPE => count($item['key']),
            in_array($item['type'], self::WRITTEN_TYPES, true) => $item['max_points'],
            default => 1,
        };
    }

    /**
     * The points available in a section.
     *
     * @param array $section
     * @return int|float
     */
    public static function section_units(array $section): int|float {
        return array_sum(array_map([self::class, 'units'], $section['items']));
    }

    /**
     * The marks an item is worth in the mirror quiz: the section's weight shared by points, so that the quiz adds up to
     * 100. Rounded to seven decimals, which is what Moodle stores for a question.
     *
     * @param array $section
     * @param array $item
     * @return float
     */
    public static function maxmark(array $section, array $item): float {
        return round($section['weight'] * self::units($item) / self::section_units($section), 7);
    }

    /**
     * The percent the points of an attempt come to under the service's rule, to two decimals, half up.
     *
     * @param array<string, int|float> $points Points earned by item id; an item that is not there earned nothing.
     * @return string For example `80.10`.
     */
    public function percent(array $points): string {
        $total = 0.0;
        foreach ($this->sections as $section) {
            $earned = 0;
            foreach ($section['items'] as $item) {
                $earned += $points[$item['item_id']] ?? 0;
            }
            $total += $section['weight'] * $earned / self::section_units($section);
        }
        return number_format(round($total, 2), 2, '.', '');
    }

    /**
     * Validates one item.
     *
     * @param mixed $raw
     * @return array The item in a fixed form.
     * @throws result_rejection 422 when it is not valid.
     */
    private static function validate_item(mixed $raw): array {
        if (!is_array($raw)) {
            throw self::invalid('item');
        }
        $id = self::id($raw['item_id'] ?? null, 'item_id');
        $type = $raw['type'] ?? null;
        $item = [
            'item_id' => $id,
            'type' => $type,
            'prompt' => self::text($raw['prompt'] ?? '', 'prompt', self::MAX_TEXT, true),
            'shared' => self::optional_text($raw['shared'] ?? null, 'shared'),
            'transcript' => self::optional_text($raw['transcript'] ?? null, 'transcript'),
        ];

        if (in_array($type, self::CHOICE_TYPES, true)) {
            $options = self::options($raw['options'] ?? null, $id);
            $key = $raw['key'] ?? null;
            if (!is_string($key) || !in_array($key, array_column($options, 'id'), true)) {
                throw self::invalid($id . '.key');
            }
            return $item + ['options' => $options, 'key' => $key];
        }
        if ($type === self::MATCH_TYPE) {
            return $item + self::matching($raw, $id);
        }
        if (in_array($type, self::WRITTEN_TYPES, true)) {
            $max = $raw['max_points'] ?? null;
            if (!(is_int($max) || is_float($max)) || $max <= 0 || $max > 100) {
                throw self::invalid($id . '.max_points');
            }
            return $item + [
                'max_points' => $max,
                'rubric' => self::text($raw['rubric'] ?? '', 'rubric', 255, true),
                'template' => self::optional_text($raw['template'] ?? null, 'template'),
            ];
        }
        throw self::invalid($id . '.type');
    }

    /**
     * Validates the options of a choice item.
     *
     * @param mixed $raw
     * @param string $itemid
     * @return array[] Each `id` and `text`.
     */
    private static function options(mixed $raw, string $itemid): array {
        if (!is_array($raw) || !array_is_list($raw) || count($raw) < 2 || count($raw) > 12) {
            throw self::invalid($itemid . '.options');
        }
        $options = [];
        foreach ($raw as $option) {
            if (!is_array($option)) {
                throw self::invalid($itemid . '.options');
            }
            $oid = self::id($option['id'] ?? null, $itemid . '.option.id');
            if (in_array($oid, array_column($options, 'id'), true)) {
                throw self::invalid($itemid . '.options');
            }
            $options[] = ['id' => $oid, 'text' => self::text($option['text'] ?? null, $itemid . '.option.text', 1000)];
        }
        if (count(array_unique(array_column($options, 'text'))) !== count($options)) {
            // Moodle shows the answers by their text, and the response summary names them by it.
            throw self::invalid($itemid . '.options');
        }
        return $options;
    }

    /**
     * Validates a matching item: clips (the stems), places (the choices) and the right place of every clip.
     *
     * @param array $raw
     * @param string $itemid
     * @return array `clips`, `places` and `key` (clip index => place id).
     */
    private static function matching(array $raw, string $itemid): array {
        $clips = $raw['clips'] ?? null;
        $places = $raw['places'] ?? null;
        $key = $raw['key'] ?? null;
        if (
            !is_array($clips) || !array_is_list($clips) || count($clips) < 2 || count($clips) > 12
                || !is_array($places) || !array_is_list($places) || count($places) < 2 || count($places) > 24
                || !is_array($key) || count($key) !== count($clips)
        ) {
            throw self::invalid($itemid . '.match');
        }
        $stems = [];
        foreach ($clips as $clip) {
            $stems[] = self::text(is_array($clip) ? ($clip['transcript'] ?? null) : null, $itemid . '.clip', 1000);
        }
        $choices = [];
        foreach ($places as $place) {
            if (!is_array($place)) {
                throw self::invalid($itemid . '.places');
            }
            $pid = self::id($place['id'] ?? null, $itemid . '.place.id');
            if (isset($choices[$pid])) {
                throw self::invalid($itemid . '.places');
            }
            $choices[$pid] = self::text($place['text'] ?? null, $itemid . '.place.text', 1000);
        }
        if (count(array_unique($choices)) !== count($choices)) {
            // Moodle merges choices that have the same text.
            throw self::invalid($itemid . '.places');
        }
        $right = [];
        foreach ($key as $pair) {
            if (
                !is_array($pair) || count($pair) !== 2 || !is_int($pair[0] ?? null) || !is_string($pair[1] ?? null)
                    || $pair[0] < 0 || $pair[0] >= count($clips) || isset($right[$pair[0]])
                    || !isset($choices[$pair[1]])
            ) {
                throw self::invalid($itemid . '.key');
            }
            $right[$pair[0]] = $pair[1];
        }
        ksort($right);
        return [
            'clips' => $stems,
            'places' => array_map(fn($pid) => ['id' => (string) $pid, 'text' => $choices[$pid]], array_keys($choices)),
            'key' => $right,
        ];
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
     * @param int $max Most characters.
     * @param bool $mayempty Whether an empty text is acceptable.
     * @return string
     */
    private static function text(mixed $value, string $where, int $max, bool $mayempty = false): string {
        if (!is_string($value) || (!$mayempty && trim($value) === '') || \core_text::strlen($value) > $max) {
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
        return $value === null || $value === '' ? null : self::text($value, $where, self::MAX_TEXT);
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
