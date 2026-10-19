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
 * Tests for the key manager and the pseudonymous ids.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\local\key_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\local\pseudonym::class)]
final class key_manager_test extends \advanced_testcase {
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
     * Keys are created lazily and are a usable RSA pair.
     */
    public function test_keys_are_generated_on_first_use(): void {
        $this->assertFalse(get_config('format_rumbos', 'publickey'));

        $public = key_manager::get_public_key();
        $this->assertStringStartsWith('-----BEGIN PUBLIC KEY-----', $public);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', key_manager::get_kid());

        $private = key_manager::get_private_key();
        $this->assertStringContainsString('PRIVATE KEY-----', $private);
        $pubresource = openssl_pkey_get_public($public);
        $privresource = openssl_pkey_get_private($private);
        $this->assertNotFalse($pubresource);
        $this->assertNotFalse($privresource);
        $this->assertSame(
            openssl_pkey_get_details($pubresource)['key'],
            openssl_pkey_get_details($privresource)['key']
        );
    }

    /**
     * Secrets are encrypted at rest and never stored in clear text.
     */
    public function test_secrets_are_encrypted_at_rest(): void {
        $this->resetAfterTest();
        key_manager::ensure_initialised();

        $storedprivate = get_config('format_rumbos', 'privatekey');
        $storedsecret = get_config('format_rumbos', 'hmacsecret');
        $this->assertStringNotContainsString('PRIVATE KEY', $storedprivate);
        $this->assertSame(key_manager::get_private_key(), \core\encryption::decrypt($storedprivate));
        $this->assertSame(key_manager::get_hmac_secret(), \core\encryption::decrypt($storedsecret));
        $this->assertNotSame(key_manager::get_hmac_secret(), $storedsecret);
    }

    /**
     * Initialising twice does not replace anything.
     */
    public function test_ensure_initialised_is_idempotent(): void {
        $this->resetAfterTest();
        $kid = key_manager::get_kid();
        $secret = key_manager::get_hmac_secret();

        key_manager::ensure_initialised();

        $this->assertSame($kid, key_manager::get_kid());
        $this->assertSame($secret, key_manager::get_hmac_secret());
    }

    /**
     * Rotation changes the key pair but keeps the HMAC secret, so ids are stable.
     */
    public function test_rotate_replaces_keys_but_not_the_hmac_secret(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $kid = key_manager::get_kid();
        $public = key_manager::get_public_key();
        $pseudonym = pseudonym::for_user($user->id);

        key_manager::rotate();

        $this->assertNotSame($kid, key_manager::get_kid());
        $this->assertNotSame($public, key_manager::get_public_key());
        $this->assertSame($pseudonym, pseudonym::for_user($user->id));
    }

    /**
     * Freshly generated secrets are healthy.
     */
    public function test_keys_are_healthy_when_decryptable(): void {
        $this->assertTrue(key_manager::is_healthy());
    }

    /**
     * Secrets that cannot be decrypted (the encryption key file was lost) raise a clear error.
     */
    public function test_undecryptable_secrets_raise_an_actionable_error(): void {
        key_manager::ensure_initialised();
        // Simulate secrets encrypted with a key file that no longer exists.
        set_config('privatekey', base64_encode(random_bytes(80)), 'format_rumbos');
        set_config('hmacsecret', base64_encode(random_bytes(80)), 'format_rumbos');

        $this->assertFalse(key_manager::is_healthy());
        try {
            key_manager::get_hmac_secret();
            $this->fail('Expected an exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorkeydecrypt', $e->errorcode);
        }
        $this->expectException(\moodle_exception::class);
        key_manager::get_private_key();
    }

    /**
     * Plain rotation does not heal undecryptable secrets, but a full reset does.
     */
    public function test_reset_all_heals_undecryptable_secrets_and_changes_pseudonyms(): void {
        $user = $this->getDataGenerator()->create_user();
        $before = pseudonym::for_user($user->id);
        set_config('hmacsecret', base64_encode(random_bytes(80)), 'format_rumbos');
        $this->assertFalse(key_manager::is_healthy());

        key_manager::rotate();
        $this->assertFalse(key_manager::is_healthy(), 'rotation alone cannot fix the HMAC secret');

        key_manager::reset_all();

        $this->assertTrue(key_manager::is_healthy());
        $this->assertNotSame($before, pseudonym::for_user($user->id));
    }

    /**
     * The pseudonym matches the contract: 32 lowercase hex of HMAC-SHA256.
     */
    public function test_pseudonym_follows_the_contract(): void {
        $this->resetAfterTest();
        $expected = substr(hash_hmac('sha256', '42', key_manager::get_hmac_secret()), 0, 32);

        $this->assertSame($expected, pseudonym::for_user(42));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', pseudonym::for_user(42));
    }

    /**
     * Different users get different ids and the id is not the Moodle id.
     */
    public function test_pseudonyms_differ_per_user(): void {
        $this->resetAfterTest();
        $this->assertNotSame(pseudonym::for_user(1), pseudonym::for_user(2));
        $this->assertNotSame('42', pseudonym::for_user(42));
    }
}
