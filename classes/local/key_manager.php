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

use core\encryption;

/**
 * Owns the RSA signing key pair and the per-site HMAC secret.
 *
 * Both secrets are stored encrypted at rest with \core\encryption (sodium,
 * key file in dataroot). Nothing here is ever logged, rendered or cached in
 * clear text; only the public key and its kid leave this class.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class key_manager {
    /** @var string Plugin config component. */
    private const COMPONENT = 'format_rumbos';

    /** @var int RSA modulus size in bits. */
    private const KEY_BITS = 2048;

    /**
     * Creates the key pair and the HMAC secret if they do not exist yet.
     *
     * The HMAC secret is generated exactly once and survives key rotation,
     * so pseudonymous ids stay stable.
     */
    public static function ensure_initialised(): void {
        if (!self::has_key_pair()) {
            self::generate_key_pair();
        }
        if (self::stored('hmacsecret') === '') {
            set_config('hmacsecret', encryption::encrypt(bin2hex(random_bytes(32))), self::COMPONENT);
        }
    }

    /**
     * Replaces the key pair with a new one. The HMAC secret is untouched.
     */
    public static function rotate(): void {
        self::generate_key_pair();
    }

    /**
     * Whether the stored secrets can be decrypted with this site's encryption key.
     *
     * They cannot when Moodle's encryption key file (in dataroot/secret) was lost or
     * replaced, for example by restoring the database without it. Rotation does not
     * help in that case, because the HMAC secret is unreadable too: see reset_all().
     *
     * @return bool
     */
    public static function is_healthy(): bool {
        try {
            self::get_private_key();
            self::get_hmac_secret();
            return true;
        } catch (\moodle_exception $e) {
            return false;
        }
    }

    /**
     * Replaces the key pair AND the HMAC secret.
     *
     * Last resort when the stored secrets cannot be decrypted. Every pseudonymous
     * id changes, so the Rumbos service sees all users as new. Normal key rotation
     * keeps the HMAC secret and does not have this effect.
     */
    public static function reset_all(): void {
        self::generate_key_pair();
        set_config('hmacsecret', encryption::encrypt(bin2hex(random_bytes(32))), self::COMPONENT);
    }

    /**
     * PEM encoded public key.
     *
     * @return string
     */
    public static function get_public_key(): string {
        self::ensure_initialised();
        return self::stored('publickey');
    }

    /**
     * Identifier of the current key pair (carried in the JWS header).
     *
     * @return string
     */
    public static function get_kid(): string {
        self::ensure_initialised();
        return self::stored('kid');
    }

    /**
     * Decrypted PEM private key. Only the signer should call this.
     *
     * @return string
     */
    public static function get_private_key(): string {
        self::ensure_initialised();
        return self::decrypt_stored('privatekey');
    }

    /**
     * Decrypted per-site HMAC secret used for pseudonymous ids.
     *
     * @return string
     */
    public static function get_hmac_secret(): string {
        self::ensure_initialised();
        return self::decrypt_stored('hmacsecret');
    }

    /**
     * Decrypts a stored secret, turning a decryption failure into an actionable error.
     *
     * @param string $name Config name of the encrypted value.
     * @return string
     * @throws \moodle_exception With errorkeydecrypt when it cannot be decrypted.
     */
    private static function decrypt_stored(string $name): string {
        try {
            return encryption::decrypt(self::stored($name));
        } catch (\moodle_exception $e) {
            throw new \moodle_exception('errorkeydecrypt', 'format_rumbos');
        }
    }

    /**
     * Whether a complete key pair is stored.
     *
     * @return bool
     */
    private static function has_key_pair(): bool {
        return self::stored('privatekey') !== '' && self::stored('publickey') !== '' && self::stored('kid') !== '';
    }

    /**
     * Reads a plugin config value as a string.
     *
     * @param string $name
     * @return string
     */
    private static function stored(string $name): string {
        $value = get_config(self::COMPONENT, $name);
        return $value === false ? '' : (string) $value;
    }

    /**
     * Generates and stores a fresh RSA key pair.
     *
     * @throws \moodle_exception When OpenSSL cannot generate the key.
     */
    private static function generate_key_pair(): void {
        $resource = openssl_pkey_new([
            'private_key_bits' => self::KEY_BITS,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($resource === false || !openssl_pkey_export($resource, $privatepem)) {
            throw new \moodle_exception('errorkeygeneration', 'format_rumbos');
        }
        $details = openssl_pkey_get_details($resource);
        $publicpem = $details['key'];

        // The private key goes in first: a crash in between leaves an old
        // public key, never a public key without its private counterpart.
        set_config('privatekey', encryption::encrypt($privatepem), self::COMPONENT);
        set_config('publickey', $publicpem, self::COMPONENT);
        set_config('kid', substr(hash('sha256', $publicpem), 0, 16), self::COMPONENT);
    }
}
