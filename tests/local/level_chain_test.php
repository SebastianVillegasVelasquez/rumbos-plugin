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

namespace format_rumbos\local;

/**
 * Tests for the level chain: parsing, the predecessor and the validation.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\local\level_chain::class)]
final class level_chain_test extends \advanced_testcase {
    /**
     * Separators and spaces are tolerated, empty entries dropped, order kept.
     */
    public function test_parse(): void {
        $this->assertSame(['1000', '1001', 'B 1', '1002'], level_chain::parse(" 1000, 1001 ;B 1\n\n,1002 ,"));
        $this->assertSame([], level_chain::parse(''));
        $this->assertSame([], level_chain::parse(" , \n "));
    }

    /**
     * The predecessor is the entry before; the first and unknown levels have none.
     */
    public function test_predecessor(): void {
        $chain = ['1000', '1001', '1003', '1002'];

        $this->assertNull(level_chain::predecessor($chain, '1000'));
        $this->assertSame('1000', level_chain::predecessor($chain, '1001'));
        $this->assertSame('1001', level_chain::predecessor($chain, '1003'));
        $this->assertSame('1003', level_chain::predecessor($chain, '1002'));
        $this->assertNull(level_chain::predecessor($chain, '9999'));
        $this->assertNull(level_chain::predecessor($chain, ''));
        $this->assertNull(level_chain::predecessor([], '1000'));
    }

    /**
     * Matching is exact: no numeric coercion ("01001" is not "1001").
     */
    public function test_predecessor_is_an_exact_string_match(): void {
        $this->assertNull(level_chain::predecessor(['1000', '1001'], '01001'));
        $this->assertNull(level_chain::predecessor(['1000', '1001'], '1001.0'));
    }

    /**
     * A chain of existing, distinct courses is valid; so is an empty one.
     */
    public function test_a_valid_chain(): void {
        $this->resetAfterTest();
        $this->getDataGenerator()->create_course(['idnumber' => '1000']);
        $this->getDataGenerator()->create_course(['idnumber' => '1001']);

        $this->assertSame(level_chain::OK, level_chain::validate(['1000', '1001'])['error']);
        $this->assertSame(level_chain::OK, level_chain::validate([])['error']);
    }

    /**
     * A repeated ID number is refused (it is the only way a straight chain could loop).
     */
    public function test_duplicates_are_refused(): void {
        $this->resetAfterTest();
        $this->getDataGenerator()->create_course(['idnumber' => '1000']);
        $this->getDataGenerator()->create_course(['idnumber' => '1001']);

        $result = level_chain::validate(['1000', '1001', '1000']);

        $this->assertSame(level_chain::ERROR_DUPLICATE, $result['error']);
        $this->assertSame('1000', $result['idnumber']);
    }

    /**
     * An ID number no course has is refused.
     */
    public function test_a_missing_course_is_refused(): void {
        $this->resetAfterTest();
        $this->getDataGenerator()->create_course(['idnumber' => '1000']);

        $result = level_chain::validate(['1000', '1001']);

        $this->assertSame(level_chain::ERROR_MISSING, $result['error']);
        $this->assertSame('1001', $result['idnumber']);
    }

    /**
     * An ID number two courses share is refused: the prerequisite would be a guess.
     */
    public function test_an_ambiguous_idnumber_is_refused(): void {
        global $DB;
        $this->resetAfterTest();
        $this->getDataGenerator()->create_course(['idnumber' => '1000']);
        $this->getDataGenerator()->create_course(['idnumber' => '1001']);
        $other = $this->getDataGenerator()->create_course(['idnumber' => '1002']);
        // The course form refuses duplicates, the database does not.
        $DB->set_field('course', 'idnumber', '1001', ['id' => $other->id]);

        $result = level_chain::validate(['1000', '1001']);

        $this->assertSame(level_chain::ERROR_AMBIGUOUS, $result['error']);
        $this->assertSame('1001', $result['idnumber']);
    }

    /**
     * The front page never counts as a level, even with an ID number.
     */
    public function test_the_front_page_is_not_a_level(): void {
        global $DB;
        $this->resetAfterTest();
        $DB->set_field('course', 'idnumber', 'FRONT', ['id' => SITEID]);

        $this->assertSame(level_chain::ERROR_MISSING, level_chain::validate(['FRONT'])['error']);
    }
}
