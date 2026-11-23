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
 * A small exam definition and attempts shaped like the B2 form A of the Rumbos service.
 *
 * This is a FIXTURE. The service has not published its export contract; the shape below follows the form files in the
 * service's content directory (sections with weights, items of type listen_choose, mcq, gap_choice, match_audio and
 * written_answers, a matching item that counts as five) and is not an agreement. When the contract exists, only the
 * adapter that reads it changes.
 *
 * The expected percentages are computed here with integers, on purpose independently of the plugin's own scoring.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class mirror_fixture {
    /** @var int[] Section weights of the fixture, in order: listening, reading, use of English, writing, speaking. */
    public const WEIGHTS = [25, 25, 20, 15, 15];

    /** @var int[] Points available in each section: the matching item counts as 5, a written task is 0 to 5. */
    public const MAXIMA = [7, 3, 3, 5, 5];

    /** @var int The least common multiple of the section maxima, to count exactly in integers. */
    private const LCM = 105;

    /**
     * The definition. Section maxima 7, 3, 3, 5, 5; weights 25, 25, 20, 15, 15.
     *
     * @param string $version The content version (a hash in the service).
     * @param string $formid The form id.
     * @return array
     */
    public static function form(string $version = 'v1hash', string $formid = 'b2-form-a'): array {
        return [
            'form_id' => $formid,
            'content_version' => $version,
            'title' => 'B2 check-in (fixture)',
            'purpose' => 'check_in',
            'sections' => [
                ['key' => 'listening', 'weight' => 25, 'items' => [
                    self::choice('l-01', 'When is the delegation expected?', 'a', 'listen_choose', null, 'Around nine forty.'),
                    self::choice('l-02', 'What does the client conclude?', 'b', 'listen_choose'),
                    [
                        'item_id' => 'l-11', 'type' => 'match_audio', 'prompt' => 'Match each audio with the scene.',
                        'clips' => [
                            ['transcript' => 'I am pleased to open this session.'],
                            ['transcript' => 'I am reporting a theft.'],
                            ['transcript' => 'It is highly probable that.'],
                            ['transcript' => 'Visitors are instructed.'],
                            ['transcript' => 'Under GDS rules.'],
                        ],
                        'places' => [
                            ['id' => 'p0', 'text' => 'chaired negotiation'], ['id' => 'p1', 'text' => 'theft report'],
                            ['id' => 'p2', 'text' => 'certainty briefing'], ['id' => 'p3', 'text' => 'symposium'],
                            ['id' => 'p4', 'text' => 'compliance call'],
                        ],
                        'key' => [[0, 'p0'], [1, 'p1'], [2, 'p2'], [3, 'p3'], [4, 'p4']],
                    ],
                ]],
                ['key' => 'reading', 'weight' => 25, 'items' => [
                    self::choice('r-01', 'Notice: ACCOUNTS FLAGGED CANNOT BE REBOOKED', 'a'),
                    self::choice('r-02', 'What would have happened without the complaint?', 'c'),
                    self::choice('r-11', 'Gap (a):', 'a', 'gap_choice', 'Chair: I am pleased to (a)___ this meeting.'),
                ]],
                ['key' => 'use_of_english', 'weight' => 20, 'items' => [
                    self::choice('u-01', 'Could you tell me ___ the delegation arrives?', 'a'),
                    self::choice('u-02', 'If I ___ known, I would have called.', 'b'),
                    self::choice('u-03', 'The fee ___ be waived.', 'c'),
                ]],
                ['key' => 'writing', 'weight' => 15, 'items' => [
                    [
                        'item_id' => 'w-01', 'type' => 'written_answers', 'prompt' => 'Write a formal complaint letter.',
                        'max_points' => 5, 'rubric' => 'writing.complaint_letter',
                    ],
                ]],
                ['key' => 'speaking', 'weight' => 15, 'delivery' => 'written_equivalent', 'items' => [
                    [
                        'item_id' => 's-02', 'type' => 'written_answers', 'prompt' => 'Chair a negotiation (written).',
                        'max_points' => 5, 'rubric' => 'speaking.chair_negotiation_written',
                    ],
                ]],
            ],
        ];
    }

    /**
     * One choice item with the options a, b and c.
     *
     * @param string $id
     * @param string $prompt
     * @param string $key The right option.
     * @param string $type
     * @param string|null $shared A passage shared with other items.
     * @param string|null $transcript The audio's transcript.
     * @return array
     */
    private static function choice(
        string $id,
        string $prompt,
        string $key,
        string $type = 'mcq',
        ?string $shared = null,
        ?string $transcript = null
    ): array {
        return [
            'item_id' => $id, 'type' => $type, 'prompt' => $prompt, 'shared' => $shared, 'transcript' => $transcript,
            'key' => $key,
            'options' => [['id' => 'a', 'text' => 'first'], ['id' => 'b', 'text' => 'second'], ['id' => 'c', 'text' => 'third']],
        ];
    }

    /**
     * An attempt as the service would export it, answered so that it earns exactly the points given.
     *
     * @param array $form The definition ({@see self::form()}).
     * @param string $attemptid The service's attempt id.
     * @param array<string, int|float> $points Item id => points earned; an item that is left out earned nothing.
     * @param string $percent The percent the service decided.
     * @param bool $passed What the service decided.
     * @return array
     */
    public static function attempt(array $form, string $attemptid, array $points, string $percent, bool $passed): array {
        $items = [];
        foreach ($form['sections'] as $section) {
            foreach ($section['items'] as $item) {
                $earned = $points[$item['item_id']] ?? 0;
                $items[] = [
                    'item_id' => $item['item_id'], 'points' => $earned, 'response' => self::response($item, $earned),
                ];
            }
        }
        return [
            'attempt_id' => $attemptid, 'form_id' => $form['form_id'], 'content_version' => $form['content_version'],
            'purpose' => $form['purpose'], 'preview' => false, 'started_at' => 1789999000, 'finished_at' => 1790000000,
            'percent' => $percent, 'passed' => $passed, 'items' => $items,
        ];
    }

    /**
     * A response that earns the points given.
     *
     * @param array $item
     * @param int|float $earned
     * @return string|array
     */
    private static function response(array $item, int|float $earned): string|array {
        if ($item['type'] === 'written_answers') {
            return 'The student wrote this for ' . $item['item_id'] . '.';
        }
        if ($item['type'] === 'match_audio') {
            $response = [];
            $count = count($item['key']);
            foreach ($item['key'] as [$clip, $place]) {
                // The pairs after the first $earned are shifted by one, so each of them is wrong.
                $response[$clip] = $clip < $earned ? $place : $item['key'][($clip + 1) % $count][1];
            }
            return $response;
        }
        foreach ($item['options'] as $option) {
            if (($option['id'] === $item['key']) === ($earned >= 1)) {
                return $option['id'];
            }
        }
        return $item['options'][0]['id'];
    }

    /**
     * Points per section (listening, reading, use of English, writing, speaking) as points per item.
     *
     * @param int[] $section Points earned in each section.
     * @return array<string, int>
     */
    public static function item_points(array $section): array {
        [$listening, $reading, $english, $writing, $speaking] = $section;
        $pairs = min(5, $listening);
        $rest = $listening - $pairs;
        return [
            'l-11' => $pairs, 'l-01' => $rest >= 1 ? 1 : 0, 'l-02' => $rest >= 2 ? 1 : 0,
            'r-01' => $reading >= 1 ? 1 : 0, 'r-02' => $reading >= 2 ? 1 : 0, 'r-11' => $reading >= 3 ? 1 : 0,
            'u-01' => $english >= 1 ? 1 : 0, 'u-02' => $english >= 2 ? 1 : 0, 'u-03' => $english >= 3 ? 1 : 0,
            'w-01' => $writing, 's-02' => $speaking,
        ];
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
            $total += self::WEIGHTS[$i] * $points * (self::LCM / self::MAXIMA[$i]);
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
     * Section points that come to a given total, nearest below and above 80 percent, and exactly 80.
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
}
