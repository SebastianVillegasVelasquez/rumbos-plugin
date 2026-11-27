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
 * ES256 signatures: the form a JWS carries, turned into the form OpenSSL verifies.
 *
 * A JWS carries `R || S`: two unsigned big-endian integers of exactly 32 octets each, 64 in all (RFC 7518, section
 * 3.4). openssl_verify() wants the DER encoding of `Ecdsa-Sig-Value ::= SEQUENCE { r INTEGER, s INTEGER }` (RFC 3279,
 * section 2.2.3). A DER INTEGER is signed two's complement in as few octets as possible (ITU-T X.690, section 8.3): the
 * leading zero octets are removed, and one is put back when the first remaining octet has its high bit set, so that the
 * number is not read as negative. A DER form is at most 72 octets, so every length fits in one octet.
 *
 * The JWT library Moodle ships also converts, but it cuts any signature in two halves whatever its length, so the
 * length rule of RFC 7518 would not be enforced.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class es256 {
    /** @var int Octets of each of R and S for the P-256 curve. */
    public const INTEGER_LENGTH = 32;

    /**
     * The DER form of a raw ES256 signature.
     *
     * @param string $raw `R || S`.
     * @return string|null Null when it is not exactly 64 octets.
     */
    public static function der_signature(string $raw): ?string {
        if (strlen($raw) !== 2 * self::INTEGER_LENGTH) {
            return null;
        }
        $body = self::der_integer(substr($raw, 0, self::INTEGER_LENGTH))
            . self::der_integer(substr($raw, self::INTEGER_LENGTH));
        return "\x30" . chr(strlen($body)) . $body;
    }

    /**
     * Whether a raw signature is a valid ES256 signature of a text under a public key.
     *
     * @param string $input The signed text: the first two segments of the JWS and the dot between them.
     * @param string $raw `R || S`.
     * @param string $pem The EC P-256 public key.
     * @return bool
     */
    public static function verify(string $input, string $raw, string $pem): bool {
        $der = self::der_signature($raw);
        // Only 1 means valid: openssl_verify() answers 0 for a wrong signature and -1 or false for an error.
        return $der !== null && openssl_verify($input, $der, $pem, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * One unsigned big-endian integer as a DER INTEGER.
     *
     * @param string $octets
     * @return string
     */
    private static function der_integer(string $octets): string {
        $value = ltrim($octets, "\x00");
        // Zero keeps one octet; a high bit would make the number negative.
        if ($value === '' || ord($value[0]) > 0x7f) {
            $value = "\x00" . $value;
        }
        return "\x02" . chr(strlen($value)) . $value;
    }
}
