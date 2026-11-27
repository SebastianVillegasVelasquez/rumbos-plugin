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

use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Verifies a signed result statement and turns it into a plain, validated claim set.
 *
 * Nothing is trusted before the signature is checked, and the algorithm is pinned by the configured key: the header of
 * a token has to name that algorithm and never chooses it. The claims are specified in docs/RESULT-PASSBACK.md.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class result_statement {
    /** @var string The algorithm of an RSA key: the development signer's. */
    public const ALGORITHM = 'RS256';

    /** @var string The algorithm of an elliptic curve key: the Rumbos service's. */
    public const SERVICE_ALGORITHM = 'ES256';

    /** @var int Seconds an ES256 time may be in the future. The service assumes the clocks differ by less. */
    public const FUTURE_SKEW = 300;

    /** @var string The audience the service writes in every statement. */
    public const SERVICE_AUDIENCE = 'moodle';

    /** @var string A statement or attempt id: what the table columns hold. */
    public const ID_PATTERN = '/^[A-Za-z0-9._-]{1,64}$/D';

    /** @var string The user reference of a launch assertion, which `sub` repeats. */
    private const SUBJECT_PATTERN = '/^[0-9a-f]{32}$/D';

    /** @var string A percent: two decimals, 0.00 to 100.00. */
    private const PERCENT_PATTERN = '/^(100\.00|\d{1,2}\.\d{2})$/D';

    /** @var int Seconds of clock difference tolerated, for iat and exp. */
    public const LEEWAY = 60;

    /** @var int Longest lifetime a statement may declare, in seconds. */
    public const MAX_LIFETIME = 300;

    /** @var string[] The purposes a statement may carry. */
    public const PURPOSES = ['check_in', 'final'];

    /** @var string[] The pass operators a policy may carry. */
    public const OPERATORS = ['gt', 'gte'];

    /**
     * Reads the key id of a statement's header without trusting anything else in it.
     *
     * @param string $jws The compact serialization.
     * @return string The key id, or an empty string when there is none.
     * @throws result_rejection 400 when it is not three non empty segments of a JWS.
     */
    public static function key_id(string $jws): string {
        $header = self::header($jws);
        return isset($header->kid) && is_string($header->kid) ? $header->kid : '';
    }

    /**
     * Verifies the signature and the times of a signed message and returns its claims as they are.
     *
     * This is the one place a signature of the service is checked. Nothing else about the claims is checked here: who
     * sent the message, to whom, and what it says are for the caller.
     *
     * @param string $jws The compact serialization.
     * @param int $now The current time.
     * @return \stdClass The decoded claims.
     * @throws result_rejection 400 when malformed, 401 when not authentic, expired or from the future.
     */
    public static function decode(string $jws, int $now): \stdClass {
        $kid = self::key_id($jws);
        $keys = result_config::keys();
        if ($kid === '' || !isset($keys[$kid])) {
            throw new result_rejection(401, 'unknown_key');
        }
        $algorithm = result_config::algorithm($keys[$kid]);
        // This is what stops "none", an HMAC with the public key as its secret, and any other swap.
        if ((self::header($jws)->alg ?? null) !== $algorithm) {
            throw new result_rejection(401, 'bad_signature');
        }
        return $algorithm === self::SERVICE_ALGORITHM
            ? self::decode_es256($jws, $keys[$kid], $now)
            : self::decode_rs256($jws, $kid, $keys[$kid], $now);
    }

    /**
     * The header of a statement, unverified.
     *
     * @param string $jws The compact serialization.
     * @return \stdClass Empty when the header is JSON but not an object.
     * @throws result_rejection 400 when it is not three non empty segments of a JWS.
     */
    private static function header(string $jws): \stdClass {
        $parts = explode('.', $jws);
        if (count($parts) !== 3 || in_array('', $parts, true)) {
            throw new result_rejection(400, 'malformed');
        }
        try {
            $header = json_decode(JWT::urlsafeB64Decode($parts[0]), false, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new result_rejection(400, 'malformed');
        }
        return $header instanceof \stdClass ? $header : new \stdClass();
    }

    /**
     * An RS256 statement, verified by the JWT library Moodle ships.
     *
     * @param string $jws
     * @param string $kid
     * @param string $pem
     * @param int $now
     * @return \stdClass The claims.
     * @throws result_rejection 401.
     */
    private static function decode_rs256(string $jws, string $kid, string $pem, int $now): \stdClass {
        $leeway = JWT::$leeway;
        $timestamp = JWT::$timestamp;
        JWT::$leeway = self::LEEWAY;
        JWT::$timestamp = $now;
        try {
            return JWT::decode($jws, [$kid => new Key($pem, self::ALGORITHM)]);
        } catch (ExpiredException $e) {
            throw new result_rejection(401, 'expired');
        } catch (BeforeValidException $e) {
            throw new result_rejection(401, 'not_yet_valid');
        } catch (\UnexpectedValueException | \DomainException $e) {
            // Includes SignatureInvalidException, a key that does not fit the algorithm and an unreadable payload (the
            // library throws DomainException for bytes that are not JSON).
            throw new result_rejection(401, 'bad_signature');
        } finally {
            JWT::$leeway = $leeway;
            JWT::$timestamp = $timestamp;
        }
    }

    /**
     * An ES256 statement of the service (see {@see es256} for the signature).
     *
     * The service sends no `exp`: its outbox retries for days, and a statement that arrives twice changes nothing. One
     * that carries `exp` or `nbf` is held to it. An old statement is accepted; one issued further in the future than
     * {@see self::FUTURE_SKEW} is not.
     *
     * @param string $jws
     * @param string $pem
     * @param int $now
     * @return \stdClass The claims.
     * @throws result_rejection 400 or 401.
     */
    private static function decode_es256(string $jws, string $pem, int $now): \stdClass {
        [$header, $payload, $signature] = explode('.', $jws);
        foreach ([$header, $payload, $signature] as $segment) {
            // Strict, because the decoder below skips characters that are not base64url.
            if (preg_match('/^[A-Za-z0-9_-]+$/D', $segment) !== 1) {
                throw new result_rejection(401, 'bad_signature');
            }
        }
        if (!es256::verify($header . '.' . $payload, JWT::urlsafeB64Decode($signature), $pem)) {
            throw new result_rejection(401, 'bad_signature');
        }
        $claims = json_decode(JWT::urlsafeB64Decode($payload), false, 64, JSON_BIGINT_AS_STRING);
        if (!$claims instanceof \stdClass) {
            throw new result_rejection(401, 'bad_signature');
        }

        foreach (['iat', 'nbf', 'exp'] as $name) {
            if (property_exists($claims, $name) && !is_int($claims->{$name}) && !is_float($claims->{$name})) {
                throw new result_rejection(400, 'malformed');
            }
        }
        if (isset($claims->exp) && $now - self::LEEWAY >= $claims->exp) {
            throw new result_rejection(401, 'expired');
        }
        foreach (['iat', 'nbf'] as $name) {
            if (isset($claims->{$name}) && $claims->{$name} > $now + self::FUTURE_SKEW) {
                throw new result_rejection(401, 'not_yet_valid');
            }
        }
        return $claims;
    }

    /**
     * Verifies the signature, the times, the issuer, the audience and the tenant, and validates a result statement.
     *
     * Which claims are required depends on the algorithm of the key: ES256 is the service's contract
     * ({@see self::normalise_service()}), RS256 the development signer's ({@see self::normalise()}).
     *
     * @param string $jws The compact serialization.
     * @param int $now The current time.
     * @return array The normalised claims.
     * @throws result_rejection 400 when malformed, 401 when not authentic, expired or not for this site, 422 when it
     *     is not a result.
     */
    public static function verify(string $jws, int $now): array {
        return self::result_claims(self::decode($jws, $now), self::algorithm_of($jws), $now);
    }

    /**
     * Verifies a signed message that need not be a result: the signature, the times, who sent it, to whom and for which
     * tenant, and the claims every message carries (`jti` and `iat`; with RS256 also a short `exp`).
     *
     * The caller checks the rest of the claims.
     *
     * @param string $jws The compact serialization.
     * @param int $now The current time.
     * @return \stdClass The decoded claims.
     * @throws result_rejection 400 when malformed, 401 when not authentic, expired or not for this site.
     */
    public static function verify_envelope(string $jws, int $now): \stdClass {
        $claims = self::decode($jws, $now);
        self::check_envelope($claims, self::algorithm_of($jws));
        return $claims;
    }

    /**
     * The algorithm of the configured key that a statement names. Only meaningful once {@see self::decode()} accepted it.
     *
     * @param string $jws The compact serialization.
     * @return string
     * @throws result_rejection 401 when no configured key has that id.
     */
    public static function algorithm_of(string $jws): string {
        $keys = result_config::keys();
        $kid = self::key_id($jws);
        if (!isset($keys[$kid])) {
            throw new result_rejection(401, 'unknown_key');
        }
        return result_config::algorithm($keys[$kid]);
    }

    /**
     * The checks of {@see self::verify_envelope()} on claims whose signature is verified.
     *
     * @param \stdClass $claims
     * @param string $algorithm The algorithm of the key that signed them.
     * @throws result_rejection 400 or 401.
     */
    public static function check_envelope(\stdClass $claims, string $algorithm): void {
        $valid = is_string($claims->iss ?? null) && $claims->iss !== ''
            && is_string($claims->aud ?? null) && $claims->aud !== ''
            && is_string($claims->tenant ?? null) && $claims->tenant !== ''
            && is_string($claims->jti ?? null) && preg_match(self::ID_PATTERN, $claims->jti) === 1
            && is_int($claims->iat ?? null);
        if ($valid && $algorithm === self::ALGORITHM) {
            $valid = is_int($claims->exp ?? null)
                && $claims->exp > $claims->iat && $claims->exp - $claims->iat <= self::MAX_LIFETIME;
        }
        if (!$valid) {
            throw new result_rejection(400, 'malformed');
        }
        self::check_parties($claims->iss, $claims->aud, $claims->tenant, $algorithm);
    }

    /**
     * Validates verified claims as a result statement and checks who it is from and for.
     *
     * @param \stdClass $claims
     * @param string $algorithm The algorithm of the key that signed them.
     * @param int $now
     * @return array The normalised claims.
     * @throws result_rejection 400, 401 or 422.
     */
    public static function result_claims(\stdClass $claims, string $algorithm, int $now): array {
        $normalised = $algorithm === self::SERVICE_ALGORITHM
            ? self::normalise_service($claims, $now)
            : self::normalise($claims, $now);
        self::check_parties($normalised['iss'], $normalised['aud'], $normalised['tenant'], $algorithm);
        return $normalised;
    }

    /**
     * Checks that a message is from the configured issuer, for this audience and for the configured tenant.
     *
     * The service addresses every statement to "moodle", whatever the site. What ties its statement to one site is the
     * tenant, and for a result also `sub`, an HMAC with a secret of the site. The development signer addresses the
     * site's own issuer.
     *
     * @param string $iss
     * @param string $aud
     * @param string $tenant
     * @param string $algorithm
     * @throws result_rejection 401 when one does not match.
     */
    private static function check_parties(string $iss, string $aud, string $tenant, string $algorithm): void {
        if (!hash_equals(result_config::issuer(), $iss)) {
            throw new result_rejection(401, 'bad_issuer');
        }
        $audience = $algorithm === self::SERVICE_ALGORITHM ? self::SERVICE_AUDIENCE : launch_assertion::issuer();
        if (!hash_equals($audience, $aud)) {
            throw new result_rejection(401, 'bad_audience');
        }
        if (!hash_equals(result_config::tenant(), $tenant)) {
            throw new result_rejection(401, 'bad_tenant');
        }
    }

    /**
     * Checks the claims of a result statement of the service and returns them in a fixed form.
     *
     * It is the service's schema 1 result statement (no `schema` claim), or its schema 2 attempt record, which holds
     * the same claims and more. Extra claims are dropped. The idempotency key of the attempt is `jti`. There is no
     * `exp`, `attempt_id` or `policy`. The pass rule (`pass_percent` and `pass_operator`) is only recorded, so it may
     * be absent, but not half there or wrong. `completes_course` is required: it is false when the student passed and
     * the rule in force did not exempt them from the course.
     *
     * @param \stdClass $claims The decoded claims.
     * @param int $now The current time.
     * @return array The claims, with `attempt_id` set to the `jti` and `finished_at` as a Unix time (the `iat` when
     *     the statement has none).
     * @throws result_rejection 400 when a claim is missing or of the wrong form, 401 when it is from the future, 422
     *     when it is another kind of statement.
     */
    public static function normalise_service(\stdClass $claims, int $now): array {
        $get = fn(string $name) => $claims->{$name} ?? null;
        $string = fn($value, string $pattern): bool => is_string($value) && preg_match($pattern, $value) === 1;

        $isresult = !property_exists($claims, 'kind') && (!property_exists($claims, 'schema') || $get('schema') === 1);
        $isattempt = $get('kind') === 'attempt' && $get('schema') === 2;
        if (!$isresult && !$isattempt) {
            throw new result_rejection(422, 'unknown_kind');
        }

        $passpercent = $get('pass_percent');
        $operator = $get('pass_operator');
        $hasrule = property_exists($claims, 'pass_percent') || property_exists($claims, 'pass_operator');

        $valid = is_string($get('iss')) && $get('iss') !== ''
            && is_string($get('aud')) && $get('aud') !== ''
            && is_string($get('tenant')) && $get('tenant') !== ''
            && $string($get('jti'), self::ID_PATTERN)
            && $string($get('sub'), self::SUBJECT_PATTERN)
            && is_int($get('course_id')) && $get('course_id') > 0
            && in_array($get('purpose'), self::PURPOSES, true)
            && $string($get('percent'), self::PERCENT_PATTERN)
            && is_bool($get('passed'))
            && is_bool($get('completes_course'))
            && (!$hasrule || (self::is_pass_percent($passpercent) && in_array($operator, self::OPERATORS, true)))
            && (!property_exists($claims, 'iat') || is_int($claims->iat))
            && (property_exists($claims, 'iat') || property_exists($claims, 'finished_at'));
        $finished = property_exists($claims, 'finished_at') ? self::finished_at($claims->finished_at) : $get('iat');
        if (!$valid || $finished === null) {
            throw new result_rejection(400, 'malformed');
        }
        // The `iat` was checked with the signature.
        if ($finished > $now + self::FUTURE_SKEW) {
            throw new result_rejection(401, 'not_yet_valid');
        }

        return [
            'iss' => $get('iss'),
            'aud' => $get('aud'),
            'tenant' => $get('tenant'),
            'jti' => $get('jti'),
            'iat' => $get('iat'),
            'sub' => $get('sub'),
            'course_id' => $get('course_id'),
            'purpose' => $get('purpose'),
            'attempt_id' => $get('jti'),
            'percent' => $get('percent'),
            'passed' => $get('passed'),
            'completes_course' => $get('completes_course'),
            'pass_percent' => $hasrule ? self::pass_percent_text($passpercent) : null,
            'pass_operator' => $hasrule ? $operator : null,
            'finished_at' => $finished,
        ];
    }

    /**
     * Whether a pass of these claims completes the course (and, with it, the content when auto-complete is on).
     *
     * @param array $claims Normalised claims.
     * @return bool
     */
    public static function completes(array $claims): bool {
        // The development signer's statements have no completes_course: there, passing completes.
        return $claims['passed'] && ($claims['completes_course'] ?? true);
    }

    /**
     * Whether a claim is a usable pass percent: a number or a decimal string, 0 to 100.
     *
     * @param mixed $value
     * @return bool
     */
    private static function is_pass_percent(mixed $value): bool {
        $number = is_int($value) || is_float($value)
            || (is_string($value) && preg_match('/^\d{1,3}(\.\d{1,4})?$/D', $value) === 1);
        return $number && (float) $value >= 0 && (float) $value <= 100;
    }

    /**
     * A pass percent as it is recorded.
     *
     * @param int|float|string $value
     * @return string
     */
    private static function pass_percent_text(int|float|string $value): string {
        return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
    }

    /**
     * A hash of the claims, to tell a replay of a statement from a different statement that reuses its id.
     *
     * @param array $claims The normalised claims.
     * @return string SHA-256, lower-case hexadecimal.
     */
    public static function payload_hash(array $claims): string {
        ksort($claims);
        return hash('sha256', json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Checks the shape of the claims of an RS256 statement (the development signer's) and returns them in a fixed form.
     *
     * Extra claims are dropped. Every claim of this form in docs/RESULT-PASSBACK.md must be present and of its type.
     *
     * @param \stdClass $claims The decoded claims.
     * @param int $now The current time.
     * @return array The claims, with `finished_at` as a Unix time and the policy split in two.
     * @throws result_rejection 400 when a claim is missing or of the wrong form.
     */
    public static function normalise(\stdClass $claims, int $now): array {
        $get = fn(string $name) => $claims->{$name} ?? null;
        $string = fn($value, string $pattern): bool => is_string($value) && preg_match($pattern, $value) === 1;
        $id = self::ID_PATTERN;
        $malformed = new result_rejection(400, 'malformed');

        $policy = $get('policy');
        $policy = is_object($policy) ? $policy : null;
        $passpercent = $policy->pass_percent ?? null;
        $operator = $policy->pass_operator ?? null;

        $valid = is_string($get('iss')) && $get('iss') !== ''
            && is_string($get('aud')) && $get('aud') !== ''
            && is_string($get('tenant')) && $get('tenant') !== ''
            && $string($get('jti'), $id)
            && is_int($get('iat')) && is_int($get('exp'))
            && $string($get('sub'), self::SUBJECT_PATTERN)
            && is_int($get('course_id')) && $get('course_id') > 0
            && in_array($get('purpose'), self::PURPOSES, true)
            && $string($get('attempt_id'), $id)
            && $string($get('percent'), self::PERCENT_PATTERN)
            && is_bool($get('passed'))
            && $policy !== null
            && self::is_pass_percent($passpercent)
            && in_array($operator, self::OPERATORS, true);
        if (!$valid) {
            throw $malformed;
        }
        if ($get('exp') - $get('iat') > self::MAX_LIFETIME || $get('exp') <= $get('iat')) {
            throw $malformed;
        }
        $finished = self::finished_at($get('finished_at'));
        if ($finished === null) {
            throw $malformed;
        }

        return [
            'iss' => $get('iss'),
            'aud' => $get('aud'),
            'tenant' => $get('tenant'),
            'jti' => $get('jti'),
            'iat' => $get('iat'),
            'exp' => $get('exp'),
            'sub' => $get('sub'),
            'course_id' => $get('course_id'),
            'purpose' => $get('purpose'),
            'attempt_id' => $get('attempt_id'),
            'percent' => $get('percent'),
            'passed' => $get('passed'),
            'pass_percent' => self::pass_percent_text($passpercent),
            'pass_operator' => $operator,
            'finished_at' => $finished,
        ];
    }

    /**
     * The percent of a statement as whole hundredths, so that nothing is compared as a float.
     *
     * @param string $percent A decimal string with two decimals, "0.00" to "100.00".
     * @return int 0 to 10000.
     */
    public static function cents(string $percent): int {
        return (int) str_replace('.', '', $percent);
    }

    /**
     * Reads `finished_at`: Unix seconds, or an ISO 8601 time with a zone.
     *
     * @param mixed $value
     * @return int|null Unix time, or null when it is neither.
     */
    private static function finished_at(mixed $value): ?int {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        $iso = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})$/';
        if (!is_string($value) || preg_match($iso, $value) !== 1) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->getTimestamp();
        } catch (\Exception $e) {
            return null;
        }
    }
}
