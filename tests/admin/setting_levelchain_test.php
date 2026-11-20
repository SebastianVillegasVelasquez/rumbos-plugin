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

namespace format_rumbos\admin;

/**
 * Tests for the level chain admin setting.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\admin\setting_levelchain::class)]
final class setting_levelchain_test extends \advanced_testcase {
    /**
     * Creates the setting.
     *
     * @return setting_levelchain
     */
    private function setting(): setting_levelchain {
        return new setting_levelchain('format_rumbos/levelchain', 'Level chain', 'Description');
    }

    /**
     * An empty chain and a chain of existing courses are saved in one canonical form.
     */
    public function test_valid_chains_are_saved_canonically(): void {
        $this->resetAfterTest();
        $this->getDataGenerator()->create_course(['idnumber' => '1000']);
        $this->getDataGenerator()->create_course(['idnumber' => '1001']);
        $setting = $this->setting();

        $this->assertSame('', $setting->write_setting(''));
        $this->assertSame('', $setting->write_setting(" 1000 ;\n1001 "));
        $this->assertSame('1000,1001', get_config('format_rumbos', 'levelchain'));
    }

    /**
     * A missing, repeated or shared ID number is refused with a message that names it, and nothing is saved.
     */
    public function test_invalid_chains_are_refused(): void {
        global $DB;
        $this->resetAfterTest();
        $this->getDataGenerator()->create_course(['idnumber' => '1000']);
        $this->getDataGenerator()->create_course(['idnumber' => '1001']);
        $shared = $this->getDataGenerator()->create_course(['idnumber' => '1002']);
        $DB->set_field('course', 'idnumber', '1001', ['id' => $shared->id]);
        $setting = $this->setting();

        $this->assertStringContainsString('9999', $setting->write_setting('1000,9999'));
        $this->assertStringContainsString('1000', $setting->write_setting('1000,1000'));
        $this->assertStringContainsString('1001', $setting->write_setting('1000,1001'));
        $this->assertStringContainsString('100', $setting->write_setting(str_repeat('x', 101)));
        $this->assertSame('', (string) get_config('format_rumbos', 'levelchain'));
    }

    /**
     * Cleaning: the ID number is escaped in the message, so a crafted value cannot inject markup.
     */
    public function test_the_message_escapes_the_value(): void {
        $this->resetAfterTest();

        $message = $this->setting()->write_setting('<b>x</b>');

        $this->assertStringNotContainsString('<b>', $message);
    }
}
