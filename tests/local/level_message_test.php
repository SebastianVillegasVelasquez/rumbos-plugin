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

use format_rumbos\admin\setting_lockedmessage;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests the text of the locked level message and the setting that replaces it.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(level_message::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(setting_lockedmessage::class)]
final class level_message_test extends \advanced_testcase {
    /**
     * With nothing saved, the default language string is used, with the previous level named.
     */
    public function test_default_text(): void {
        $this->resetAfterTest();

        $this->assertSame('', level_message::override());
        $this->assertSame(
            'You need to complete the previous level (A1) to open this level.',
            level_message::locked_text('A1', 'A2')
        );
    }

    /**
     * The Spanish default is in the "tu" form and names the previous level. Read from the file: a language pack is not
     * installed in every test environment.
     */
    public function test_default_text_in_spanish(): void {
        $string = [];
        require(__DIR__ . '/../../lang/es/format_rumbos.php');

        $this->assertSame(
            'Debes completar el nivel anterior ({$a->previous}) para acceder a este nivel.',
            $string['levellocked_body']
        );
        $this->assertStringContainsString('{$a->previous}', $string['levellocked_body']);
    }

    /**
     * A saved text replaces the default and both names are filled in; a blank text is the default again.
     */
    public function test_override_and_placeholders(): void {
        $this->resetAfterTest();

        set_config(level_message::SETTING, "  Termina {previous} para abrir {level}. {previous}  ", 'format_rumbos');
        $this->assertSame('Termina A1 para abrir A2. A1', level_message::locked_text('A1', 'A2'));

        set_config(level_message::SETTING, "  \n ", 'format_rumbos');
        $this->assertStringStartsWith('You need to complete', level_message::locked_text('A1', 'A2'));
    }

    /**
     * A name that looks like a placeholder is not expanded a second time.
     */
    public function test_names_are_not_expanded_again(): void {
        $this->resetAfterTest();
        set_config(level_message::SETTING, '{previous} / {level}', 'format_rumbos');

        $this->assertSame('{level} / B', level_message::locked_text('{level}', 'B'));
    }

    /**
     * The setting accepts plain text up to the limit and refuses markup and longer text.
     */
    public function test_the_setting_validates(): void {
        $this->resetAfterTest();
        $setting = new setting_lockedmessage('format_rumbos/' . level_message::SETTING, 'x', 'y');

        $this->assertTrue($setting->validate(''));
        $this->assertTrue($setting->validate('Debes completar {previous}. 5 < 6 y 7 > 3'));
        $this->assertNotTrue($setting->validate('<b>Hola</b>'));
        $this->assertNotTrue($setting->validate('<script>alert(1)</script>'));
        $this->assertTrue($setting->validate(str_repeat('a', level_message::MAX_LENGTH)));
        $this->assertNotTrue($setting->validate(str_repeat('a', level_message::MAX_LENGTH + 1)));
    }
}
