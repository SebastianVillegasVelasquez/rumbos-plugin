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
use format_rumbos\mirror_fixture;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../fixtures/mirror_fixture.php');

/**
 * Tests for the exam definition and the attempt of the native quiz mirror.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(exam::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(attempt_data::class)]
final class exam_test extends \advanced_testcase {
    /**
     * Asserts that a callback is refused as unprocessable with a reason that starts as given.
     *
     * @param callable $callback
     * @param string $reason The reason with its detail.
     */
    private function assert_refused(callable $callback, string $reason): void {
        try {
            $callback();
            $this->fail('Nothing was refused, expected ' . $reason);
        } catch (result_rejection $rejection) {
            $this->assertSame(422, $rejection->status);
            $this->assertSame($reason, $rejection->reason);
        }
    }

    /**
     * Builds a definition and changes it.
     *
     * @param callable $change Receives the definition by reference.
     * @return callable
     */
    private static function changed_form(callable $change): callable {
        return function () use ($change): array {
            $data = mirror_fixture::form();
            $change($data);
            return $data;
        };
    }

    /**
     * Builds an attempt of 6/1/3/5/5 points and changes it.
     *
     * @param callable $change Receives the attempt by reference.
     * @return callable
     */
    private static function changed_attempt(callable $change): callable {
        return function () use ($change): array {
            $points = mirror_fixture::item_points([6, 1, 3, 5, 5]);
            $data = mirror_fixture::attempt(mirror_fixture::form(), 'att-1', $points, '79.76', false);
            $change($data);
            return $data;
        };
    }

    /**
     * The fixture is accepted and keeps what the mirror needs.
     */
    public function test_a_valid_definition_is_accepted(): void {
        $exam = exam::from_array(mirror_fixture::form());

        $this->assertSame('b2-form-a', $exam->formid);
        $this->assertSame('v1hash', $exam->version);
        $this->assertSame('check_in', $exam->purpose);
        $this->assertCount(5, $exam->sections);
        $this->assertCount(11, iterator_to_array($exam->items(), false));
    }

    /**
     * Points are the service's: a matching item is one per pair, a written task its rubric maximum, the rest one.
     */
    public function test_units_follow_the_services_scoring(): void {
        $exam = exam::from_array(mirror_fixture::form());

        $this->assertSame(5, exam::units($exam->item('l-11')));
        $this->assertSame(1, exam::units($exam->item('l-01')));
        $this->assertSame(5, exam::units($exam->item('w-01')));
        $this->assertSame(mirror_fixture::MAXIMA, array_map([exam::class, 'section_units'], $exam->sections));
    }

    /**
     * The marks of the items add up to 100, so a Moodle total reads as a percentage, within Moodle's own precision.
     */
    public function test_the_marks_of_the_items_add_up_to_one_hundred(): void {
        $exam = exam::from_array(mirror_fixture::form());
        $total = 0.0;
        foreach ($exam->items() as ['section' => $section, 'item' => $item]) {
            $total += exam::maxmark($section, $item);
        }

        $this->assertEqualsWithDelta(100.0, $total, 0.00001);
        $listening = $exam->sections[0];
        $this->assertSame(3.5714286, exam::maxmark($listening, $listening['items'][0]));
        $this->assertSame(17.8571429, exam::maxmark($listening, $listening['items'][2]));
    }

    /**
     * The percent computed from the points equals the service's rule for every combination of section points of the
     * fixture, which includes values just below, at and just above 80, where a half-up rounding could drift.
     */
    public function test_the_percent_is_the_services_for_every_combination(): void {
        $exam = exam::from_array(mirror_fixture::form());
        $checked = 0;
        for ($a = 0; $a <= 7; $a++) {
            for ($b = 0; $b <= 3; $b++) {
                for ($c = 0; $c <= 3; $c++) {
                    for ($d = 0; $d <= 5; $d++) {
                        for ($e = 0; $e <= 5; $e++) {
                            $section = [$a, $b, $c, $d, $e];
                            $this->assertSame(
                                mirror_fixture::display(mirror_fixture::total105($section)),
                                $exam->percent(mirror_fixture::item_points($section)),
                                implode('/', $section)
                            );
                            $checked++;
                        }
                    }
                }
            }
        }
        $this->assertSame(4608, $checked);
    }

    /**
     * Definitions that are not valid, by what is wrong with them.
     *
     * @return array
     */
    public static function invalid_definitions(): array {
        return [
            'weights do not make 100' => [self::changed_form(function (&$d) {
                $d['sections'][0]['weight'] = 24;
            }), 'invalid_definition:weights'],
            'a weight that is not a whole number' => [self::changed_form(function (&$d) {
                $d['sections'][0]['weight'] = '25';
            }), 'invalid_definition:section.weight'],
            'a repeated item id' => [self::changed_form(function (&$d) {
                $d['sections'][1]['items'][0]['item_id'] = 'l-01';
            }), 'invalid_definition:item_id.duplicate'],
            'an item type that cannot be mirrored' => [self::changed_form(function (&$d) {
                $d['sections'][1]['items'][0]['type'] = 'drag_drop';
            }), 'invalid_definition:r-01.type'],
            'a key that is not an option' => [self::changed_form(function (&$d) {
                $d['sections'][1]['items'][0]['key'] = 'z';
            }), 'invalid_definition:r-01.key'],
            'one option only' => [self::changed_form(function (&$d) {
                $d['sections'][1]['items'][0]['options'] = [['id' => 'a', 'text' => 'only']];
            }), 'invalid_definition:r-01.options'],
            'a repeated option id' => [self::changed_form(function (&$d) {
                $d['sections'][1]['items'][0]['options'][1]['id'] = 'a';
            }), 'invalid_definition:r-01.options'],
            'two options with the same text' => [self::changed_form(function (&$d) {
                $d['sections'][1]['items'][0]['options'][1]['text'] = 'first';
            }), 'invalid_definition:r-01.options'],
            'two places with the same text' => [self::changed_form(function (&$d) {
                $d['sections'][0]['items'][2]['places'][1]['text'] = 'chaired negotiation';
            }), 'invalid_definition:l-11.places'],
            'a matching key that repeats a clip' => [self::changed_form(function (&$d) {
                $d['sections'][0]['items'][2]['key'][4] = [3, 'p4'];
            }), 'invalid_definition:l-11.key'],
            'a matching key with a place that does not exist' => [self::changed_form(function (&$d) {
                $d['sections'][0]['items'][2]['key'][0] = [0, 'nowhere'];
            }), 'invalid_definition:l-11.key'],
            'a written task without a maximum' => [self::changed_form(function (&$d) {
                unset($d['sections'][3]['items'][0]['max_points']);
            }), 'invalid_definition:w-01.max_points'],
            'an identifier with a space' => [self::changed_form(function (&$d) {
                $d['form_id'] = 'b2 form';
            }), 'invalid_definition:form_id'],
            'no sections' => [self::changed_form(function (&$d) {
                $d['sections'] = [];
            }), 'invalid_definition:sections'],
            'a purpose that does not exist' => [self::changed_form(function (&$d) {
                $d['purpose'] = 'practice';
            }), 'invalid_definition:purpose'],
            'a title that is too long' => [self::changed_form(function (&$d) {
                $d['title'] = str_repeat('x', 256);
            }), 'invalid_definition:title'],
        ];
    }

    /**
     * Every kind of invalid definition is refused as unprocessable, naming the part.
     *
     * @param callable $build Builds the definition.
     * @param string $reason The expected reason.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_definitions')]
    public function test_an_invalid_definition_is_refused(callable $build, string $reason): void {
        $this->assert_refused(fn() => exam::from_array($build()), $reason);
    }

    /**
     * A well formed attempt is read, resolved against its exam and every item of the exam is there.
     */
    public function test_an_attempt_is_resolved_against_its_exam(): void {
        $form = mirror_fixture::form();
        $exam = exam::from_array($form);
        $points = mirror_fixture::item_points([6, 1, 3, 5, 5]);
        $data = mirror_fixture::attempt($form, 'att-1', $points, '79.76', false);
        // The service reports only some items; the others are unanswered.
        $data['items'] = array_values(array_filter($data['items'], fn($i) => $i['item_id'] !== 'u-03'));

        $attempt = attempt_data::from_array($data);
        $resolved = $attempt->resolve($exam);

        $this->assertSame('att-1', $attempt->attemptid);
        $this->assertFalse($attempt->passed);
        $this->assertSame('79.76', $attempt->percent);
        $this->assertCount(11, $resolved);
        $this->assertSame(0, $resolved['u-03']['points']);
        $this->assertNull($resolved['u-03']['response']);
        $this->assertSame(5, $resolved['l-11']['points']);
        $this->assertSame([0 => 'p0', 1 => 'p1', 2 => 'p2', 3 => 'p3', 4 => 'p4'], $resolved['l-11']['response']);
        $this->assertSame('a', $resolved['r-01']['response']);
    }

    /**
     * A preview is never mirrored, however the flag is missing or written.
     */
    public function test_a_preview_is_refused(): void {
        $data = mirror_fixture::attempt(mirror_fixture::form(), 'att-1', [], '0.00', false);
        foreach ([true, 'false', 0, null] as $value) {
            $data['preview'] = $value;
            $this->assert_refused(fn() => attempt_data::from_array($data), 'preview_not_accepted');
        }
        unset($data['preview']);
        $this->assert_refused(fn() => attempt_data::from_array($data), 'preview_not_accepted');
    }

    /**
     * Attempts that are not valid, by what is wrong with them.
     *
     * @return array
     */
    public static function invalid_attempts(): array {
        return [
            'a percent with one decimal' => [self::changed_attempt(function (&$d) {
                $d['percent'] = '79.8';
            }), 'invalid_attempt:percent'],
            'a percent that is a number' => [self::changed_attempt(function (&$d) {
                $d['percent'] = 79.76;
            }), 'invalid_attempt:percent'],
            'passed that is not a boolean' => [self::changed_attempt(function (&$d) {
                $d['passed'] = 'false';
            }), 'invalid_attempt:passed'],
            'no finish time' => [self::changed_attempt(function (&$d) {
                unset($d['finished_at']);
            }), 'invalid_attempt:time'],
            'an attempt id with a slash' => [self::changed_attempt(function (&$d) {
                $d['attempt_id'] = 'a/b';
            }), 'invalid_attempt:attempt_id'],
            'points that are text' => [self::changed_attempt(function (&$d) {
                $d['items'][0]['points'] = '1';
            }), 'invalid_attempt:l-01.points'],
            'negative points' => [self::changed_attempt(function (&$d) {
                $d['items'][0]['points'] = -1;
            }), 'invalid_attempt:l-01.points'],
            'the same item twice' => [self::changed_attempt(function (&$d) {
                $d['items'][1]['item_id'] = 'l-01';
            }), 'invalid_attempt:l-01.points'],
        ];
    }

    /**
     * Every kind of invalid attempt is refused as unprocessable, naming the part.
     *
     * @param callable $build Builds the attempt.
     * @param string $reason The expected reason.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_attempts')]
    public function test_an_invalid_attempt_is_refused(callable $build, string $reason): void {
        $this->assert_refused(fn() => attempt_data::from_array($build()), $reason);
    }

    /**
     * Attempts that read well but do not fit their exam.
     *
     * @return array
     */
    public static function attempts_that_do_not_fit(): array {
        return [
            'another version' => [self::changed_attempt(function (&$d) {
                $d['content_version'] = 'v2hash';
            }), 'invalid_attempt:exam'],
            'another purpose' => [self::changed_attempt(function (&$d) {
                $d['purpose'] = 'final';
            }), 'invalid_attempt:exam'],
            'an item the exam does not have' => [self::changed_attempt(function (&$d) {
                $d['items'][] = ['item_id' => 'x-99', 'points' => 1, 'response' => 'a'];
            }), 'invalid_attempt:x-99.unknown'],
            'more points than the item is worth' => [self::changed_attempt(function (&$d) {
                $d['items'][2]['points'] = 6;
            }), 'invalid_attempt:l-11.points'],
            'half a point on a choice item' => [self::changed_attempt(function (&$d) {
                $d['items'][0]['points'] = 0.5;
            }), 'invalid_attempt:l-01.points'],
            'an option that does not exist' => [self::changed_attempt(function (&$d) {
                $d['items'][0]['response'] = 'z';
            }), 'invalid_attempt:l-01.response'],
            'a matching answer with a place that does not exist' => [self::changed_attempt(function (&$d) {
                $d['items'][2]['response'] = [0 => 'nowhere'];
            }), 'invalid_attempt:l-11.response'],
            'a matching answer for a clip that does not exist' => [self::changed_attempt(function (&$d) {
                $d['items'][2]['response'] = [9 => 'p0'];
            }), 'invalid_attempt:l-11.response'],
        ];
    }

    /**
     * An attempt that does not fit its exam is refused as unprocessable.
     *
     * @param callable $build Builds the attempt.
     * @param string $reason The expected reason.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('attempts_that_do_not_fit')]
    public function test_an_attempt_that_does_not_fit_is_refused(callable $build, string $reason): void {
        $exam = exam::from_array(mirror_fixture::form());
        $this->assert_refused(fn() => attempt_data::from_array($build())->resolve($exam), $reason);
    }
}
