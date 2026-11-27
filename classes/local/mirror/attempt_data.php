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
 * One finished attempt at an exam, as the service reports it: what the student answered and the points the service
 * gave for each item.
 *
 * Like {@see exam}, this reads the provisional wire shape in one place ({@see self::from_array()}).
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class attempt_data {
    /** @var string The pattern of every identifier. */
    private const ID = '/^[A-Za-z0-9._-]{1,64}$/';

    /** @var string The service's attempt id: the idempotency key. */
    public string $attemptid;

    /** @var string The exam form. */
    public string $formid;

    /** @var string The content version the attempt was taken on. */
    public string $version;

    /** @var string `check_in` or `final`. */
    public string $purpose;

    /** @var int When the student began (Unix time). */
    public int $started;

    /** @var int When the student finished (Unix time). */
    public int $finished;

    /** @var string The percent the service decided, two decimals. */
    public string $percent;

    /** @var bool What the service decided. Moodle never recomputes it. */
    public bool $passed;

    /** @var array Item id => `points`, `response` and `comment`. */
    public array $items;

    /**
     * Use {@see self::from_array()}.
     *
     * @param string $attemptid The service's attempt id: the idempotency key.
     * @param string $formid The exam form.
     * @param string $version The content version the attempt was taken on.
     * @param string $purpose `check_in` or `final`.
     * @param int $started When the student began (Unix time).
     * @param int $finished When the student finished (Unix time).
     * @param string $percent The percent the service decided, two decimals.
     * @param bool $passed What the service decided.
     * @param array $items Item id => `points`, `response` and `comment`.
     */
    private function __construct(
        string $attemptid,
        string $formid,
        string $version,
        string $purpose,
        int $started,
        int $finished,
        string $percent,
        bool $passed,
        array $items
    ) {
        $this->attemptid = $attemptid;
        $this->formid = $formid;
        $this->version = $version;
        $this->purpose = $purpose;
        $this->started = $started;
        $this->finished = $finished;
        $this->percent = $percent;
        $this->passed = $passed;
        $this->items = $items;
    }

    /**
     * Validates an attempt and builds it.
     *
     * A preview is refused here and nowhere else decides it: an attempt that is not explicitly `preview: false` is not
     * mirrored.
     *
     * @param array $data The attempt, decoded from JSON as an associative array.
     * @return self
     * @throws result_rejection 422 `preview_not_accepted` or `invalid_attempt:<where>`.
     */
    public static function from_array(array $data): self {
        if (($data['preview'] ?? null) !== false) {
            throw new result_rejection(422, 'preview_not_accepted');
        }
        $attemptid = self::id($data['attempt_id'] ?? null, 'attempt_id');
        $formid = self::id($data['form_id'] ?? null, 'form_id');
        $version = self::id($data['content_version'] ?? null, 'content_version');
        $purpose = $data['purpose'] ?? null;
        if (!in_array($purpose, exam::PURPOSES, true)) {
            throw self::invalid('purpose');
        }
        $percent = $data['percent'] ?? null;
        if (!is_string($percent) || preg_match('/^(100\.00|\d{1,2}\.\d{2})$/', $percent) !== 1) {
            throw self::invalid('percent');
        }
        if (!is_bool($data['passed'] ?? null)) {
            throw self::invalid('passed');
        }
        $finished = $data['finished_at'] ?? null;
        $started = $data['started_at'] ?? null;
        if (!is_int($finished) || $finished <= 0 || ($started !== null && (!is_int($started) || $started <= 0))) {
            throw self::invalid('time');
        }
        $started = min($started ?? $finished, $finished);

        $rawitems = $data['items'] ?? null;
        if (!is_array($rawitems) || !array_is_list($rawitems) || count($rawitems) > exam::MAX_ITEMS) {
            throw self::invalid('items');
        }
        $items = [];
        foreach ($rawitems as $raw) {
            if (!is_array($raw)) {
                throw self::invalid('items');
            }
            $id = self::id($raw['item_id'] ?? null, 'item_id');
            $points = $raw['points'] ?? null;
            if (isset($items[$id]) || !(is_int($points) || is_float($points)) || $points < 0 || $points > 100) {
                throw self::invalid($id . '.points');
            }
            $comment = $raw['comment'] ?? null;
            if ($comment !== null && (!is_string($comment) || \core_text::strlen($comment) > 2000)) {
                throw self::invalid($id . '.comment');
            }
            $items[$id] = ['points' => $points + 0, 'response' => $raw['response'] ?? null, 'comment' => $comment];
        }
        return new self($attemptid, $formid, $version, $purpose, $started, $finished, $percent, $data['passed'], $items);
    }

    /**
     * Checks the attempt against the exam it was taken on and reads each response into the form the mirror writes.
     *
     * Each item's points must be possible (never more than the item is worth, and a choice item is 0 or 1), every item
     * must exist in the exam, and the response must have the shape its item type has.
     *
     * @param exam $exam The exam of the same form and version.
     * @return array<string, array{points: int|float, response: mixed, comment: string|null}> By item id, for every item
     *      of the exam (an item the service did not report is unanswered and earned nothing).
     * @throws result_rejection 422 `invalid_attempt:<where>`.
     */
    public function resolve(exam $exam): array {
        if ($exam->formid !== $this->formid || $exam->version !== $this->version || $exam->purpose !== $this->purpose) {
            throw self::invalid('exam');
        }
        foreach (array_keys($this->items) as $id) {
            if ($exam->item($id) === null) {
                throw self::invalid($id . '.unknown');
            }
        }

        $resolved = [];
        foreach ($exam->items() as ['item' => $item]) {
            $id = $item['item_id'];
            $given = $this->items[$id] ?? ['points' => 0, 'response' => null, 'comment' => null];
            $units = exam::units($item);
            $points = $given['points'];
            $choice = in_array($item['type'], exam::CHOICE_TYPES, true);
            if ($points > $units || ($choice && $points !== 0 && $points !== 1)) {
                throw self::invalid($id . '.points');
            }
            $resolved[$id] = [
                'points' => $points,
                'response' => self::response($item, $given['response']),
                'comment' => $given['comment'],
            ];
        }
        return $resolved;
    }

    /**
     * Reads a response into a fixed form.
     *
     * @param array $item The exam's item.
     * @param mixed $response What the service sent.
     * @return string|array|null A choice: the option id. A matching item: clip index => place id. A written task: the
     *      text. Null when the student did not answer.
     */
    private static function response(array $item, mixed $response): string|array|null {
        $id = $item['item_id'];
        if ($response === null || $response === '' || $response === []) {
            return null;
        }
        if (in_array($item['type'], exam::CHOICE_TYPES, true)) {
            if (!is_string($response) || !in_array($response, array_column($item['options'], 'id'), true)) {
                throw self::invalid($id . '.response');
            }
            return $response;
        }
        if ($item['type'] === exam::MATCH_TYPE) {
            $places = array_column($item['places'], 'id');
            if (!is_array($response)) {
                throw self::invalid($id . '.response');
            }
            $out = [];
            foreach ($response as $clip => $place) {
                if (!is_int($clip) && !(is_string($clip) && ctype_digit($clip))) {
                    throw self::invalid($id . '.response');
                }
                if ($place === null || $place === '') {
                    continue;
                }
                if ((int) $clip >= count($item['clips']) || !is_string($place) || !in_array($place, $places, true)) {
                    throw self::invalid($id . '.response');
                }
                $out[(int) $clip] = $place;
            }
            ksort($out);
            return $out === [] ? null : $out;
        }
        // Written: a text, or the answers by field or by question, shown one per line.
        if (is_array($response)) {
            $lines = [];
            foreach ($response as $name => $value) {
                if (!is_string($value) && !is_int($value) && !is_float($value)) {
                    throw self::invalid($id . '.response');
                }
                $lines[] = (is_string($name) ? $name . ': ' : '') . $value;
            }
            $response = implode("\n", $lines);
        }
        if (!is_string($response) || \core_text::strlen($response) > exam::MAX_TEXT) {
            throw self::invalid($id . '.response');
        }
        return $response;
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
     * The refusal of an attempt that is not valid.
     *
     * @param string $where Which part.
     * @return result_rejection
     */
    private static function invalid(string $where): result_rejection {
        return new result_rejection(422, 'invalid_attempt:' . $where);
    }
}
