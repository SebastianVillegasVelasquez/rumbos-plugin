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
use Firebase\JWT\Key;

/**
 * Tests for the launch assertion, verified like the backend would.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\local\launch_assertion::class)]
final class launch_assertion_test extends \advanced_testcase {
    /**
     * Starts every test without stored keys.
     *
     * The baseline database holds keys that were encrypted at install time with
     * a key file PHPUnit discards between tests, so they could not be decrypted.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        foreach (['privatekey', 'publickey', 'kid', 'hmacsecret'] as $name) {
            unset_config($name, 'format_rumbos');
        }
    }

    /**
     * Decodes and verifies a token with the published public key.
     *
     * @param string $token
     * @return \stdClass Claims.
     */
    private function verify(string $token): \stdClass {
        return JWT::decode($token, new Key(key_manager::get_public_key(), 'RS256'));
    }

    /**
     * Creates a course with an enrolled user.
     *
     * @param string $role Role shortname.
     * @return array [course, user]
     */
    private function enrolled(string $role): array {
        $course = $this->getDataGenerator()->create_course(['format' => 'rumbos']);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, $role);
        return [$course, $user];
    }

    /**
     * The token verifies with the public key and carries exactly the contract claims.
     */
    public function test_claims_and_header_match_the_contract(): void {
        global $CFG;
        $this->resetAfterTest();
        [$course, $user] = $this->enrolled('student');
        $now = time();

        $result = launch_assertion::create($course->id, $user->id, $now);
        $claims = $this->verify($result['token']);

        $this->assertEqualsCanonicalizing(
            ['iss', 'aud', 'sub', 'course', 'role', 'iat', 'exp', 'jti'],
            array_keys((array) $claims)
        );
        $this->assertSame(rtrim($CFG->wwwroot, '/'), $claims->iss);
        $this->assertSame('rumbos', $claims->aud);
        $this->assertSame(pseudonym::for_user($user->id), $claims->sub);
        $this->assertSame((int) $course->id, $claims->course);
        $this->assertSame('student', $claims->role);
        $this->assertSame($now, $claims->iat);
        $this->assertSame($now + 60, $claims->exp);
        $this->assertSame($now + 60, $result['expiresat']);

        [$header] = explode('.', $result['token']);
        $header = json_decode(JWT::urlsafeB64Decode($header), true);
        $this->assertSame('RS256', $header['alg']);
        $this->assertSame('JWT', $header['typ']);
        $this->assertSame(key_manager::get_kid(), $header['kid']);
    }

    /**
     * The issuer is the wwwroot in one canonical form, the one the service stores.
     */
    public function test_issuer_is_canonical(): void {
        global $CFG;
        $this->resetAfterTest();

        $expected = [
            'HTTPS://LMS.Example.com:443/moodle/' => 'https://lms.example.com/moodle',
            'https://lms.example.com/' => 'https://lms.example.com',
            'https://lms.example.com' => 'https://lms.example.com',
            'https://lms.example.com:8443/a/b/' => 'https://lms.example.com:8443/a/b',
        ];
        foreach ($expected as $wwwroot => $issuer) {
            $CFG->wwwroot = $wwwroot;
            $this->assertSame($issuer, launch_assertion::issuer(), $wwwroot);
        }
    }

    /**
     * The assertion is signed with the very issuer that issuer() reports.
     */
    public function test_the_assertion_carries_the_reported_issuer(): void {
        global $CFG;
        $this->resetAfterTest();
        [$course, $user] = $this->enrolled('student');
        $CFG->wwwroot = 'https://LMS.Example.com:443/moodle/';

        $claims = $this->verify(launch_assertion::create($course->id, $user->id)['token']);

        $this->assertSame(launch_assertion::issuer(), $claims->iss);
        $this->assertSame('https://lms.example.com/moodle', $claims->iss);
    }

    /**
     * The audience is configurable.
     */
    public function test_audience_is_configurable(): void {
        $this->resetAfterTest();
        [$course, $user] = $this->enrolled('student');
        set_config('audience', 'rumbos-staging', 'format_rumbos');

        $claims = $this->verify(launch_assertion::create($course->id, $user->id)['token']);

        $this->assertSame('rumbos-staging', $claims->aud);
    }

    /**
     * The role claim follows format/rumbos:editmap.
     */
    public function test_role_follows_the_capability(): void {
        $this->resetAfterTest();
        [$course, $teacher] = $this->enrolled('editingteacher');
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $noneditingteacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($noneditingteacher->id, $course->id, 'teacher');

        $this->assertSame('editor', $this->verify(launch_assertion::create($course->id, $teacher->id)['token'])->role);
        $this->assertSame('student', $this->verify(launch_assertion::create($course->id, $student->id)['token'])->role);
        $this->assertSame(
            'student',
            $this->verify(launch_assertion::create($course->id, $noneditingteacher->id)['token'])->role
        );

        // Granting the capability to the non-editing teacher role makes them an editor.
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(
            'format/rumbos:editmap',
            CAP_ALLOW,
            $roleid,
            \core\context\course::instance($course->id)->id
        );
        $this->getDataGenerator()->role_assign($roleid, $student->id, \core\context\course::instance($course->id)->id);
        $this->assertSame('editor', $this->verify(launch_assertion::create($course->id, $student->id)['token'])->role);
    }

    /**
     * Every assertion has a fresh random jti of at least 128 bits.
     */
    public function test_jti_is_unique_and_at_least_128_bits(): void {
        $this->resetAfterTest();
        [$course, $user] = $this->enrolled('student');

        $first = $this->verify(launch_assertion::create($course->id, $user->id)['token'])->jti;
        $second = $this->verify(launch_assertion::create($course->id, $user->id)['token'])->jti;

        $this->assertNotSame($first, $second);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32,}$/', $first);
    }

    /**
     * The assertion carries no personal data.
     */
    public function test_no_personal_data(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['format' => 'rumbos']);
        $user = $this->getDataGenerator()->create_user([
            'firstname' => 'Zenobia',
            'lastname' => 'Quintanilla',
            'email' => 'zenobia.quintanilla@example.com',
            'username' => 'zquintanilla',
        ]);
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');

        $token = launch_assertion::create($course->id, $user->id)['token'];
        $payload = JWT::urlsafeB64Decode(explode('.', $token)[1]);

        foreach (['Zenobia', 'Quintanilla', 'zenobia.quintanilla', 'zquintanilla'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $payload);
        }
    }

    /**
     * Tokens signed with an old key do not verify after rotation.
     */
    public function test_rotation_invalidates_previous_tokens(): void {
        $this->resetAfterTest();
        [$course, $user] = $this->enrolled('student');
        $token = launch_assertion::create($course->id, $user->id)['token'];

        key_manager::rotate();

        $this->expectException(\Firebase\JWT\SignatureInvalidException::class);
        $this->verify($token);
    }

    /**
     * A token whose algorithm header is tampered with is rejected by a pinned verifier.
     */
    public function test_pinned_algorithm_rejects_other_algorithms(): void {
        $this->resetAfterTest();
        [$course, $user] = $this->enrolled('student');
        $token = launch_assertion::create($course->id, $user->id)['token'];
        [, $payload, $signature] = explode('.', $token);
        $forgedheader = JWT::urlsafeB64Encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));

        $this->expectException(\UnexpectedValueException::class);
        $this->verify("$forgedheader.$payload.$signature");
    }
}
