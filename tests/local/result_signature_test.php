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

use Firebase\JWT\JWT;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/result_testcase.php');

/**
 * Tests for the one place a signature is checked, against the golden vectors of the Rumbos service.
 *
 * The vectors were signed by the service's own code. Where a case needs claims they do not hold (a time, say), a key
 * made here signs them with the JWT library, so the conversion under test never checks its own output.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\local\result_statement::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\local\es256::class)]
final class result_signature_test extends \format_rumbos\result_testcase {
    /** @var int The iat of the vectors. */
    private const IAT = 1795000000;

    /**
     * Trusts the keys of the vectors only.
     */
    protected function setUp(): void {
        parent::setUp();
        set_config('resultkeys', $this->vector_file('jwks.json'), 'format_rumbos');
    }

    /**
     * The statements the service signs.
     *
     * @return array
     */
    public static function vector_provider(): array {
        return ['result' => ['result'], 'attempt' => ['attempt'], 'definition' => ['definition']];
    }

    /**
     * Every golden vector verifies with the JWKS pasted into the setting as it is, and decodes to its claims.
     *
     * @param string $name
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('vector_provider')]
    public function test_the_golden_vectors_verify(string $name): void {
        $vector = $this->vector($name);

        $claims = result_statement::decode($vector->jws, self::IAT + 1);

        $this->assertEquals($vector->claims, $claims);
    }

    /**
     * The outbox may deliver weeks later: there is no exp, and an old iat is accepted.
     */
    public function test_a_statement_delivered_a_month_later_still_verifies(): void {
        $vector = $this->vector('result');

        $this->assertEquals($vector->claims, result_statement::decode($vector->jws, self::IAT + 30 * DAYSECS));
    }

    /**
     * A changed payload and a changed signature are refused.
     *
     * @param string $name
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('vector_provider')]
    public function test_a_tampered_vector_is_refused(string $name): void {
        $vector = $this->vector($name);
        [$header, , $signature] = explode('.', $vector->jws);
        $claims = clone $vector->claims;
        $claims->jti = '00000000-0000-4000-8000-000000000000';

        $this->assert_refused($header . '.' . self::b64(json_encode($claims)) . '.' . $signature, 401, 'bad_signature');
        $this->assert_refused($this->tamper($vector->jws), 401, 'bad_signature');
    }

    /**
     * Only 64 raw octets are a signature: cut, extended, in DER form or with characters that are not base64url, no.
     */
    public function test_a_signature_of_another_length_or_form_is_refused(): void {
        $jws = $this->vector('result')->jws;
        [$header, $payload, $signature] = explode('.', $jws);
        $raw = JWT::urlsafeB64Decode($signature);

        $bad = [
            'one octet short' => self::b64(substr($raw, 0, 63)),
            'one octet long' => self::b64($raw . "\x00"),
            'the right signature in DER' => self::b64(es256::der_signature($raw)),
            'zeros' => self::b64(str_repeat("\x00", 64)),
            'a character that is not base64url' => $signature . '!',
        ];
        foreach ($bad as $label => $value) {
            $this->assert_refused($header . '.' . $payload . '.' . $value, 401, 'bad_signature', $label);
        }
    }

    /**
     * The kid picks the key: an unknown one, none, and another key under the right one are refused.
     */
    public function test_the_kid_picks_the_key(): void {
        $jws = $this->vector('result')->jws;
        $jwks = json_decode($this->vector_file('jwks.json'), true);

        set_config('resultkeys', json_encode(['kid' => 'vectors-2'] + $jwks['keys'][0]), 'format_rumbos');
        $this->assert_refused($jws, 401, 'unknown_key');

        [, $payload, $signature] = explode('.', $jws);
        $this->assert_refused(self::b64('{"alg":"ES256","typ":"JWT"}') . '.' . $payload . '.' . $signature, 401, 'unknown_key');

        [, $otherjwk] = $this->ec_key('vectors-1');
        set_config('resultkeys', json_encode($otherjwk), 'format_rumbos');
        $this->assert_refused($jws, 401, 'bad_signature');
    }

    /**
     * Two keys at once, which is how a key is rotated: statements of either verify.
     */
    public function test_two_keys_can_be_trusted_at_once(): void {
        [$private, $jwk] = $this->ec_key('passback-2');
        $old = json_decode($this->vector_file('jwks.json'), true)['keys'][0];
        set_config('resultkeys', json_encode(['keys' => [$old, $jwk]]), 'format_rumbos');

        $this->assertSame(7, result_statement::decode($this->vector('result')->jws, self::IAT)->course_id);
        $new = JWT::encode(['iat' => self::IAT, 'n' => 1], $private, 'ES256', 'passback-2');
        $this->assertSame(1, result_statement::decode($new, self::IAT)->n);
    }

    /**
     * Algorithm confusion: an HMAC computed with the public key as its secret, which anybody can make, fails.
     */
    public function test_an_hmac_with_the_public_key_as_secret_is_refused(): void {
        $vector = $this->vector('result');
        $pem = result_config::keys()['vectors-1'];
        $jwks = $this->vector_file('jwks.json');
        $der = base64_decode(preg_replace('/-----[A-Z ]+-----|\s/', '', $pem));
        $payload = self::b64(json_encode($vector->claims));

        foreach ([$pem, trim($pem), $der, $jwks] as $secret) {
            foreach (['HS256' => 'sha256', 'HS384' => 'sha384', 'HS512' => 'sha512'] as $alg => $hash) {
                // Honestly labelled, and disguised as ES256: HS512 gives 64 octets, the length of an ES256 signature.
                foreach ([$alg, 'ES256'] as $label) {
                    $input = self::b64(json_encode(['alg' => $label, 'kid' => 'vectors-1', 'typ' => 'JWT'])) . '.' . $payload;
                    $jws = $input . '.' . self::b64(hash_hmac($hash, $input, $secret, true));
                    $this->assert_refused($jws, 401, 'bad_signature', $alg . ' labelled ' . $label);
                }
            }
        }
    }

    /**
     * The header cannot choose the algorithm: none, another one, another spelling or no alg are refused even when the
     * signature is the genuine one.
     */
    public function test_the_header_cannot_choose_the_algorithm(): void {
        [, $payload, $signature] = explode('.', $this->vector('result')->jws);

        $headers = [
            '{"alg":"none","kid":"vectors-1"}',
            '{"alg":"None","kid":"vectors-1"}',
            '{"alg":"RS256","kid":"vectors-1"}',
            '{"alg":"ES384","kid":"vectors-1"}',
            '{"alg":"es256","kid":"vectors-1"}',
            '{"alg":["ES256"],"kid":"vectors-1"}',
            '{"kid":"vectors-1"}',
        ];
        foreach ($headers as $header) {
            $this->assert_refused(self::b64($header) . '.' . $payload . '.' . $signature, 401, 'bad_signature', $header);
        }
        $unsigned = self::b64('{"alg":"none","kid":"vectors-1"}') . '.' . $payload;
        $this->assert_refused($unsigned . '.x', 401, 'bad_signature');
        $this->assert_refused($unsigned . '.', 400, 'malformed');
    }

    /**
     * An RSA key verifies RS256 only and an EC key ES256 only, whatever the other key of the site could verify.
     */
    public function test_each_key_verifies_its_own_algorithm_only(): void {
        [$ecprivate, $ecjwk] = $this->ec_key('ec-1');
        $rsakid = result_config::key_id(self::$publicpem);
        set_config('resultkeys', self::$publicpem . json_encode($ecjwk), 'format_rumbos');
        $claims = ['iat' => $this->now, 'exp' => $this->now + 60];

        $this->assertSame($this->now, result_statement::decode($this->sign($claims), $this->now)->iat);
        $es = JWT::encode($claims, $ecprivate, 'ES256', 'ec-1');
        $this->assertSame($this->now, result_statement::decode($es, $this->now)->iat);

        $this->assert_refused(JWT::encode($claims, $ecprivate, 'ES256', $rsakid), 401, 'bad_signature');
        $this->assert_refused($this->sign($claims, 'ec-1'), 401, 'bad_signature');
    }

    /**
     * The times of an ES256 statement: exp and nbf are honoured when present, and nothing may come from further in the
     * future than the skew.
     *
     * @param array $times Claims, as offsets from now.
     * @param string $reason What is answered, or "ok".
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('time_provider')]
    public function test_times(array $times, string $reason): void {
        [$private, $jwk] = $this->ec_key('ec-1');
        set_config('resultkeys', json_encode($jwk), 'format_rumbos');
        $claims = array_map(fn($offset) => is_int($offset) ? $this->now + $offset : $offset, $times) + ['n' => 1];
        $jws = JWT::encode($claims, $private, 'ES256', 'ec-1');

        if ($reason === 'ok') {
            $this->assertSame(1, result_statement::decode($jws, $this->now)->n);
        } else {
            $this->assert_refused($jws, $reason === 'malformed' ? 400 : 401, $reason);
        }
    }

    /**
     * The times to try.
     *
     * @return array
     */
    public static function time_provider(): array {
        return [
            'no time at all' => [[], 'ok'],
            'issued long ago' => [['iat' => -90 * DAYSECS], 'ok'],
            'issued within the skew' => [['iat' => 300], 'ok'],
            'issued beyond the skew' => [['iat' => 301], 'not_yet_valid'],
            'not before, beyond the skew' => [['nbf' => 301], 'not_yet_valid'],
            'exp in the future' => [['exp' => 3600], 'ok'],
            'exp passed, within the leeway' => [['exp' => -59], 'ok'],
            'exp passed' => [['exp' => -60], 'expired'],
            'exp long passed' => [['iat' => -DAYSECS, 'exp' => -3600], 'expired'],
            'iat as text' => [['iat' => '1795000000'], 'malformed'],
            'exp as text' => [['exp' => 'never'], 'malformed'],
            'exp null' => [['exp' => null], 'malformed'],
        ];
    }

    /**
     * A payload that is signed but is not a JSON object is refused, not a server error.
     */
    public function test_a_signed_payload_that_is_not_an_object_is_refused(): void {
        [$private, $jwk] = $this->ec_key('ec-1');
        set_config('resultkeys', json_encode($jwk), 'format_rumbos');

        foreach (['not json', '[1,2]', '"text"', '7'] as $payload) {
            $input = self::b64('{"alg":"ES256","kid":"ec-1"}') . '.' . self::b64($payload);
            openssl_sign($input, $der, $private, OPENSSL_ALGO_SHA256);
            $at = 2;
            $raw = '';
            foreach (['r', 's'] as $name) {
                $length = ord($der[$at + 1]);
                $raw .= str_pad(ltrim(substr($der, $at + 2, $length), "\x00"), 32, "\x00", STR_PAD_LEFT);
                $at += 2 + $length;
            }
            $this->assert_refused($input . '.' . self::b64($raw), 401, 'bad_signature', $payload);
        }
    }

    /**
     * Asserts that decoding refuses a statement.
     *
     * @param string $jws
     * @param int $status
     * @param string $reason
     * @param string $label
     */
    private function assert_refused(string $jws, int $status, string $reason, string $label = ''): void {
        try {
            result_statement::decode($jws, $this->now > self::IAT ? $this->now : self::IAT);
            $this->fail('accepted: ' . $label);
        } catch (result_rejection $rejection) {
            $this->assertSame([$status, $reason], [$rejection->status, $rejection->reason], $label);
        }
    }

    /**
     * Base64url without padding.
     *
     * @param string $data
     * @return string
     */
    private static function b64(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
