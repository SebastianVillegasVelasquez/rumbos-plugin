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

namespace format_rumbos\external;

use core_external\external_api;
use format_rumbos\local\key_manager;
use format_rumbos\local\launch_assertion;

/**
 * Tests for the administrator's assertion preview.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\external\get_assertion_preview::class)]
final class assertion_preview_test extends \advanced_testcase {
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
     * Runs the function as the current user and validates the return structure.
     *
     * @param int $courseid
     * @return array
     */
    private function preview(int $courseid = 0): array {
        return external_api::clean_returnvalue(
            get_assertion_preview::execute_returns(),
            get_assertion_preview::execute($courseid)
        );
    }

    /**
     * An administrator gets the decoded header and claims.
     */
    public function test_administrator_gets_the_header_and_claims(): void {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['format' => 'rumbos']);

        $result = $this->preview();

        $this->assertSame('RS256', $result['header']['alg']);
        $this->assertSame('JWT', $result['header']['typ']);
        $this->assertSame(key_manager::get_kid(), $result['header']['kid']);
        $this->assertSame(launch_assertion::issuer(), $result['claims']['iss']);
        $this->assertSame('rumbos', $result['claims']['aud']);
        $this->assertSame((int) $course->id, $result['claims']['course']);
        $this->assertSame('editor', $result['claims']['role']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $result['claims']['sub']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $result['claims']['jti']);
    }

    /**
     * The sample has the expected lifetime.
     */
    public function test_the_lifetime_is_sixty_seconds(): void {
        $this->setAdminUser();

        $result = $this->preview();

        $this->assertSame(60, $result['lifetime']);
        $this->assertSame(60, $result['claims']['exp'] - $result['claims']['iat']);
        $this->assertEqualsWithDelta(time(), $result['claims']['iat'], 5);
    }

    /**
     * Neither the compact token nor the signature leaves the server.
     */
    public function test_the_token_and_the_signature_are_never_returned(): void {
        $this->setAdminUser();

        $result = $this->preview();
        $encoded = json_encode($result);

        $this->assertEqualsCanonicalizing(['header', 'claims', 'lifetime'], array_keys($result));
        $this->assertEqualsCanonicalizing(['alg', 'typ', 'kid'], array_keys($result['header']));
        $this->assertEqualsCanonicalizing(
            ['iss', 'aud', 'sub', 'course', 'role', 'iat', 'exp', 'jti'],
            array_keys($result['claims'])
        );
        // No three dot-separated base64url segments, which is what a compact JWS looks like.
        $this->assertDoesNotMatchRegularExpression('/[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}/', $encoded);
        // No value is long enough to hold a signature (an RS256 signature is 342 characters).
        foreach (array_merge($result['header'], $result['claims']) as $value) {
            $this->assertLessThan(100, strlen((string) $value));
        }
        $this->assertStringNotContainsString('PRIVATE', $encoded);
        $this->assertArrayNotHasKey('assertion', $result);
        $this->assertArrayNotHasKey('token', $result);
    }

    /**
     * The declared return structure has no place for a token.
     */
    public function test_the_return_structure_declares_no_token(): void {
        $keys = array_keys(get_assertion_preview::execute_returns()->keys);

        $this->assertEqualsCanonicalizing(['header', 'claims', 'lifetime'], $keys);
    }

    /**
     * Without a Rumbos course the sample is for the site.
     */
    public function test_without_a_rumbos_course_it_uses_the_site(): void {
        $this->setAdminUser();

        $this->assertSame((int) SITEID, $this->preview()['claims']['course']);
    }

    /**
     * A given course is used, and an unknown one is refused.
     */
    public function test_a_given_course_is_used_and_an_unknown_one_is_refused(): void {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();

        $this->assertSame((int) $course->id, $this->preview($course->id)['claims']['course']);

        $this->expectException(\invalid_parameter_exception::class);
        get_assertion_preview::execute(999999);
    }

    /**
     * Users without moodle/site:config are refused, whatever their role in a course.
     */
    public function test_it_requires_the_site_configuration_capability(): void {
        $course = $this->getDataGenerator()->create_course(['format' => 'rumbos']);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('manager', $manager->id);

        foreach ([$teacher, $manager] as $user) {
            $this->setUser($user);
            try {
                get_assertion_preview::execute();
                $this->fail('Expected a refusal for user ' . $user->id);
            } catch (\required_capability_exception $e) {
                $this->assertSame('nopermissions', $e->errorcode);
            }
        }
    }

    /**
     * Guests are refused.
     */
    public function test_guests_are_refused(): void {
        $this->setGuestUser();

        $this->expectException(\moodle_exception::class);
        get_assertion_preview::execute();
    }

    /**
     * The function is declared for AJAX and asks for the capability.
     */
    public function test_it_is_registered_for_ajax_with_the_capability(): void {
        global $CFG;
        $functions = [];
        require($CFG->dirroot . '/course/format/rumbos/db/services.php');

        $declared = $functions['format_rumbos_get_assertion_preview'];

        $this->assertTrue($declared['ajax']);
        $this->assertTrue($declared['loginrequired']);
        $this->assertSame('moodle/site:config', $declared['capabilities']);
    }
}
