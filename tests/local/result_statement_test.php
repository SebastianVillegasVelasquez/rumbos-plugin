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

require_once(__DIR__ . '/../fixtures/result_testcase.php');

/**
 * Tests for the verification of result statements.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\local\result_statement::class)]
final class result_statement_test extends \format_rumbos\result_testcase {
    /**
     * Verifies claims after signing them, and returns what the verifier concludes or the refusal.
     *
     * @param array $override Claims to change.
     * @return array|result_rejection
     */
    private function outcome(array $override = []): array|result_rejection {
        $course = $this->getDataGenerator()->create_course(['format' => 'rumbos']);
        $user = $this->getDataGenerator()->create_user();
        try {
            return result_statement::verify($this->sign($this->claims($course, $user, $override)), $this->now);
        } catch (result_rejection $rejection) {
            return $rejection;
        }
    }

    /**
     * Asserts a refusal.
     *
     * @param array|result_rejection $outcome
     * @param int $status
     * @param string $reason
     */
    private function assert_refused(array|result_rejection $outcome, int $status, string $reason): void {
        $this->assertInstanceOf(result_rejection::class, $outcome);
        $this->assertSame([$status, $reason], [$outcome->status, $outcome->reason]);
    }

    /**
     * A well formed statement is accepted and normalised.
     */
    public function test_a_valid_statement_is_accepted(): void {
        $claims = $this->outcome(['percent' => '80.01', 'passed' => true, 'purpose' => 'final']);

        $this->assertIsArray($claims);
        $this->assertSame('80.01', $claims['percent']);
        $this->assertSame('final', $claims['purpose']);
        $this->assertTrue($claims['passed']);
        $this->assertSame('gt', $claims['pass_operator']);
        $this->assertSame('80', $claims['pass_percent']);
        $this->assertSame($this->now, $claims['finished_at']);
        $this->assertSame(8001, result_statement::cents($claims['percent']));
    }

    /**
     * The policy may use gte, a decimal string, and a Unix time may replace the ISO one.
     */
    public function test_policy_and_time_forms(): void {
        $claims = $this->outcome([
            'policy' => ['pass_percent' => '75.5', 'pass_operator' => 'gte'],
            'finished_at' => $this->now - 5,
        ]);

        $this->assertIsArray($claims);
        $this->assertSame('gte', $claims['pass_operator']);
        $this->assertSame('75.5', $claims['pass_percent']);
        $this->assertSame($this->now - 5, $claims['finished_at']);
    }

    /**
     * Whole hundredths, never a float.
     */
    public function test_cents_are_exact(): void {
        $this->assertSame(0, result_statement::cents('0.00'));
        $this->assertSame(1, result_statement::cents('0.01'));
        $this->assertSame(8000, result_statement::cents('80.00'));
        $this->assertSame(8001, result_statement::cents('80.01'));
        $this->assertSame(10000, result_statement::cents('100.00'));
    }

    /**
     * Past its expiry (beyond the leeway) it is refused with 401.
     */
    public function test_an_expired_statement_is_refused(): void {
        $this->assert_refused($this->outcome(['iat' => $this->now - 600, 'exp' => $this->now - 300]), 401, 'expired');
    }

    /**
     * Within the clock leeway it is still accepted.
     */
    public function test_a_statement_just_past_expiry_is_within_the_leeway(): void {
        $this->assertIsArray($this->outcome(['iat' => $this->now - 150, 'exp' => $this->now - 30]));
    }

    /**
     * Issued in the future it is refused with 401.
     */
    public function test_a_statement_from_the_future_is_refused(): void {
        $this->assert_refused($this->outcome(['iat' => $this->now + 3600, 'exp' => $this->now + 3700]), 401, 'not_yet_valid');
    }

    /**
     * An expiry more than five minutes after the issue time is malformed.
     */
    public function test_a_lifetime_over_five_minutes_is_malformed(): void {
        $this->assert_refused($this->outcome(['exp' => $this->now + 301]), 400, 'malformed');
        $this->assertIsArray($this->outcome(['exp' => $this->now + 300]));
    }

    /**
     * Issuer, audience and tenant must match the configuration.
     */
    public function test_issuer_audience_and_tenant_are_checked(): void {
        $this->assert_refused($this->outcome(['iss' => 'someone-else']), 401, 'bad_issuer');
        $this->assert_refused($this->outcome(['aud' => 'https://another.example']), 401, 'bad_audience');
        $this->assert_refused($this->outcome(['tenant' => 'tenant-2']), 401, 'bad_tenant');
    }

    /**
     * A changed signature, a key the site does not trust and an unknown key id are all 401.
     */
    public function test_signature_and_key_are_checked(): void {
        $course = $this->getDataGenerator()->create_course(['format' => 'rumbos']);
        $user = $this->getDataGenerator()->create_user();
        $jws = $this->sign($this->claims($course, $user));

        // Change the first character of the signature: the last one of a base64 text can carry padding bits that a
        // decoder ignores, so changing it sometimes changes nothing.
        [$header, $payload, $signature] = explode('.', $jws);
        $tampered = $header . '.' . $payload . '.' . ($signature[0] === 'A' ? 'B' : 'A') . substr($signature, 1);
        try {
            result_statement::verify($tampered, $this->now);
            $this->fail('a tampered statement was accepted');
        } catch (result_rejection $rejection) {
            $this->assertSame([401, 'bad_signature'], [$rejection->status, $rejection->reason]);
        }

        // A payload that is not JSON is a bad statement, not a server error the sender would retry.
        // A control character inside a string is what makes the JWT library throw DomainException.
        $badjson = rtrim(strtr(base64_encode("{\"iss\":\"\x01\"}"), '+/', '-_'), '=');
        $broken = $header . '.' . $badjson . '.' . $signature;
        try {
            result_statement::verify($broken, $this->now);
            $this->fail('a broken payload was accepted');
        } catch (result_rejection $rejection) {
            $this->assertSame([401, 'bad_signature'], [$rejection->status, $rejection->reason]);
        }

        // Signed with another key but claiming the trusted key's id.
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($other, $otherpem);
        $forged = JWT::encode($this->claims($course, $user), $otherpem, 'RS256', result_config::key_id(self::$publicpem));
        try {
            result_statement::verify($forged, $this->now);
            $this->fail('a statement signed with another key was accepted');
        } catch (result_rejection $rejection) {
            $this->assertSame([401, 'bad_signature'], [$rejection->status, $rejection->reason]);
        }

        // A key id the site does not know.
        try {
            result_statement::verify($this->sign($this->claims($course, $user), 'ffffffffffffffff'), $this->now);
            $this->fail('an unknown key id was accepted');
        } catch (result_rejection $rejection) {
            $this->assertSame([401, 'unknown_key'], [$rejection->status, $rejection->reason]);
        }
    }

    /**
     * The algorithm is pinned: "none" and an HMAC with the public key as secret are refused.
     */
    public function test_the_algorithm_is_pinned(): void {
        $course = $this->getDataGenerator()->create_course(['format' => 'rumbos']);
        $user = $this->getDataGenerator()->create_user();
        $claims = $this->claims($course, $user);
        $kid = result_config::key_id(self::$publicpem);
        $b64 = fn(string $data): string => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');

        $none = $b64(json_encode(['alg' => 'none', 'typ' => 'JWT', 'kid' => $kid])) . '.' . $b64(json_encode($claims)) . '.x';
        $hs256 = JWT::encode($claims, self::$publicpem, 'HS256', $kid);

        foreach ([$none, $hs256] as $jws) {
            try {
                result_statement::verify($jws, $this->now);
                $this->fail('a statement with another algorithm was accepted');
            } catch (result_rejection $rejection) {
                $this->assertSame([401, 'bad_signature'], [$rejection->status, $rejection->reason]);
            }
        }
    }

    /**
     * Claims that are missing or of the wrong form are malformed, whatever the signature says.
     *
     * @param array $override Claims to change; a null value removes the claim.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformed_provider')]
    public function test_malformed_claims_are_refused(array $override): void {
        $course = $this->getDataGenerator()->create_course(['format' => 'rumbos']);
        $user = $this->getDataGenerator()->create_user();
        $claims = $this->claims($course, $user);
        foreach ($override as $name => $value) {
            if ($value === null) {
                unset($claims[$name]);
            } else {
                $claims[$name] = $value;
            }
        }
        try {
            result_statement::verify($this->sign($claims), $this->now);
            $this->fail('a malformed statement was accepted');
        } catch (result_rejection $rejection) {
            $this->assertSame([400, 'malformed'], [$rejection->status, $rejection->reason]);
        }
    }

    /**
     * The malformed claims to try.
     *
     * @return array
     */
    public static function malformed_provider(): array {
        return [
            'no jti' => [['jti' => null]],
            'jti with a space' => [['jti' => 'a b']],
            'no sub' => [['sub' => null]],
            'sub is not a pseudonym' => [['sub' => 'user@example.com']],
            'course id as text' => [['course_id' => '3']],
            'course id zero' => [['course_id' => 0]],
            'unknown purpose' => [['purpose' => 'practice']],
            'no attempt' => [['attempt_id' => null]],
            'percent as a number' => [['percent' => 85.5]],
            'percent with one decimal' => [['percent' => '85.5']],
            'percent over 100' => [['percent' => '100.01']],
            'passed as text' => [['passed' => 'true']],
            'no policy' => [['policy' => null]],
            'policy operator unknown' => [['policy' => ['pass_percent' => 80, 'pass_operator' => 'lt']]],
            'policy percent over 100' => [['policy' => ['pass_percent' => 101, 'pass_operator' => 'gt']]],
            'finished_at not a time' => [['finished_at' => 'yesterday']],
            'finished_at without zone' => [['finished_at' => '2026-11-17T10:00:00']],
            'no tenant' => [['tenant' => null]],
        ];
    }

    /**
     * Not three segments, or not JSON in the header, is malformed before any key is looked at.
     */
    public function test_a_statement_that_is_not_a_jws_is_malformed(): void {
        foreach (['', 'abc', 'a.b', 'a..c', '!!!.b.c'] as $jws) {
            try {
                result_statement::verify($jws, $this->now);
                $this->fail('"' . $jws . '" was accepted');
            } catch (result_rejection $rejection) {
                $this->assertSame(400, $rejection->status);
            }
        }
    }

    /**
     * Two statements with the same claims have the same hash, and any change changes it.
     */
    public function test_the_payload_hash_tells_statements_apart(): void {
        $course = $this->getDataGenerator()->create_course(['format' => 'rumbos']);
        $user = $this->getDataGenerator()->create_user();
        $verify = fn(string $attempt) => result_statement::verify(
            $this->sign($this->claims($course, $user, ['jti' => 'same', 'attempt_id' => $attempt])),
            $this->now
        );
        $one = $verify('a');
        $again = $verify('a');
        $other = $verify('b');

        $this->assertSame(result_statement::payload_hash($one), result_statement::payload_hash($again));
        $this->assertNotSame(result_statement::payload_hash($one), result_statement::payload_hash($other));
    }

    /**
     * Signs envelope claims (no result claims) and returns what verify_envelope concludes or the refusal.
     *
     * @param array $override Claims to change; a null value removes the claim.
     * @param string|null $kid
     * @return \stdClass|result_rejection
     */
    private function envelope_outcome(array $override = [], ?string $kid = null): \stdClass|result_rejection {
        $claims = array_filter($override + [
            'iss' => 'rumbos-test', 'aud' => launch_assertion::issuer(), 'tenant' => 'tenant-1', 'jti' => 'env-1',
            'iat' => $this->now, 'exp' => $this->now + 120, 'kind' => 'anything', 'data' => ['x' => 1],
        ], fn($value) => $value !== null);
        try {
            return result_statement::verify_envelope($this->sign($claims, $kid), $this->now);
        } catch (result_rejection $rejection) {
            return $rejection;
        }
    }

    /**
     * Any other signed message of the service passes the same signature, expiry and party checks and keeps its claims.
     */
    public function test_an_envelope_is_verified_and_keeps_its_own_claims(): void {
        $claims = $this->envelope_outcome();

        $this->assertInstanceOf(\stdClass::class, $claims);
        $this->assertSame('anything', $claims->kind);
        $this->assertSame(1, $claims->data->x);
    }

    /**
     * The envelope refuses what a result statement refuses: parties, signature, expiry and a lifetime over five minutes.
     */
    public function test_an_envelope_is_refused_like_a_statement(): void {
        $this->assert_refused($this->envelope_outcome(['iss' => 'someone-else']), 401, 'bad_issuer');
        $this->assert_refused($this->envelope_outcome(['aud' => 'https://another.example']), 401, 'bad_audience');
        $this->assert_refused($this->envelope_outcome(['tenant' => 'tenant-2']), 401, 'bad_tenant');
        $this->assert_refused($this->envelope_outcome(['iat' => $this->now - 600, 'exp' => $this->now - 300]), 401, 'expired');
        $this->assert_refused($this->envelope_outcome([], 'ffffffffffffffff'), 401, 'unknown_key');
        $this->assert_refused($this->envelope_outcome(['exp' => $this->now + 301]), 400, 'malformed');
        $this->assert_refused($this->envelope_outcome(['jti' => null]), 400, 'malformed');
        $this->assert_refused($this->envelope_outcome(['jti' => 'has space']), 400, 'malformed');
        $this->assert_refused($this->envelope_outcome(['tenant' => null]), 400, 'malformed');
    }
}
