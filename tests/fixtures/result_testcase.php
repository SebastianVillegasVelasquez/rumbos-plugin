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

namespace format_rumbos;

use Firebase\JWT\JWT;
use format_rumbos\local\launch_assertion;
use format_rumbos\local\pseudonym;
use format_rumbos\local\result_config;
use format_rumbos\local\result_course;
use format_rumbos\local\result_receiver;

/**
 * Base of the result receiver tests: plays the Rumbos backend (signs statements with its own key) and sets a course up
 * the way the administrator's command does.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class result_testcase extends \advanced_testcase {
    /** @var string The backend's private key, made once for all the tests. */
    private static string $privatepem = '';

    /** @var string The backend's public key. */
    protected static string $publicpem = '';

    /** @var int The time the tests pretend it is. */
    protected int $now = 0;

    /**
     * Starts every test with a configured receiver and no stored keys of the plugin's own.
     *
     * The baseline database holds keys encrypted at install time with a key file PHPUnit discards between tests, so
     * they could not be decrypted (see launch_assertion_test).
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        foreach (['privatekey', 'publickey', 'kid', 'hmacsecret'] as $name) {
            unset_config($name, 'format_rumbos');
        }
        if (self::$privatepem === '') {
            $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($resource, self::$privatepem);
            self::$publicpem = openssl_pkey_get_details($resource)['key'];
        }
        set_config('resultsenabled', 1, 'format_rumbos');
        set_config('resultissuer', 'rumbos-test', 'format_rumbos');
        set_config('resulttenant', 'tenant-1', 'format_rumbos');
        set_config('resultkeys', self::$publicpem, 'format_rumbos');
        $this->now = time();
    }

    /**
     * Creates a Rumbos course, optionally set up for results as the administrator's command does.
     *
     * @param bool $configured Run the command's steps.
     * @return \stdClass The course.
     */
    protected function make_course(bool $configured = true): \stdClass {
        $course = $this->getDataGenerator()->create_course(['format' => 'rumbos', 'enablecompletion' => 1]);
        if ($configured) {
            foreach (result_course::plan((int) $course->id, '80', 'gt', true) as $step) {
                $step['run']();
            }
        }
        return $course;
    }

    /**
     * Creates a user enrolled in the course with a role.
     *
     * @param \stdClass $course
     * @param string $role Role shortname.
     * @return \stdClass The user.
     */
    protected function make_user(\stdClass $course, string $role = 'student'): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, $role);
        return $user;
    }

    /**
     * The claims of a valid statement for a user, with some overridden.
     *
     * @param \stdClass $course
     * @param \stdClass $user
     * @param array $override
     * @return array
     */
    protected function claims(\stdClass $course, \stdClass $user, array $override = []): array {
        static $counter = 0;
        $counter++;
        return $override + [
            'iss' => 'rumbos-test',
            'aud' => launch_assertion::issuer(),
            'jti' => 'jti-' . $counter . '-' . bin2hex(random_bytes(4)),
            'iat' => $this->now,
            'exp' => $this->now + 120,
            'tenant' => 'tenant-1',
            'sub' => pseudonym::for_user((int) $user->id),
            'course_id' => (int) $course->id,
            'purpose' => 'check_in',
            'attempt_id' => 'attempt-' . $counter . '-' . bin2hex(random_bytes(4)),
            'percent' => '90.00',
            'passed' => true,
            'policy' => ['pass_percent' => 80, 'pass_operator' => 'gt'],
            'finished_at' => gmdate('Y-m-d\TH:i:s\Z', $this->now),
        ];
    }

    /**
     * Signs claims like the backend does.
     *
     * @param array $claims
     * @param string|null $kid The key id of the header; the configured key's by default.
     * @return string The JWS.
     */
    protected function sign(array $claims, ?string $kid = null): string {
        return JWT::encode($claims, self::$privatepem, 'RS256', $kid ?? result_config::key_id(self::$publicpem));
    }

    /**
     * Changes one character in the middle of a statement's signature.
     *
     * Not the last one: the signature of a 2048-bit key is 342 base64url characters for 256 bytes, so the last character
     * carries four padding bits that a decoder ignores. Changing it left the bytes as they were about one time in four,
     * and the "tampered" statement was then rightly accepted.
     *
     * @param string $jws
     * @return string
     */
    protected function tamper(string $jws): string {
        $start = strrpos($jws, '.') + 1;
        $middle = $start + intdiv(strlen($jws) - $start, 2);
        $tampered = substr_replace($jws, $jws[$middle] === 'A' ? 'B' : 'A', $middle, 1);
        $this->assertNotSame(
            JWT::urlsafeB64Decode(substr($jws, $start)),
            JWT::urlsafeB64Decode(substr($tampered, $start)),
            'the tampering must change the bytes of the signature'
        );
        return $tampered;
    }

    /**
     * The text of a file of the service's golden vectors.
     *
     * @param string $file
     * @return string
     */
    protected function vector_file(string $file): string {
        return file_get_contents(__DIR__ . '/service_vectors/' . $file);
    }

    /**
     * One golden vector: `jws`, `header` and `claims`, as the service wrote them.
     *
     * @param string $name result, attempt or definition.
     * @return \stdClass
     */
    protected function vector(string $name): \stdClass {
        return json_decode($this->vector_file($name . '.json'));
    }

    /**
     * A new EC P-256 key pair of the tests' own.
     *
     * @param string $kid
     * @return array The private key in PEM, and the public JWK as the setting takes it.
     */
    protected function ec_key(string $kid): array {
        $resource = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($resource, $private);
        return [$private, result_config::jwk_from_pem(openssl_pkey_get_details($resource)['key'], $kid)];
    }

    /**
     * Posts a statement to the receiver.
     *
     * @param string $statement The JWS.
     * @param int|null $now
     * @return array The receiver's reply.
     */
    protected function post(string $statement, ?int $now = null): array {
        return result_receiver::handle('POST', json_encode(['statement' => $statement]), $now ?? $this->now);
    }

    /**
     * Signs and posts claims.
     *
     * @param array $claims
     * @return array The receiver's reply.
     */
    protected function send(array $claims): array {
        return $this->post($this->sign($claims));
    }

    /**
     * Asserts the status and the reason of a reply.
     *
     * @param array $reply
     * @param int $status
     * @param string $reason
     * @param bool|null $applied
     */
    protected function assert_reply(array $reply, int $status, string $reason, ?bool $applied = null): void {
        $this->assertSame($status, $reply['status'], 'reason answered: ' . $reply['body']['reason']);
        $this->assertSame($reason, $reply['body']['reason']);
        $this->assertSame($applied ?? ($status === 200 && $reason !== 'already_applied'), $reply['body']['applied']);
    }

    /**
     * Asserts that the last audit row says what was answered, and returns it.
     *
     * @param int $status
     * @param string $reason The start of the audited reason.
     * @return \stdClass The audit row.
     */
    protected function assert_audited(int $status, string $reason): \stdClass {
        global $DB;

        $rows = $DB->get_records('format_rumbos_audit', null, 'id DESC', '*', 0, 1);
        $this->assertNotEmpty($rows, 'no audit row was written');
        $row = reset($rows);
        $this->assertSame($status, (int) $row->status);
        $this->assertStringStartsWith($reason, $row->reason);
        return $row;
    }

    /**
     * The result grade of a user, as hundredths, or null.
     *
     * @param \stdClass $course
     * @param \stdClass $user
     * @return int|null
     */
    protected function grade_cents(\stdClass $course, \stdClass $user): ?int {
        $item = result_course::grade_item((int) $course->id);
        $grade = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $user->id]);
        return $grade && $grade->finalgrade !== null ? (int) round($grade->finalgrade * 100) : null;
    }

    /**
     * Whether the course is complete for the user, by Moodle's own count.
     *
     * @param \stdClass $course
     * @param \stdClass $user
     * @return bool
     */
    protected function is_complete(\stdClass $course, \stdClass $user): bool {
        return (new \completion_info($course))->is_course_complete($user->id);
    }
}
