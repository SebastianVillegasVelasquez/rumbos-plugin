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
 * Tests for the conversion of ES256 signatures.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\local\es256::class)]
final class es256_test extends \basic_testcase {
    /**
     * Known encodings: the expected bytes are written out by the DER rules, not produced by the code.
     *
     * @param string $r 32 octets, hexadecimal.
     * @param string $s 32 octets, hexadecimal.
     * @param string $der The expected DER, hexadecimal.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('encoding_provider')]
    public function test_known_encodings(string $r, string $s, string $der): void {
        $this->assertSame($der, bin2hex(es256::der_signature(hex2bin($r . $s))));
    }

    /**
     * The encodings to check.
     *
     * @return array
     */
    public static function encoding_provider(): array {
        // 32 octets that need neither a zero added nor removed.
        $plain = str_repeat('11', 32);
        $high = 'ff' . str_repeat('22', 31);
        return [
            'both as they are' => [$plain, $plain, '3044' . '0220' . $plain . '0220' . $plain],
            'high bit: a zero is added, 33 octets' => [$high, $high, '3046' . '022100' . $high . '022100' . $high],
            'one leading zero is removed' => [
                '00' . str_repeat('33', 31),
                $plain,
                '3043' . '021f' . str_repeat('33', 31) . '0220' . $plain,
            ],
            'several leading zeros are removed' => [
                $plain,
                '000000' . str_repeat('44', 29),
                '3041' . '0220' . $plain . '021d' . str_repeat('44', 29),
            ],
            'a leading zero before a high bit is kept' => [
                '0080' . str_repeat('55', 30),
                $plain,
                '3044' . '0220' . '0080' . str_repeat('55', 30) . '0220' . $plain,
            ],
            'leading zeros before a high bit leave one' => [
                '000090' . str_repeat('66', 29),
                $plain,
                '3043' . '021f' . '0090' . str_repeat('66', 29) . '0220' . $plain,
            ],
            'short integers of one octet' => [
                str_repeat('00', 31) . '01',
                str_repeat('00', 31) . '80',
                '3007' . '020101' . '02020080',
            ],
            'zero keeps one octet' => [
                str_repeat('00', 32),
                str_repeat('00', 31) . '7f',
                '3006' . '020100' . '02017f',
            ],
        ];
    }

    /**
     * Only 64 octets are a signature: a DER signature, or one short or long by an octet, is not.
     */
    public function test_other_lengths_are_refused(): void {
        foreach ([0, 1, 32, 63, 65, 70, 72, 96, 128] as $length) {
            $this->assertNull(es256::der_signature(str_repeat("\x01", $length)), $length . ' octets');
        }
    }

    /**
     * Signatures OpenSSL makes, cut down to R||S and converted back, are its own DER again and verify. Enough of
     * them are made that some have an integer shorter than 32 octets and some need the added zero.
     */
    public function test_real_signatures_survive_the_round_trip(): void {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $public = openssl_pkey_get_details($key)['key'];
        $short = 0;
        $padded = 0;

        for ($i = 0; $i < 1500; $i++) {
            $input = 'message ' . $i;
            openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256);
            $raw = '';
            $at = 2;
            foreach (['r', 's'] as $name) {
                $length = ord($der[$at + 1]);
                $integer = substr($der, $at + 2, $length);
                $short += $length < 32 ? 1 : 0;
                $padded += $length === 33 ? 1 : 0;
                $raw .= str_pad(ltrim($integer, "\x00"), 32, "\x00", STR_PAD_LEFT);
                $at += 2 + $length;
            }

            $this->assertSame(bin2hex($der), bin2hex(es256::der_signature($raw)));
            $this->assertTrue(es256::verify($input, $raw, $public));
            $this->assertFalse(es256::verify($input . '.', $raw, $public));
        }

        $this->assertGreaterThan(0, $short, 'no short integer came up');
        $this->assertGreaterThan(0, $padded, 'no integer needed a zero');
    }

    /**
     * A key that cannot be read verifies nothing.
     */
    public function test_an_unreadable_key_verifies_nothing(): void {
        $this->expectOutputString('');
        $this->assertFalse(@es256::verify('input', str_repeat("\x01", 64), 'not a key'));
    }
}
