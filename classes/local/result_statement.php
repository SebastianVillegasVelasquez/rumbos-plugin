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
 * Nothing is trusted before the signature is checked, and the algorithm is pinned: it is never read from the token.
 * The claims are specified in docs/RESULT-PASSBACK.md.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class result_statement {
    /** @var string The only algorithm accepted. */
    public const ALGORITHM = 'RS256';

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
        $parts = explode('.', $jws);
        if (count($parts) !== 3 || in_array('', $parts, true)) {
            throw new result_rejection(400, 'malformed');
        }
        try {
            $header = json_decode(JWT::urlsafeB64Decode($parts[0]), false, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new result_rejection(400, 'malformed');
        }
        return is_object($header) && isset($header->kid) && is_string($header->kid) ? $header->kid : '';
    }

    /**
     * Verifies the signature, the expiry, the issuer, the audience and the tenant, and validates the claims.
     *
     * @param string $jws The compact serialization.
     * @param int $now The current time.
     * @return array The normalised claims (see {@see self::normalise()}).
     * @throws result_rejection 400 when malformed, 401 when not authentic, expired or not for this site.
     */
    public static function verify(string $jws, int $now): array {
        $kid = self::key_id($jws);
        $keys = result_config::keys();
        if ($kid === '' || !isset($keys[$kid])) {
            throw new result_rejection(401, 'unknown_key');
        }

        $leeway = JWT::$leeway;
        $timestamp = JWT::$timestamp;
        JWT::$leeway = self::LEEWAY;
        JWT::$timestamp = $now;
        try {
            $claims = JWT::decode($jws, [$kid => new Key($keys[$kid], self::ALGORITHM)]);
        } catch (ExpiredException $e) {
            throw new result_rejection(401, 'expired');
        } catch (BeforeValidException $e) {
            throw new result_rejection(401, 'not_yet_valid');
        } catch (\UnexpectedValueException $e) {
            // Includes SignatureInvalidException, a key that does not fit the algorithm and an unreadable payload.
            throw new result_rejection(401, 'bad_signature');
        } finally {
            JWT::$leeway = $leeway;
            JWT::$timestamp = $timestamp;
        }

        $claims = self::normalise($claims, $now);

        if (!hash_equals(result_config::issuer(), $claims['iss'])) {
            throw new result_rejection(401, 'bad_issuer');
        }
        if (!hash_equals(launch_assertion::issuer(), $claims['aud'])) {
            throw new result_rejection(401, 'bad_audience');
        }
        if (!hash_equals(result_config::tenant(), $claims['tenant'])) {
            throw new result_rejection(401, 'bad_tenant');
        }
        return $claims;
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
     * Checks the shape of the claims and returns them in a fixed form.
     *
     * Extra claims are dropped. Every claim of docs/RESULT-PASSBACK.md must be present and of its type.
     *
     * @param \stdClass $claims The decoded claims.
     * @param int $now The current time.
     * @return array The claims, with `finished_at` as a Unix time and the policy split in two.
     * @throws result_rejection 400 when a claim is missing or of the wrong form.
     */
    public static function normalise(\stdClass $claims, int $now): array {
        $get = fn(string $name) => $claims->{$name} ?? null;
        $string = fn($value, string $pattern): bool => is_string($value) && preg_match($pattern, $value) === 1;
        $id = '/^[A-Za-z0-9._-]{1,64}$/';
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
            && $string($get('sub'), '/^[0-9a-f]{32}$/')
            && is_int($get('course_id')) && $get('course_id') > 0
            && in_array($get('purpose'), self::PURPOSES, true)
            && $string($get('attempt_id'), $id)
            && $string($get('percent'), '/^(100\.00|\d{1,2}\.\d{2})$/')
            && is_bool($get('passed'))
            && $policy !== null
            && (is_int($passpercent) || is_float($passpercent) || $string($passpercent, '/^\d{1,3}(\.\d{1,4})?$/'))
            && (float) $passpercent >= 0 && (float) $passpercent <= 100
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
            'pass_percent' => rtrim(rtrim(number_format((float) $passpercent, 4, '.', ''), '0'), '.'),
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
