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
     * The service's JWKS.
     *
     * @return string
     */
    private function vector_jwks(): string {
        return file_get_contents(__DIR__ . '/../fixtures/service_vectors/jwks.json');
    }

    /**
     * An EC key is read from a JWKS or a single JWK, under its own kid, and verifies ES256; an RSA key beside it
     * keeps verifying RS256.
     */
    public function test_jwk_keys_are_read(): void {
        $jwks = $this->vector_jwks();
        $one = json_encode(json_decode($jwks)->keys[0]);
        $rsa = $this->public_key();

        $parsed = result_config::parse_keys($jwks);
        $this->assertSame([], $parsed['errors']);
        $this->assertSame(['vectors-1'], array_keys($parsed['keys']));
        $this->assertSame($parsed, result_config::parse_keys($one));
        $this->assertSame('ES256', result_config::algorithm($parsed['keys']['vectors-1']));

        $both = result_config::parse_keys($rsa . "\n" . $jwks);
        $this->assertSame([], $both['errors']);
        $this->assertSame([result_config::key_id($rsa), 'vectors-1'], array_keys($both['keys']));
        $this->assertSame('RS256', result_config::algorithm($both['keys'][result_config::key_id($rsa)]));
    }

    /**
     * Anything that is not a public P-256 signing key with a usable kid is reported and left out.
     *
     * @param array $change Members to change in the service's JWK; null removes one.
     * @param string $error
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bad_jwk_provider')]
    public function test_bad_jwks_are_reported(array $change, string $error): void {
        $jwk = json_decode($this->vector_jwks(), true)['keys'][0];
        foreach ($change as $name => $value) {
            if ($value === null) {
                unset($jwk[$name]);
            } else {
                $jwk[$name] = $value;
            }
        }

        $this->assertSame(['keys' => [], 'errors' => [$error]], result_config::parse_keys(json_encode($jwk)));
    }

    /**
     * The bad keys to try.
     *
     * @return array
     */
    public static function bad_jwk_provider(): array {
        return [
            'a private key' => [['d' => 'AAAA'], 'privatejwk'],
            'another type' => [['kty' => 'RSA'], 'badjwk'],
            'an HMAC secret' => [['kty' => 'oct', 'k' => 'AAAA'], 'badjwk'],
            'another curve' => [['crv' => 'P-384'], 'badjwk'],
            'another algorithm' => [['alg' => 'HS256'], 'badjwk'],
            'for encryption' => [['use' => 'enc'], 'badjwk'],
            'no kid' => [['kid' => null], 'badjwk'],
            'a kid too long' => [['kid' => str_repeat('k', 33)], 'badjwk'],
            'a kid with a space' => [['kid' => 'pass back'], 'badjwk'],
            'no x' => [['x' => null], 'badjwk'],
            'x too short' => [['x' => str_repeat('A', 42)], 'badjwk'],
            'x in standard base64' => [['x' => str_repeat('+', 43)], 'badjwk'],
            'a point that is not on the curve' => [['y' => str_repeat('A', 43)], 'badjwk'],
        ];
    }

    /**
     * A second key under a kid already taken does not replace the first; broken JSON and an empty list give no key.
     */
    public function test_duplicate_kids_and_broken_json(): void {
        $jwk = json_decode($this->vector_jwks(), true)['keys'][0];
        $first = result_config::parse_keys(json_encode($jwk))['keys'];
        $other = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $impostor = result_config::jwk_from_pem(openssl_pkey_get_details($other)['key'], 'vectors-1');

        $parsed = result_config::parse_keys(json_encode(['keys' => [$jwk, $impostor]]));
        $this->assertSame(['keys' => $first, 'errors' => ['duplicatekid']], $parsed);

        // Two JWKs one after the other are not JSON.
        $two = json_encode($jwk) . json_encode($jwk);
        $this->assertSame(['keys' => [], 'errors' => ['stray']], result_config::parse_keys($two));
        $this->assertSame(['keys' => [], 'errors' => ['badjwk']], result_config::parse_keys('{"keys": "none"}'));
        $this->assertSame(['keys' => [], 'errors' => []], result_config::parse_keys('{"keys": []}'));
    }

    /**
     * The JWK made from a PEM reads back as the same key, also when a coordinate starts with zero octets, which
     * OpenSSL leaves out. A private key, another type of key or a bad kid gives nothing.
     */
    public function test_a_jwk_is_made_from_a_public_pem(): void {
        $padded = false;
        // One key in 128 has a coordinate shorter than 32 octets.
        for ($i = 0; $i < 3000 && !$padded; $i++) {
            $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
            $details = openssl_pkey_get_details($key);
            $padded = strlen($details['ec']['x']) < 32 || strlen($details['ec']['y']) < 32;
        }
        $this->assertTrue($padded, 'no key with a short coordinate came up');

        $jwk = result_config::jwk_from_pem($details['key'], 'passback-1');

        $this->assertSame([43, 43], [strlen($jwk['x']), strlen($jwk['y'])]);
        $this->assertSame($details['key'], result_config::parse_keys(json_encode($jwk))['keys']['passback-1']);

        openssl_pkey_export($key, $private);
        $this->assertNull(result_config::jwk_from_pem($private, 'passback-1'));
        $this->assertNull(result_config::jwk_from_pem($this->public_key(), 'passback-1'));
        $this->assertNull(result_config::jwk_from_pem($details['key'], 'not a kid'));
        $this->assertNull(result_config::jwk_from_pem('nonsense', 'passback-1'));
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
        $this->assertTrue($setting->validate($this->vector_jwks()));
        $private = json_encode(['d' => 'AAAA'] + json_decode($this->vector_jwks(), true)['keys'][0]);
        $this->assertSame(get_string('resultkeys_error_privatejwk', 'format_rumbos'), $setting->validate($private));
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
