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

use format_rumbos\admin\setting_resultkeys;

/**
 * Tests for the receiver's settings and its keys.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\local\result_config::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\admin\setting_resultkeys::class)]
final class result_config_test extends \advanced_testcase {
    /**
     * Makes a public key.
     *
     * @param int $bits
     * @param int $type OPENSSL_KEYTYPE_RSA or OPENSSL_KEYTYPE_EC.
     * @return string PEM.
     */
    private function public_key(int $bits = 2048, int $type = OPENSSL_KEYTYPE_RSA): string {
        $shape = $type === OPENSSL_KEYTYPE_EC ? ['curve_name' => 'prime256v1'] : ['private_key_bits' => $bits];
        $options = ['private_key_type' => $type] + $shape;
        return openssl_pkey_get_details(openssl_pkey_new($options))['key'];
    }

    /**
     * The key id is the start of the fingerprint of the DER form: the same for any line wrapping.
     */
    public function test_the_key_id_is_canonical(): void {
        $pem = $this->public_key();
        $crlf = str_replace("\n", "\r\n", $pem);
        $body = preg_replace('/-----[A-Z ]+-----|\s/', '', $pem);
        $unwrapped = "-----BEGIN PUBLIC KEY-----\n" . $body . "\n-----END PUBLIC KEY-----\n";

        $this->assertSame(substr(key_manager::fingerprint_of($pem), 0, 16), result_config::key_id($pem));
        $this->assertSame(result_config::key_id($pem), result_config::key_id($crlf));
        $this->assertSame(result_config::key_id($pem), result_config::key_id($unwrapped));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', result_config::key_id($pem));
    }

    /**
     * Several keys are read, each under its own id.
     */
    public function test_several_keys(): void {
        $one = $this->public_key();
        $two = $this->public_key();

        $parsed = result_config::parse_keys("# the first\n" . $one . "\n" . $two);

        $this->assertCount(2, $parsed['keys']);
        $this->assertArrayHasKey(result_config::key_id($one), $parsed['keys']);
        $this->assertArrayHasKey(result_config::key_id($two), $parsed['keys']);
        // Text outside the blocks, even a comment, is reported: nothing is silently ignored.
        $this->assertSame(['stray'], $parsed['errors']);
    }

    /**
     * Weak keys, keys of another type and garbage are reported and left out.
     */
    public function test_bad_keys_are_reported(): void {
        $weak = $this->public_key(1024);
        $ec = $this->public_key(0, OPENSSL_KEYTYPE_EC);
        $garbage = "-----BEGIN PUBLIC KEY-----\nAAAA\n-----END PUBLIC KEY-----";

        $this->assertSame(['keys' => [], 'errors' => ['tooweak']], result_config::parse_keys($weak));
        $this->assertSame(['keys' => [], 'errors' => ['notrsa']], result_config::parse_keys($ec));
        $this->assertSame(['keys' => [], 'errors' => ['notrsa']], result_config::parse_keys($garbage));
        $this->assertSame(['keys' => [], 'errors' => ['stray']], result_config::parse_keys('not a key'));
        $this->assertSame(['keys' => [], 'errors' => []], result_config::parse_keys(''));
    }

    /**
     * The settings page refuses text it would have to ignore, and accepts an empty value.
     */
    public function test_the_setting_validates(): void {
        $setting = new setting_resultkeys('format_rumbos/resultkeys', 'Keys', 'Desc', '');

        $this->assertTrue($setting->validate(''));
        $this->assertTrue($setting->validate($this->public_key()));
        $this->assertSame(get_string('resultkeys_error_tooweak', 'format_rumbos'), $setting->validate($this->public_key(1024)));
        $notrsa = get_string('resultkeys_error_notrsa', 'format_rumbos');
        $this->assertSame($notrsa, $setting->validate($this->public_key(0, OPENSSL_KEYTYPE_EC)));
        $this->assertSame(get_string('resultkeys_error_stray', 'format_rumbos'), $setting->validate('oops'));
    }

    /**
     * The receiver is ready only with everything configured, and the endpoint is the script of the plugin.
     */
    public function test_readiness(): void {
        $this->resetAfterTest();
        $this->assertFalse(result_config::is_ready());

        set_config('resultsenabled', 1, 'format_rumbos');
        set_config('resultissuer', 'rumbos', 'format_rumbos');
        set_config('resulttenant', 't1', 'format_rumbos');
        $this->assertFalse(result_config::is_ready(), 'no key yet');

        set_config('resultkeys', $this->public_key(), 'format_rumbos');
        $this->assertTrue(result_config::is_ready());
        $this->assertStringEndsWith('/course/format/rumbos/passback.php', result_config::endpoint_url());
    }
}
