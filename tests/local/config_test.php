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
 * Tests for the minimum cohort setting.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\local\config::class)]
final class config_test extends \advanced_testcase {
    /**
     * Unset means the default of 5.
     */
    public function test_min_cohort_defaults_to_five(): void {
        $this->resetAfterTest();
        unset_config('mincohort', 'format_rumbos');
        $this->assertSame(5, config::get_min_cohort());
    }

    /**
     * A stored value is used, but never below 1: zero or a negative number would switch the protection off.
     */
    public function test_min_cohort_is_at_least_one(): void {
        $this->resetAfterTest();
        set_config('mincohort', '12', 'format_rumbos');
        $this->assertSame(12, config::get_min_cohort());
        foreach (['0', '-3', 'abc'] as $value) {
            set_config('mincohort', $value, 'format_rumbos');
            $this->assertSame(1, config::get_min_cohort(), $value);
        }
    }
}
