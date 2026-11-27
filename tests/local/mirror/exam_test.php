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
use format_rumbos\mirror_fixture;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../fixtures/result_testcase.php');
require_once(__DIR__ . '/../../fixtures/mirror_fixture.php');

/**
 * Tests for how the mirror reads the service's exam definitions and attempt records, starting from its golden vectors.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(exam::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(attempt_data::class)]
final class exam_test extends \format_rumbos\result_testcase {
    /**
     * The claims of a vector, as an associative array.
     *
     * @param string $name
     * @return array
     */
    private function vector_claims(string $name): array {
        return json_decode($this->vector_file($name . '.json'), true)['claims'];
    }

    /**
     * The result claims of the attempt vector, as the receiver would normalise them. The vector's `sub` is not one a
     * launch can carry, so only that claim is replaced.
     *
     * @return array [the claims object, the normalised result claims]
     */
    private function attempt_vector(): array {
        $claims = $this->vector('attempt')->claims;
        $claims->sub = str_repeat('a', 32);
        return [$claims, result_statement::normalise_service($claims, $claims->iat + 1)];
    }

    /**
     * The service's definition vector is read as it is: every question, in order, worth its exact share of 100.
     */
    public function test_the_definition_vector(): void {
        $claims = $this->vector_claims('definition');

        $exam = exam::from_definition($claims['definition'], $claims['definition_hash']);

        $this->assertSame('synthetic', $exam->formid);
        $this->assertSame($claims['definition_hash'], $exam->version);
        $this->assertSame('check_in', $exam->purpose);
        $this->assertCount(20, $exam->questions);
        $this->assertSame('listening.q0', $exam->questions[0]['id']);
        $this->assertEqualsWithDelta(4.9, $exam->questions[0]['weight'], 0.0000001);
        $this->assertEqualsWithDelta(100.0, array_sum(array_map([exam::class, 'maxmark'], $exam->questions)), 0.00001);
    }

    /**
     * The hash the fixture computes with its own code is the vector's, so the fixture names exams as the service does.
     */
    public function test_the_fixtures_hash_is_the_services(): void {
        $claims = $this->vector_claims('definition');

        $this->assertSame($claims['definition_hash'], mirror_fixture::hash($claims['definition']));
    }

    /**
     * The attempt vector fits the definition vector, and its marks add up to its exact percent from the definition's
     * weights alone.
     */
    public function test_the_attempt_vector_against_the_definition_vector(): void {
        $definition = $this->vector_claims('definition');
        $exam = exam::from_definition($definition['definition'], $definition['definition_hash']);
        [$claims, $result] = $this->attempt_vector();

        $attempt = attempt_data::from_claims($claims, $result);
        $attempt->check_against($exam);

        $this->assertSame($claims->jti, $attempt->attemptid);
        $this->assertSame('80.20', $attempt->percent);
        $this->assertEqualsWithDelta(80.2, $attempt->exact, 0.0000001);
        $this->assertTrue($attempt->passed);
        $total = 0.0;
        foreach ($exam->questions as $question) {
            $entry = $attempt->entries[$question['id']];
            $total += $question['weight'] * $entry['points'] / $entry['max_points'];
        }
        $this->assertEqualsWithDelta($attempt->exact, $total, 0.0000001);
    }

    /**
     * Every question type the service's schema names is read: pairs of a matching item are choices among the places,
     * and the written types keep their boxes.
     */
    public function test_every_question_type(): void {
        $definition = mirror_fixture::definition();

        $exam = exam::from_definition($definition, mirror_fixture::hash($definition));

        $this->assertSame(mirror_fixture::QUESTIONS, array_column($exam->questions, 'id'));
        $pair = $exam->question_by_id('l-11:3');
        $this->assertSame(['match_pair', 'l-11', 4, 'talk'], [$pair['type'], $pair['item_id'], $pair['clip'], $pair['correct']]);
        $this->assertCount(5, $pair['options']);
        $this->assertSame(['f1' => 'Your opening', 'f2' => 'Your close'], $exam->question_by_id('s-02')['written']['fields']);
        $this->assertTrue(exam::is_written($exam->question_by_id('w-01')));
        $this->assertNull($exam->question_by_id('x-01'));
    }

    /**
     * The service's matching places and options may repeat a text: Moodle's single choice holds them by position.
     */
    public function test_two_options_may_share_a_text(): void {
        $definition = mirror_fixture::definition();
        $definition['sections'][1]['parts'][0]['questions'][0]['options'][1]['text'] = 'first';

        $exam = exam::from_definition($definition, mirror_fixture::hash($definition));

        $this->assertCount(3, $exam->question_by_id('r-01')['options']);
    }

    /**
     * What is not the shape of the schema is refused, and says where.
     *
     * @param array $path Keys down to the value to change.
     * @param mixed $value The new value.
     * @param string $where
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_definition_provider')]
    public function test_an_invalid_definition_is_refused(array $path, mixed $value, string $where): void {
        $definition = mirror_fixture::definition();
        $target = &$definition;
        foreach ($path as $key) {
            $target = &$target[$key];
        }
        $target = $value;
        unset($target);

        try {
            exam::from_definition($definition, str_repeat('a', 64));
            $this->fail('accepted');
        } catch (result_rejection $rejection) {
            $this->assertSame([422, 'invalid_definition:' . $where], [$rejection->status, $rejection->reason]);
        }
    }

    /**
     * Cases of invalid definitions.
     *
     * @return array
     */
    public static function invalid_definition_provider(): array {
        $question = ['sections', 0, 'parts', 0, 'questions', 0];
        return [
            'another schema' => [['schema'], 2, 'schema'],
            'no title' => [['title'], '', 'title'],
            'unknown purpose' => [['purpose'], 'practice', 'purpose'],
            'no sections' => [['sections'], [], 'sections'],
            'unknown type' => [[...$question, 'type'], 'drawing', 'l-01.type'],
            'the key is no option' => [[...$question, 'correct'], 'z', 'l-01.correct'],
            'one option' => [[...$question, 'options'], [['id' => 'a', 'text' => 'only']], 'l-01.correct'],
            'a decimal weight' => [[...$question, 'weight_points'], '3.57', 'l-01.weight_points'],
            'weights that do not make 100' => [[...$question, 'weight_points'], '4', 'weights'],
            'no points' => [[...$question, 'max_points'], 0, 'l-01.max_points'],
            'a repeated question' => [[...$question, 'question_id'], 'l-02', 'question_id.duplicate'],
            'a bad id' => [[...$question, 'question_id'], 'a b', 'question_id'],
        ];
    }

    /**
     * A hash that is not one is refused.
     */
    public function test_a_definition_needs_its_hash(): void {
        $this->expectExceptionObject(new result_rejection(422, 'invalid_definition:definition_hash'));

        exam::from_definition(mirror_fixture::definition(), 'v1hash');
    }

    /**
     * Fractions as the service writes them.
     */
    public function test_fractions(): void {
        $this->assertSame(2.0, (float) exam::fraction('2'));
        $this->assertEqualsWithDelta(5 / 3, exam::fraction('5/3'), 0.0000000001);
        foreach (['', '1.5', '-1', '1/0', '1/', '/2', ' 1', 1, null] as $value) {
            $this->assertNull(exam::fraction($value), var_export($value, true));
        }
    }

    /**
     * What is not the shape of an attempt record is refused.
     *
     * @param \Closure $change Changes the record.
     * @param string $where
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_attempt_provider')]
    public function test_an_invalid_attempt_is_refused(\Closure $change, string $where): void {
        $definition = mirror_fixture::definition();
        $exam = exam::from_definition($definition, mirror_fixture::hash($definition));
        $record = $change(mirror_fixture::record(mirror_fixture::points([7, 3, 3, 5, 5])));

        try {
            attempt_data::from_claims(json_decode(json_encode($record)), [
                'attempt_id' => 'att-1', 'purpose' => 'check_in', 'finished_at' => 1000, 'passed' => true,
                'percent' => '100.00',
            ])->check_against($exam);
            $this->fail('accepted');
        } catch (result_rejection $rejection) {
            $this->assertSame([422, 'invalid_attempt:' . $where], [$rejection->status, $rejection->reason]);
        }
    }

    /**
     * Cases of invalid attempt records. The first answer is l-01's, the first written entry w-01's.
     *
     * @return array
     */
    public static function invalid_attempt_provider(): array {
        $with = fn(array $path, mixed $value): \Closure => function (array $record) use ($path, $value): array {
            $target = &$record;
            foreach ($path as $key) {
                $target = &$target[$key];
            }
            $target = $value;
            return $record;
        };
        return [
            'unknown status' => [$with(['status'], 'voided'), 'status'],
            'a decimal exact percent' => [$with(['percent_exact'], '100.0'), 'percent_exact'],
            'more than 100' => [$with(['percent_exact'], '101'), 'percent_exact'],
            'no answers list' => [$with(['answers'], null), 'answers'],
            'more points than the question has' => [$with(['answers', 0, 'points_exact'], '2'), 'l-01.points'],
            'an option the question does not have' => [$with(['answers', 0, 'answer'], 'z'), 'l-01.answer'],
            'an unknown question' => [$with(['answers', 0, 'question_id'], 'x-01'), 'question.unknown'],
            'another maximum' => [$with(['written', 0, 'max_points'], 6), 'w-01.type'],
            'text although none travelled' => [$with(['written_text_included'], false), 'w-01.text'],
            'text that is not texts' => [$with(['written', 0, 'text'], ['q1' => 3]), 'w-01.text'],
            'a missing question' => [function (array $record): array {
                array_pop($record['answers']);
                return $record;
            }, 'u-03.missing'],
            'another form' => [$with(['form_id'], 'other'), 'exam'],
        ];
    }
}
