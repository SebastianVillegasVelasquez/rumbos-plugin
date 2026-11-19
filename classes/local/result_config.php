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
 * Settings of the result receiver: whether it is on, who it trusts, and with which public keys.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class result_config {
    /** @var int Smallest RSA key accepted, in bits. */
    public const MIN_KEY_BITS = 2048;

    /**
     * Whether the receiver is on and has everything it needs to verify a statement.
     *
     * @return bool
     */
    public static function is_ready(): bool {
        return (bool) get_config('format_rumbos', 'resultsenabled')
            && self::issuer() !== ''
            && self::tenant() !== ''
            && self::keys() !== [];
    }

    /**
     * The issuer ("iss") statements must carry.
     *
     * @return string
     */
    public static function issuer(): string {
        return trim((string) get_config('format_rumbos', 'resultissuer'));
    }

    /**
     * The tenant statements must carry.
     *
     * @return string
     */
    public static function tenant(): string {
        return trim((string) get_config('format_rumbos', 'resulttenant'));
    }

    /**
     * The trusted public keys by key id.
     *
     * @return array<string, string> Key id => PEM.
     */
    public static function keys(): array {
        return self::parse_keys((string) get_config('format_rumbos', 'resultkeys'))['keys'];
    }

    /**
     * The address of the receiver script, as the backend must call it.
     *
     * @return string
     */
    public static function endpoint_url(): string {
        return (new \moodle_url('/course/format/rumbos/passback.php'))->out(false);
    }

    /**
     * The id of a public key: the first 16 hexadecimal characters of the SHA-256 of its DER SubjectPublicKeyInfo.
     *
     * @param string $pem A PEM "PUBLIC KEY" block.
     * @return string
     * @throws \coding_exception When the text is not a PEM public key.
     */
    public static function key_id(string $pem): string {
        return substr(key_manager::fingerprint_of($pem), 0, 16);
    }

    /**
     * Splits the text of the setting into keys, and reports what is wrong with it.
     *
     * Every "PUBLIC KEY" block must be an RSA key of at least {@see self::MIN_KEY_BITS} bits. A block that is not
     * is reported and left out; text that is not in a block is reported too.
     *
     * @param string $text The setting.
     * @return array{keys: array<string, string>, errors: string[]} Keys by id, and the problems found.
     */
    public static function parse_keys(string $text): array {
        $keys = [];
        $errors = [];
        $pattern = '/-----BEGIN PUBLIC KEY-----.+?-----END PUBLIC KEY-----/s';
        preg_match_all($pattern, $text, $matches);
        foreach ($matches[0] as $block) {
            $pem = preg_replace('/\s*\R\s*/', "\n", trim($block)) . "\n";
            $resource = openssl_pkey_get_public($pem);
            $details = $resource === false ? false : openssl_pkey_get_details($resource);
            if ($details === false || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
                $errors[] = 'notrsa';
                continue;
            }
            if (($details['bits'] ?? 0) < self::MIN_KEY_BITS) {
                $errors[] = 'tooweak';
                continue;
            }
            $keys[self::key_id($pem)] = $pem;
        }
        $rest = trim(preg_replace($pattern, '', $text));
        if ($rest !== '') {
            $errors[] = 'stray';
        }
        return ['keys' => $keys, 'errors' => array_values(array_unique($errors))];
    }
}
