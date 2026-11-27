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

namespace format_rumbos;

/**
 * A made-up exam in the shape of the Rumbos service's statements, with every question type.
 *
 * The service's golden vectors (tests/fixtures/service_vectors) are the contract; their exam has only multiple choice
 * questions. This one adds matching pairs and the written types, following the published schema
 * (`definition-statement` and `attempt-statement` of the service). No real exam content is here.
 *
 * It has the section weights and points of a real form: five sections of 25, 25, 20, 15 and 15 with 7, 3, 3, 5 and 5
 * points, a matching item of five pairs and two written tasks of 0 to 5. The expected percentages are computed here
 * with integers, on purpose independently of the plugin.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class mirror_fixture {
    /** @var string The form id. */
    public const FORM = 'made-up-form';

    /** @var string The title. */
    public const TITLE = 'Made-up check-in';

    /** @var int[] Section weights, in order: listening, reading, use of English, writing, speaking. */
    public const WEIGHTS = [25, 25, 20, 15, 15];

    /** @var int[] Points available in each section. */
    public const MAXIMA = [7, 3, 3, 5, 5];

    /** @var int The least common multiple of the section maxima, to count exactly in integers. */
    private const LCM = 105;

    /** @var string[] The question ids, in order. */
    public const QUESTIONS = [
        'l-01', 'l-02', 'l-11:0', 'l-11:1', 'l-11:2', 'l-11:3', 'l-11:4', 'r-01', 'r-02', 'r-11', 'u-01', 'u-02', 'u-03',
        'w-01', 's-02',
    ];

    /**
     * The `definition` object.
     *
     * @param string $title A different title is a different exam, and so another hash.
     * @return array
     */
    public static function definition(string $title = self::TITLE): array {
        $places = [
            ['id' => 'chair', 'text' => 'a chaired meeting'], ['id' => 'theft', 'text' => 'a theft report'],
            ['id' => 'briefing', 'text' => 'a briefing'], ['id' => 'talk', 'text' => 'a talk'],
            ['id' => 'call', 'text' => 'a phone call'],
        ];
        $pairs = [];
        foreach ($places as $clip => $place) {
            $pairs[] = self::choice('l-11:' . $clip, 'Match each audio with its scene.', $place['id'], '25/7', 'match_pair', [
                'item_id' => 'l-11', 'options' => $places, 'clip' => $clip + 1, 'audio_id' => 'audio-' . $clip,
            ]);
        }
        return [
            'schema' => 1,
            'form_id' => self::FORM,
            'purpose' => 'check_in',
            'language' => 'en',
            'level' => 'B2',
            'title' => $title,
            'sections' => [
                self::section('listening', 25, 7, [
                    self::part('listening-1', [
                        self::choice('l-01', 'When is the visitor expected?', 'a', '25/7', 'listen_choose', [
                            'audio_id' => 'audio-9',
                        ]),
                        self::choice('l-02', 'What does the speaker conclude?', 'b', '25/7', 'listen_choose'),
                    ]),
                    self::part('listening-2', $pairs),
                ]),
                self::section('reading', 25, 3, [
                    self::part('reading-1', [
                        self::choice('r-01', 'What does the notice ask for?', 'a', '25/3'),
                        self::choice('r-02', 'What would have happened otherwise?', 'c', '25/3'),
                    ], 'A made-up notice about a room.'),
                    self::part('reading-2', [
                        self::choice('r-11', 'Gap (a):', 'a', '25/3', 'gap_choice', ['gap_label' => '(a)']),
                    ], 'Chair: I am pleased to (a)___ this meeting.'),
                ]),
                self::section('use_of_english', 20, 3, [
                    self::part('use-1', [
                        self::choice('u-01', 'Could you tell me ___ the train leaves?', 'a', '20/3'),
                        self::choice('u-02', 'If I ___ known, I would have called.', 'b', '20/3'),
                        self::choice('u-03', 'The fee ___ be waived.', 'c', '20/3'),
                    ]),
                ]),
                self::section('writing', 15, 5, [
                    self::part('writing-1', [
                        self::written('w-01', 'written_answers', 'Write a short letter.', [
                            'questions' => [['id' => 'q1', 'text' => 'Write your letter here.']],
                        ]),
                    ]),
                ]),
                self::section('speaking', 15, 5, [
                    self::part('speaking-1', [
                        self::written('s-02', 'form_fields', 'Open a meeting, in writing.', [
                            'fields' => [['id' => 'f1', 'text' => 'Your opening'], ['id' => 'f2', 'text' => 'Your close']],
                        ]),
                    ]),
                ], 'written_equivalent'),
            ],
        ];
    }

    /**
     * The hash that names a definition: SHA-256 of its JSON with sorted members, no spaces, nothing escaped that need
     * not be. Written here on its own, and checked against the golden vector by the tests.
     *
     * @param array $definition
     * @return string
     */
    public static function hash(array $definition): string {
        $sort = function ($value) use (&$sort) {
            if (!is_array($value)) {
                return $value;
            }
            if (!array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            return array_map($sort, $value);
        };
        return hash('sha256', json_encode($sort($definition), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_LINE_TERMINATORS));
    }

    /**
     * The claims an attempt statement has beyond the result claims, for an attempt that earned the points given.
     *
     * @param int[] $points Question id => points earned; a question left out earned nothing.
     * @param array|null $definition The exam; the fixture's by default.
     * @param bool $text Whether the written answers' text travels.
     * @return array
     */
    public static function record(array $points, ?array $definition = null, bool $text = true): array {
        $definition ??= self::definition();
        $answers = [];
        $written = [];
        $total = 0;
        foreach ($definition['sections'] as $index => $section) {
            foreach ($section['parts'] as $part) {
                foreach ($part['questions'] as $question) {
                    $id = $question['question_id'];
                    $earned = $points[$id] ?? 0;
                    $total += self::WEIGHTS[$index] * $earned * intdiv(self::LCM, self::MAXIMA[$index]);
                    if (isset($question['written'])) {
                        $boxes = array_merge($question['written']['questions'], $question['written']['fields']);
                        $values = [];
                        foreach ($boxes as $box) {
                            $values[$box['id']] = 'The student wrote this for ' . $id . ' ' . $box['id'] . '.';
                        }
                        $written[] = ['question_id' => $id, 'points_exact' => (string) $earned, 'max_points' => 5,
                            'text' => $text ? $values : null];
                        continue;
                    }
                    $wrong = array_values(array_filter(
                        array_column($question['options'], 'id'),
                        fn($option) => $option !== $question['correct']
                    ));
                    $answers[] = ['question_id' => $id, 'answer' => $earned ? $question['correct'] : $wrong[0],
                        'correct' => (bool) $earned, 'points_exact' => (string) $earned, 'max_points' => 1];
                }
            }
        }
        return [
            'schema' => 2,
            'kind' => 'attempt',
            'form_id' => $definition['form_id'],
            'content_version' => '0123456789abcdef',
            'status' => 'submitted',
            'definition_hash' => self::hash($definition),
            'percent_exact' => self::fraction($total, self::LCM),
            'sections' => [],
            'answers' => $answers,
            'written' => $written,
            'written_text_included' => $text,
            'truncated' => false,
        ];
    }

    /**
     * Points per section (listening, reading, use of English, writing, speaking) as points per question. Listening
     * points go to the matching pairs first.
     *
     * @param int[] $section
     * @return array<string, int>
     */
    public static function points(array $section): array {
        [$listening, $reading, $english, $writing, $speaking] = $section;
        $order = ['l-11:0', 'l-11:1', 'l-11:2', 'l-11:3', 'l-11:4', 'l-01', 'l-02'];
        $points = [];
        foreach ([[$order, $listening], [['r-01', 'r-02', 'r-11'], $reading], [['u-01', 'u-02', 'u-03'], $english]] as $set) {
            foreach ($set[0] as $i => $id) {
                $points[$id] = $i < $set[1] ? 1 : 0;
            }
        }
        return $points + ['w-01' => $writing, 's-02' => $speaking];
    }

    /**
     * The service's total, times 105, in integers: the sum of weight x points / maximum, over the sections.
     *
     * @param int[] $section Points earned in each section.
     * @return int
     */
    public static function total105(array $section): int {
        $total = 0;
        foreach ($section as $i => $points) {
            $total += self::WEIGHTS[$i] * $points * intdiv(self::LCM, self::MAXIMA[$i]);
        }
        return $total;
    }

    /**
     * The percent the service shows: two decimals, half up, from the exact value.
     *
     * @param int $total105 {@see self::total105()}.
     * @return string
     */
    public static function display(int $total105): string {
        $hundredths = intdiv($total105 * 100 * 2 + self::LCM, 2 * self::LCM);
        return sprintf('%d.%02d', intdiv($hundredths, 100), $hundredths % 100);
    }

    /**
     * Section points that come to a given total: the nearest below and above 80 percent, and exactly 80.
     *
     * @return array<string, int[]> Case name => section points.
     */
    public static function threshold_cases(): array {
        $all = [];
        for ($a = 0; $a <= 7; $a++) {
            for ($b = 0; $b <= 3; $b++) {
                for ($c = 0; $c <= 3; $c++) {
                    for ($d = 0; $d <= 5; $d++) {
                        for ($e = 0; $e <= 5; $e++) {
                            $all[] = [$a, $b, $c, $d, $e];
                        }
                    }
                }
            }
        }
        usort($all, fn($x, $y) => self::total105($x) <=> self::total105($y));
        $below = null;
        $at = null;
        $above = null;
        foreach ($all as $points) {
            $total = self::total105($points);
            if ($total < 8400) {
                $below = $points;
            } else if ($total === 8400 && $at === null) {
                $at = $points;
            } else if ($total > 8400 && $above === null) {
                $above = $points;
            }
        }
        return ['below' => $below, 'at' => $at, 'above' => $above, 'zero' => [0, 0, 0, 0, 0], 'full' => [7, 3, 3, 5, 5]];
    }

    /**
     * A fraction in its lowest terms, as the service writes one.
     *
     * @param int $numerator
     * @param int $denominator
     * @return string
     */
    private static function fraction(int $numerator, int $denominator): string {
        [$a, $b] = [$numerator, $denominator];
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }
        $divisor = max($a, 1);
        $bottom = intdiv($denominator, $divisor);
        return intdiv($numerator, $divisor) . ($bottom === 1 ? '' : '/' . $bottom);
    }

    /**
     * A section.
     *
     * @param string $key
     * @param int $weight
     * @param int $maximum
     * @param array[] $parts
     * @param string|null $delivery
     * @return array
     */
    private static function section(string $key, int $weight, int $maximum, array $parts, ?string $delivery = null): array {
        return ['key' => $key, 'title' => ucfirst($key), 'instructions' => null, 'weight' => $weight,
            'delivery' => $delivery, 'max_points' => $maximum, 'parts' => $parts];
    }

    /**
     * A part, with a passage its questions share when one is given.
     *
     * @param string $key
     * @param array[] $questions
     * @param string|null $passage
     * @return array
     */
    private static function part(string $key, array $questions, ?string $passage = null): array {
        $shared = $passage === null ? null
            : ['kind' => 'passage', 'title' => 'Notice', 'text' => $passage, 'image_id' => null, 'alt_text' => null];
        return ['part_key' => $key, 'title' => $key, 'instructions' => 'Read and answer.', 'shared' => $shared,
            'questions' => $questions];
    }

    /**
     * A question answered by choosing; its options are a, b and c unless others are given.
     *
     * @param string $id
     * @param string $prompt
     * @param string $correct
     * @param string $weight
     * @param string $type
     * @param array $override
     * @return array
     */
    private static function choice(
        string $id,
        string $prompt,
        string $correct,
        string $weight,
        string $type = 'mcq',
        array $override = []
    ): array {
        return $override + [
            'question_id' => $id, 'item_id' => $id, 'type' => $type, 'prompt' => $prompt,
            'options' => [['id' => 'a', 'text' => 'first'], ['id' => 'b', 'text' => 'second'], ['id' => 'c', 'text' => 'third']],
            'correct' => $correct, 'audio_id' => null, 'gap_label' => null, 'max_points' => 1, 'weight_points' => $weight,
            'weight_points_decimal' => number_format(\format_rumbos\local\mirror\exam::fraction($weight), 6, '.', ''),
        ];
    }

    /**
     * A written task, worth 5 points and 15 of the 100.
     *
     * @param string $id
     * @param string $type
     * @param string $prompt
     * @param array $boxes `fields` or `questions`.
     * @return array
     */
    private static function written(string $id, string $type, string $prompt, array $boxes): array {
        return [
            'question_id' => $id, 'item_id' => $id, 'type' => $type, 'prompt' => $prompt,
            'written' => $boxes + ['fields' => [], 'questions' => [], 'template' => null, 'min_words' => 20,
                'max_words' => 80, 'max_chars' => 600],
            'max_points' => 5, 'weight_points' => '15', 'weight_points_decimal' => '15.000000',
        ];
    }
}
