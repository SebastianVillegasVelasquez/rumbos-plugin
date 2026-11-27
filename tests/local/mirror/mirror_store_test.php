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

use format_rumbos\mirror_fixture;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../fixtures/mirror_fixture.php');

/**
 * Tests for the tables of the native quiz mirror.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(mirror_store::class)]
final class mirror_store_test extends \advanced_testcase {
    /**
     * Every table exists after install.
     */
    public function test_the_tables_exist(): void {
        global $DB;
        $this->resetAfterTest();

        $tables = [mirror_store::DEFINITIONS, mirror_store::QUIZZES, mirror_store::ITEMS, mirror_store::ATTEMPTS,
            mirror_store::LOG];
        foreach ($tables as $table) {
            $this->assertTrue($DB->get_manager()->table_exists($table), $table);
        }
    }

    /**
     * The exam of the fixture.
     *
     * @return exam
     */
    private function exam(): exam {
        $definition = mirror_fixture::definition();
        return exam::from_definition($definition, mirror_fixture::hash($definition));
    }

    /**
     * An attempt with nothing right.
     *
     * @param string $attemptid
     * @return attempt_data
     */
    private function attempt(string $attemptid): attempt_data {
        return attempt_data::from_claims(json_decode(json_encode(mirror_fixture::record([]))), [
            'attempt_id' => $attemptid, 'purpose' => 'check_in', 'finished_at' => 1790000000, 'passed' => false,
            'percent' => '0.00',
        ]);
    }

    /**
     * A definition is kept once per form and hash.
     */
    public function test_a_definition_is_kept_once(): void {
        $this->resetAfterTest();
        $hash = str_repeat('a', 64);

        $this->assertNull(mirror_store::find_definition('form', $hash));
        mirror_store::insert_definition('form', $hash, 'content-1', '{"title":"T"}', 1000);

        $this->assertSame('{"title":"T"}', mirror_store::find_definition('form', $hash)->definition);
        $this->assertNull(mirror_store::find_definition('other', $hash));
        $this->expectException(\dml_write_exception::class);
        mirror_store::insert_definition('form', $hash, 'content-2', '{}', 1001);
    }

    /**
     * A quiz is found by course, form and version, and a version has one quiz.
     */
    public function test_a_version_has_one_quiz(): void {
        $this->resetAfterTest();
        $exam = $this->exam();
        [$form, $version] = [$exam->formid, $exam->version];

        $this->assertNull(mirror_store::find_quiz(7, $form, $version));
        $id = mirror_store::insert_quiz(7, $exam, 100, 200, 1000);

        $found = mirror_store::find_quiz(7, $form, $version);
        $this->assertSame($id, (int) $found->id);
        $this->assertSame('100', $found->cmid);
        $this->assertSame('check_in', $found->purpose);
        $this->assertNull(mirror_store::find_quiz(7, $form, str_repeat('b', 64)));
        $this->assertNull(mirror_store::find_quiz(8, $form, $version));

        $this->expectException(\dml_write_exception::class);
        mirror_store::insert_quiz(7, $exam, 101, 201, 1001);
    }

    /**
     * An item is found by the id it has in the exam, in the order of the slots.
     */
    public function test_items_are_keyed_by_the_exam_item(): void {
        $this->resetAfterTest();
        $mirrorid = mirror_store::insert_quiz(7, $this->exam(), 100, 200, 1000);
        mirror_store::insert_item($mirrorid, 'l-02', 2, 502, 3.5714286, 1);
        mirror_store::insert_item($mirrorid, 'l-01', 1, 501, 3.5714286, 1);
        mirror_store::insert_item($mirrorid, 'l-11', 3, 503, 17.8571429, 5);

        $items = mirror_store::items($mirrorid);

        $this->assertSame(['l-01', 'l-02', 'l-11'], array_keys($items));
        $this->assertSame('501', $items['l-01']->questionid);
        $this->assertEquals(17.8571429, $items['l-11']->maxmark);
        $this->assertEquals(5, $items['l-11']->units);
        $this->assertSame([], mirror_store::items($mirrorid + 1));
    }

    /**
     * Forgetting a quiz forgets its items and leaves the other quizzes alone.
     */
    public function test_forgetting_a_quiz_forgets_its_items(): void {
        $this->resetAfterTest();
        $exam = $this->exam();
        $one = mirror_store::insert_quiz(7, $exam, 100, 200, 1000);
        $two = mirror_store::insert_quiz(8, $exam, 101, 201, 1000);
        mirror_store::insert_item($one, 'l-01', 1, 501, 1, 1);
        mirror_store::insert_item($two, 'l-01', 1, 601, 1, 1);

        mirror_store::delete_quiz($one);

        $this->assertNull(mirror_store::get_quiz($one));
        $this->assertSame([], mirror_store::items($one));
        $this->assertNotNull(mirror_store::get_quiz($two));
        $this->assertCount(1, mirror_store::items($two));
    }

    /**
     * An attempt of the service has at most one quiz attempt: the database refuses a second one.
     */
    public function test_an_attempt_is_written_once(): void {
        $this->resetAfterTest();
        $exam = $this->exam();
        $attempt = $this->attempt('att-1');
        $mirrorid = mirror_store::insert_quiz(7, $exam, 100, 200, 1000);

        $this->assertNull(mirror_store::find_attempt('att-1'));
        mirror_store::insert_attempt($mirrorid, $attempt, 7, 42, 900, false, 2000);

        $found = mirror_store::find_attempt('att-1');
        $this->assertSame('42', $found->userid);
        $this->assertSame('900', $found->quizattemptid);
        $this->assertSame('0', $found->passed);
        $this->assertSame('1790000000', $found->timefinished);

        $this->expectException(\dml_write_exception::class);
        mirror_store::insert_attempt($mirrorid, $attempt, 7, 42, 901, false, 2001);
    }

    /**
     * The log keeps problems apart from what was done, newest first, and cuts what does not fit.
     */
    public function test_the_log_lists_problems_newest_first(): void {
        $this->resetAfterTest();
        mirror_store::log('definition', 'applied', 200, 'created', ['formid' => 'b2-form-a', 'courseid' => 7], 1000);
        mirror_store::log('attempt', 'rejected', 422, 'unknown_user', ['attemptid' => 'att-1', 'courseid' => 7], 1001);
        mirror_store::log('attempt', 'failed', 500, 'internal_error', ['detail' => str_repeat('x', 400)], 1002);

        $problems = array_values(mirror_store::recent_log());
        $everything = array_values(mirror_store::recent_log(false));

        $this->assertSame(['internal_error', 'unknown_user'], array_column($problems, 'reason'));
        $this->assertCount(3, $everything);
        $this->assertSame(255, \core_text::strlen($problems[0]->detail));
        $this->assertSame('att-1', $problems[1]->attemptid);
        $this->assertSame('0', $problems[0]->courseid);
        $this->assertCount(1, mirror_store::recent_log(true, 1));
    }

    /**
     * The counts the status page shows.
     */
    public function test_counts(): void {
        $this->resetAfterTest();
        $mirrorid = mirror_store::insert_quiz(7, $this->exam(), 100, 200, 1000);
        mirror_store::insert_attempt(
            $mirrorid,
            $this->attempt('att-1'),
            7,
            42,
            900,
            true,
            2000
        );
        mirror_store::log('attempt', 'rejected', 422, 'unknown_user', [], 1500);
        mirror_store::log('attempt', 'failed', 500, 'internal_error', [], 500);
        mirror_store::log('definition', 'applied', 200, 'created', [], 1500);

        $this->assertSame(
            ['definitions' => 0, 'quizzes' => 1, 'attempts' => 1, 'problems' => 1, 'diverged' => 1],
            mirror_store::counts(1000)
        );
    }
}
