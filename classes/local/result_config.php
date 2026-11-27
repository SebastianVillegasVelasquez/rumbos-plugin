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

    /** @var string What the key id of a JWK may look like. It has to fit the audit's column. */
    public const KID_PATTERN = '/^[A-Za-z0-9._-]{1,32}$/D';

    /**
     * @var string The DER of the SubjectPublicKeyInfo of a P-256 key up to its point (RFC 5480): id-ecPublicKey with
     * the curve secp256r1, then the start of the BIT STRING that holds the point.
     */
    private const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

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
     * The one algorithm a configured key verifies. It comes from the type of the key, never from a statement.
     *
     * @param string $pem A key of {@see self::keys()}.
     * @return string "RS256" for an RSA key, "ES256" for an elliptic curve one (only P-256 is ever read).
     */
    public static function algorithm(string $pem): string {
        $details = openssl_pkey_get_details(openssl_pkey_get_public($pem));
        return $details['type'] === OPENSSL_KEYTYPE_EC ? result_statement::SERVICE_ALGORITHM : result_statement::ALGORITHM;
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
     * Two forms, which may stand together. A PEM "PUBLIC KEY" block must be an RSA key of at least
     * {@see self::MIN_KEY_BITS} bits, and its id is {@see self::key_id()}. An elliptic curve P-256 key is given after
     * them as JSON, one JWK or a JWKS ({"keys": [...]}), and its id is the JWK's "kid". A key that is neither is
     * reported and left out; any other text is reported too.
     *
     * @param string $text The setting.
     * @return array{keys: array<string, string>, errors: string[]} Keys (PEM) by id, and the problems found.
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
        $json = str_starts_with($rest, '{') ? json_decode($rest, true) : null;
        if (is_array($json)) {
            $jwks = !array_key_exists('keys', $json) ? [$json] : (is_array($json['keys']) ? $json['keys'] : [null]);
            foreach ($jwks as $jwk) {
                $read = self::read_jwk($jwk);
                if (isset($read['error'])) {
                    $errors[] = $read['error'];
                } else if (isset($keys[$read['kid']])) {
                    // The first one stays: a second key must never take over an id.
                    $errors[] = 'duplicatekid';
                } else {
                    $keys[$read['kid']] = $read['pem'];
                }
            }
        } else if ($rest !== '') {
            $errors[] = 'stray';
        }
        return ['keys' => $keys, 'errors' => array_values(array_unique($errors))];
    }

    /**
     * The public JWK of a P-256 public key given as PEM, as the setting takes it.
     *
     * @param string $pem A PEM "PUBLIC KEY" block. A private key is refused.
     * @param string $kid The key id the service signs with.
     * @return array<string, string>|null Null when it is not a P-256 public key or the id cannot be used.
     */
    public static function jwk_from_pem(string $pem, string $kid): ?array {
        if (str_contains($pem, 'PRIVATE KEY') || preg_match(self::KID_PATTERN, $kid) !== 1) {
            return null;
        }
        $resource = openssl_pkey_get_public($pem);
        $details = $resource === false ? false : openssl_pkey_get_details($resource);
        if ($details === false || ($details['ec']['curve_name'] ?? '') !== 'prime256v1') {
            return null;
        }
        // OpenSSL drops the leading zeros of a coordinate; a JWK keeps all 32 octets (RFC 7518, section 6.2.1.2).
        $coordinate = fn(string $octets): string => rtrim(strtr(base64_encode(
            str_pad($octets, es256::INTEGER_LENGTH, "\x00", STR_PAD_LEFT)
        ), '+/', '-_'), '=');
        return [
            'kty' => 'EC',
            'crv' => 'P-256',
            'alg' => result_statement::SERVICE_ALGORITHM,
            'use' => 'sig',
            'kid' => $kid,
            'x' => $coordinate($details['ec']['x']),
            'y' => $coordinate($details['ec']['y']),
        ];
    }

    /**
     * Reads one public EC P-256 JWK.
     *
     * @param mixed $jwk The decoded JSON.
     * @return array The `kid` and the `pem`, or an `error`.
     */
    private static function read_jwk(mixed $jwk): array {
        if (!is_array($jwk)) {
            return ['error' => 'badjwk'];
        }
        if (array_key_exists('d', $jwk)) {
            return ['error' => 'privatejwk'];
        }
        $valid = ($jwk['kty'] ?? null) === 'EC' && ($jwk['crv'] ?? null) === 'P-256'
            && ($jwk['alg'] ?? result_statement::SERVICE_ALGORITHM) === result_statement::SERVICE_ALGORITHM
            && ($jwk['use'] ?? 'sig') === 'sig'
            && is_string($jwk['kid'] ?? null) && preg_match(self::KID_PATTERN, $jwk['kid']) === 1;
        $point = '';
        foreach (['x', 'y'] as $name) {
            // The 32 octets of a coordinate are 43 base64url characters without padding.
            $value = $jwk[$name] ?? null;
            if (!is_string($value) || preg_match('/^[A-Za-z0-9_-]{43}$/D', $value) !== 1) {
                $valid = false;
            } else {
                $point .= base64_decode(strtr($value, '-_', '+/'));
            }
        }
        if (!$valid) {
            return ['error' => 'badjwk'];
        }
        // The octet 0x04 marks an uncompressed point.
        $der = hex2bin(self::P256_SPKI_PREFIX) . "\x04" . $point;
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
        // OpenSSL refuses a point that is not on the curve.
        if (openssl_pkey_get_public($pem) === false) {
            return ['error' => 'badjwk'];
        }
        return ['kid' => $jwk['kid'], 'pem' => $pem];
    }
}
