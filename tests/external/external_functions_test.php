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
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use format_rumbos\local\key_manager;

/**
 * Tests for the AJAX external functions.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\external\get_course_state::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\external\get_launch_assertion::class)]
final class external_functions_test extends \advanced_testcase {
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
     * Creates a course with a student, a teacher and an outsider, and enables Rumbos.
     *
     * @return array [course, student, teacher, outsider]
     */
    private function setup_course(): array {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['format' => 'rumbos']);
        $student = $gen->create_user();
        $teacher = $gen->create_user();
        $outsider = $gen->create_user();
        $gen->enrol_user($student->id, $course->id, 'student');
        $gen->enrol_user($teacher->id, $course->id, 'editingteacher');
        set_config('enabled', 1, 'format_rumbos');
        set_config('appurl', 'https://rumbos.example.com', 'format_rumbos');
        return [$course, $student, $teacher, $outsider];
    }

    /**
     * Neither function takes a user id: the state is always the caller's.
     */
    public function test_no_userid_parameter(): void {
        $this->assertSame(['courseid'], array_keys(get_course_state::execute_parameters()->keys));
        $this->assertSame(['courseid'], array_keys(get_launch_assertion::execute_parameters()->keys));
    }

    /**
     * The state is the current user's and passes return validation.
     */
    public function test_state_is_for_the_current_user(): void {
        $this->resetAfterTest();
        [$course, $student, $teacher] = $this->setup_course();
        $hidden = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'visible' => 0]);

        $this->setUser($student);
        $studentstate = external_api::clean_returnvalue(
            get_course_state::execute_returns(),
            get_course_state::execute($course->id)
        );
        $this->setUser($teacher);
        $teacherstate = external_api::clean_returnvalue(
            get_course_state::execute_returns(),
            get_course_state::execute($course->id)
        );

        // The generator's courses do not track completion unless asked to.
        $this->assertFalse($studentstate['courseCompletionEnabled']);
        $this->assertSame([(int) $hidden->cmid], $studentstate['unavailableCmids']);
        $this->assertSame('hidden', $teacherstate['activities'][0]['availability']);
    }

    /**
     * Users who are not in the course cannot read its state.
     */
    public function test_state_requires_course_access(): void {
        $this->resetAfterTest();
        [$course, , , $outsider] = $this->setup_course();
        $this->setUser($outsider);

        $this->expectException(\moodle_exception::class);
        get_course_state::execute($course->id);
    }

    /**
     * Guests are not Rumbos participants.
     */
    public function test_guest_cannot_read_state(): void {
        $this->resetAfterTest();
        [$course] = $this->setup_course();
        $this->setGuestUser();

        $this->expectException(\moodle_exception::class);
        get_course_state::execute($course->id);
    }

    /**
     * A student gets a verifiable assertion with the student role.
     */
    public function test_launch_assertion_for_a_student(): void {
        $this->resetAfterTest();
        [$course, $student] = $this->setup_course();
        $this->setUser($student);

        $result = external_api::clean_returnvalue(
            get_launch_assertion::execute_returns(),
            get_launch_assertion::execute($course->id)
        );

        $claims = JWT::decode($result['assertion'], new Key(key_manager::get_public_key(), 'RS256'));
        $this->assertSame('student', $claims->role);
        $this->assertSame((int) $course->id, $claims->course);
        $this->assertSame($claims->exp, $result['expiresAt']);
        $this->assertLessThanOrEqual(60, $claims->exp - $claims->iat);
    }

    /**
     * An editing teacher gets the editor role.
     */
    public function test_launch_assertion_for_a_teacher(): void {
        $this->resetAfterTest();
        [$course, , $teacher] = $this->setup_course();
        $this->setUser($teacher);

        $result = get_launch_assertion::execute($course->id);

        $claims = JWT::decode($result['assertion'], new Key(key_manager::get_public_key(), 'RS256'));
        $this->assertSame('editor', $claims->role);
    }

    /**
     * Every call returns a fresh jti.
     */
    public function test_launch_assertion_is_fresh_each_time(): void {
        $this->resetAfterTest();
        [$course, $student] = $this->setup_course();
        $this->setUser($student);

        $first = get_launch_assertion::execute($course->id)['assertion'];
        $second = get_launch_assertion::execute($course->id)['assertion'];

        $key = new Key(key_manager::get_public_key(), 'RS256');
        $this->assertNotSame(JWT::decode($first, $key)->jti, JWT::decode($second, $key)->jti);
    }

    /**
     * No assertion is issued while Rumbos is disabled or unconfigured.
     */
    public function test_launch_assertion_requires_rumbos_enabled_and_configured(): void {
        $this->resetAfterTest();
        [$course, $student] = $this->setup_course();
        $this->setUser($student);

        set_config('enabled', 0, 'format_rumbos');
        try {
            get_launch_assertion::execute($course->id);
            $this->fail('Expected an exception while disabled');
        } catch (\moodle_exception $e) {
            $this->assertSame('errornotenabled', $e->errorcode);
        }

        set_config('enabled', 1, 'format_rumbos');
        unset_config('appurl', 'format_rumbos');
        $this->expectException(\moodle_exception::class);
        get_launch_assertion::execute($course->id);
    }

    /**
     * No assertion is issued for courses that do not use the Rumbos format.
     */
    public function test_launch_assertion_requires_the_rumbos_format(): void {
        $this->resetAfterTest();
        $this->setup_course();
        $topics = $this->getDataGenerator()->create_course(['format' => 'topics']);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $topics->id, 'student');
        $this->setUser($user);

        $this->expectException(\moodle_exception::class);
        get_launch_assertion::execute($topics->id);
    }

    /**
     * Users outside the course get no assertion.
     */
    public function test_launch_assertion_requires_course_access(): void {
        $this->resetAfterTest();
        [$course, , , $outsider] = $this->setup_course();
        $this->setUser($outsider);

        $this->expectException(\moodle_exception::class);
        get_launch_assertion::execute($course->id);
    }
}
