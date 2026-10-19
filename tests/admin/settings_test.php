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

use format_rumbos\local\key_manager;

/**
 * Tests for the admin settings.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\admin\setting_appurl::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\admin\setting_publickey::class)]
final class settings_test extends \advanced_testcase {
    /**
     * Starts every test without stored keys.
     *
     * The baseline database holds keys that were encrypted at install time with
     * a key file PHPUnit discards between tests, so they could not be decrypted.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        foreach (['privatekey', 'publickey', 'kid', 'hmacsecret'] as $name) {
            unset_config($name, 'format_rumbos');
        }
    }

    /**
     * The URL setting stores the normalised https URL.
     */
    public function test_appurl_is_stored_normalised(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $setting = new setting_appurl('format_rumbos/appurl', 'App URL', '');

        $this->assertSame('', $setting->write_setting('  HTTPS://Rumbos.Example.com/app/ '));

        $this->assertSame('https://rumbos.example.com/app', get_config('format_rumbos', 'appurl'));
    }

    /**
     * Anything that is not https is refused and nothing is stored.
     */
    public function test_appurl_rejects_http(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $setting = new setting_appurl('format_rumbos/appurl', 'App URL', '');

        $this->assertNotSame(true, $setting->validate('http://rumbos.example.com'));
        $this->assertNotSame('', $setting->write_setting('http://rumbos.example.com'));

        $this->assertEmpty(get_config('format_rumbos', 'appurl'));
    }

    /**
     * An empty value is allowed: the app is simply not configured.
     */
    public function test_appurl_may_be_empty(): void {
        $this->resetAfterTest();
        $setting = new setting_appurl('format_rumbos/appurl', 'App URL', '');

        $this->assertTrue($setting->validate(''));
    }

    /**
     * The public key setting is read-only, shows the key and never the private key.
     */
    public function test_publickey_setting_is_read_only_and_never_leaks_the_private_key(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $setting = new setting_publickey('format_rumbos/publickeyinfo', 'Public key', '');

        $this->assertSame('', $setting->write_setting('anything'));
        $this->assertFalse(get_config('format_rumbos', 'publickeyinfo'));

        $html = $setting->output_html(true);
        $this->assertStringContainsString('BEGIN PUBLIC KEY', $html);
        $this->assertStringContainsString(key_manager::get_kid(), $html);
        $this->assertStringNotContainsString('PRIVATE KEY', $html);
        $this->assertStringContainsString('data-action="copytoclipboard"', $html);
        $this->assertStringContainsString('rotatekey.php', $html);
    }
}
