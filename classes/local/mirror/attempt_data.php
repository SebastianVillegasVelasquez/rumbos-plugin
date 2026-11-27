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
use format_rumbos\local\result_statement;

/**
 * One closed attempt of the Rumbos service: what the student answered and the points the service gave each question.
 *
 * It reads the claims an attempt statement (schema 2) has beyond the result claims. The result claims are checked
 * first, by {@see result_statement::normalise_service()}, and are taken from there. Like {@see exam}, it reads that
 * shape in one place. The service decides every point and the pass; nothing here recomputes them.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class attempt_data {
    /** @var string[] How the service may have closed an attempt. */
    public const STATUSES = ['submitted', 'expired'];

    /** @var int Longest written response accepted for one question, in characters. A statement is at most 64 KB. */
    public const MAX_RESPONSE = 65536;

    /** @var string The pattern of an identifier. */
    private const ID = '/^[A-Za-z0-9._:-]{1,64}$/D';

    /** @var string The service's attempt id (`jti`): the idempotency key. */
    public string $attemptid;

    /** @var string The exam form. */
    public string $formid;

    /** @var string The definition hash the attempt was taken against. */
    public string $version;

    /** @var string `check_in` or `final`. */
    public string $purpose;

    /** @var int When the student finished (Unix time). The statement says nothing about the start. */
    public int $finished;

    /** @var string The percent the service shows, two decimals. */
    public string $percent;

    /** @var float The exact percent, which is the grade of the attempt. */
    public float $exact;

    /** @var bool What the service decided. Moodle never recomputes it. */
    public bool $passed;

    /** @var bool Whether the written answers' text was allowed to travel. */
    public bool $textincluded;

    /** @var bool Whether the service's 64 KB cap forced the written text out. */
    public bool $truncated;

    /** @var array[] By question id: `written` (bool), `points` (float), `max_points` (int), `answer` and `text`. */
    public array $entries;

    /**
     * Use {@see self::from_claims()}.
     */
    private function __construct() {
    }

    /**
     * Validates an attempt and builds it.
     *
     * @param \stdClass $claims The verified claims of the statement.
     * @param array $result The same statement's result claims, as normalised.
     * @return self
     * @throws result_rejection 422 `invalid_attempt:<where>`.
     */
    public static function from_claims(\stdClass $claims, array $result): self {
        $data = json_decode(json_encode($claims), true);

        $attempt = new self();
        $attempt->attemptid = $result['attempt_id'];
        $attempt->purpose = $result['purpose'];
        $attempt->finished = $result['finished_at'];
        $attempt->percent = $result['percent'];
        $attempt->passed = $result['passed'];
        $attempt->formid = self::id($data['form_id'] ?? null, 'form_id');

        $hash = $data['definition_hash'] ?? null;
        if (!is_string($hash) || preg_match(exam::HASH_PATTERN, $hash) !== 1) {
            throw self::invalid('definition_hash');
        }
        $attempt->version = $hash;

        if (!in_array($data['status'] ?? null, self::STATUSES, true)) {
            throw self::invalid('status');
        }
        $exact = exam::fraction($data['percent_exact'] ?? null);
        if ($exact === null || $exact > 100) {
            throw self::invalid('percent_exact');
        }
        $attempt->exact = $exact;
        if (!is_bool($data['written_text_included'] ?? null) || !is_bool($data['truncated'] ?? null)) {
            throw self::invalid('written_text_included');
        }
        $attempt->textincluded = $data['written_text_included'];
        $attempt->truncated = $data['truncated'];

        $attempt->entries = [];
        foreach (['answers' => false, 'written' => true] as $name => $written) {
            $list = $data[$name] ?? null;
            if (!is_array($list) || !array_is_list($list) || count($list) > exam::MAX_QUESTIONS) {
                throw self::invalid($name);
            }
            foreach ($list as $raw) {
                if (!is_array($raw)) {
                    throw self::invalid($name);
                }
                $id = self::id($raw['question_id'] ?? null, $name . '.question_id');
                $points = exam::fraction($raw['points_exact'] ?? null);
                $max = $raw['max_points'] ?? null;
                if (isset($attempt->entries[$id]) || $points === null || !is_int($max) || $points > $max) {
                    throw self::invalid($id . '.points');
                }
                $attempt->entries[$id] = [
                    'written' => $written,
                    'points' => $points,
                    'max_points' => $max,
                    'answer' => $written ? null : self::answer($raw, $id),
                    'text' => $written ? self::written_text($raw['text'] ?? null, $id) : null,
                ];
                if ($attempt->entries[$id]['text'] !== null && !$attempt->textincluded) {
                    // The statement says no text travelled. Keeping it would ignore the tenant's switch.
                    throw self::invalid($id . '.text');
                }
            }
        }
        return $attempt;
    }

    /**
     * Checks the attempt against the exam it names: the same form, every question of the exam and no other, each
     * answered the way its type is, and every chosen option one the question has. The purpose is not compared: the
     * definition hash already says which exam it is, and the service's own golden vectors differ there.
     *
     * @param exam $exam The exam of the same form and definition hash.
     * @throws result_rejection 422 `invalid_attempt:<where>`.
     */
    public function check_against(exam $exam): void {
        if ($exam->formid !== $this->formid || $exam->version !== $this->version) {
            throw self::invalid('exam');
        }
        $ids = array_column($exam->questions, 'id');
        if (array_diff(array_keys($this->entries), $ids) !== []) {
            throw self::invalid('question.unknown');
        }
        foreach ($exam->questions as $question) {
            $id = $question['id'];
            $entry = $this->entries[$id] ?? null;
            if ($entry === null) {
                throw self::invalid($id . '.missing');
            }
            if ($entry['written'] !== exam::is_written($question) || $entry['max_points'] !== $question['max_points']) {
                throw self::invalid($id . '.type');
            }
            if ($entry['answer'] !== null && !in_array($entry['answer'], array_column($question['options'], 'id'), true)) {
                throw self::invalid($id . '.answer');
            }
        }
    }

    /**
     * The chosen option of an objective question.
     *
     * @param array $raw
     * @param string $id
     * @return string|null Null when the student did not answer.
     */
    private static function answer(array $raw, string $id): ?string {
        if (!array_key_exists('answer', $raw)) {
            throw self::invalid($id . '.answer');
        }
        return $raw['answer'] === null ? null : self::id($raw['answer'], $id . '.answer');
    }

    /**
     * The text of a written task: texts by field or question id.
     *
     * @param mixed $raw
     * @param string $id
     * @return array<string, string>|null Null when no text came.
     */
    private static function written_text(mixed $raw, string $id): ?array {
        if ($raw === null || $raw === []) {
            return null;
        }
        if (!is_array($raw)) {
            throw self::invalid($id . '.text');
        }
        $length = 0;
        foreach ($raw as $value) {
            if (!is_string($value)) {
                throw self::invalid($id . '.text');
            }
            $length += \core_text::strlen($value);
        }
        if ($length > self::MAX_RESPONSE) {
            throw self::invalid($id . '.text');
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
     * The refusal of an attempt that is not valid.
     *
     * @param string $where Which part.
     * @return result_rejection
     */
    private static function invalid(string $where): result_rejection {
        return new result_rejection(422, 'invalid_attempt:' . $where);
    }
}
