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

use core\context\course as context_course;
use core_external\external_api;
use format_rumbos\local\flow_progress;
use format_rumbos\local\flow_rate_limit;

/**
 * Tests for the welcome-flow functions: read, record and reset the progress.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\external\get_flow_progress::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\external\set_flow_step_done::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\external\reset_flow_progress::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\local\flow_session::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\local\flow_rate_limit::class)]
final class flow_functions_test extends \advanced_testcase {
    /** @var string A map id. */
    private const MAP = '3f0c2a52-6c1e-4f6e-9a8e-0d6b1f9a7c11';

    /** @var string Another map id. */
    private const OTHERMAP = '11111111-2222-4333-8444-555555555555';

    /**
     * Starts every test with an empty rate counter.
     */
    protected function setUp(): void {
        parent::setUp();
        \cache::make('format_rumbos', 'flowrate')->purge();
    }

    /**
     * A Rumbos course with a student, an editing teacher and another student.
     *
     * @return array [course, student, teacher, otherstudent]
     */
    private function course(): array {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'rumbos']);
        return [
            $course,
            $generator->create_and_enrol($course, 'student'),
            $generator->create_and_enrol($course, 'editingteacher'),
            $generator->create_and_enrol($course, 'student'),
        ];
    }

    /**
     * Runs a function the way the AJAX layer does: validated, then cleaned against its declared structure.
     *
     * @param string $class The external class.
     * @param array $args Arguments by name.
     * @return array
     */
    private function call(string $class, array $args): array {
        return external_api::clean_returnvalue($class::execute_returns(), $class::execute(...$args));
    }

    /**
     * Reads the progress of the current user on the map.
     *
     * @param \stdClass $course
     * @param string $mapid
     * @return array
     */
    private function read(\stdClass $course, string $mapid = self::MAP): array {
        return $this->call(get_flow_progress::class, ['courseid' => $course->id, 'mapid' => $mapid]);
    }

    /**
     * Records a step as the current user.
     *
     * @param \stdClass $course
     * @param string $stepkey
     * @param int $revision
     * @param string $method
     * @param string $mapid
     * @return array
     */
    private function done(
        \stdClass $course,
        string $stepkey = 'video',
        int $revision = 1,
        string $method = 'completed',
        string $mapid = self::MAP
    ): array {
        return $this->call(set_flow_step_done::class, [
            'courseid' => $course->id, 'mapid' => $mapid, 'stepkey' => $stepkey, 'revision' => $revision, 'method' => $method,
        ]);
    }

    // The read.

    /**
     * A student gets their own rows, the course threshold, and persistent = true.
     */
    public function test_a_student_reads_their_own_progress(): void {
        [$course, $student, , $other] = $this->course();
        flow_progress::record($course->id, $student->id, self::MAP, 'video', 1, 'completed', 1700000000);
        flow_progress::record($course->id, $other->id, self::MAP, 'secret', 1, 'fallback', 1700000500);
        flow_progress::record($course->id, $student->id, self::OTHERMAP, 'elsewhere', 1, 'completed');
        $this->setUser($student);

        $reply = $this->read($course);

        $this->assertSame('', $reply['error']);
        $this->assertTrue($reply['persistent']);
        $this->assertSame(['quizThreshold' => 80], $reply['config']);
        $this->assertSame(
            [['stepKey' => 'video', 'revision' => 1, 'method' => 'completed', 'completedAt' => '2023-11-14T22:13:20Z']],
            $reply['items']
        );
        $this->assertStringNotContainsString('secret', json_encode($reply), 'nothing about another user');
        $this->assertStringNotContainsString('elsewhere', json_encode($reply), 'nothing about another map');
    }

    /**
     * The threshold of the course is the one reported.
     */
    public function test_the_threshold_comes_from_the_course(): void {
        [$course, $student] = $this->course();
        course_get_format($course)->update_course_format_options(['rumbos_quizthreshold' => 55]);
        $this->setUser($student);

        $this->assertSame(['quizThreshold' => 55], $this->read($course)['config']);
    }

    /**
     * A map id that is not a UUID is refused.
     */
    public function test_the_read_refuses_a_bad_map_id(): void {
        [$course, $student] = $this->course();
        $this->setUser($student);

        $this->expectException(\invalid_parameter_exception::class);
        $this->read($course, 'not-a-uuid');
    }

    /**
     * Somebody with no access to the course cannot read.
     */
    public function test_the_read_needs_access_to_the_course(): void {
        [$course] = $this->course();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\moodle_exception::class);
        $this->read($course);
    }

    /**
     * A guest reads nothing and is told the progress cannot be stored.
     */
    public function test_a_guest_is_not_persistent(): void {
        global $DB;
        [$course, $student] = $this->course();
        flow_progress::record($course->id, $student->id, self::MAP, 'video', 1, 'completed');
        $DB->set_field('enrol', 'status', ENROL_INSTANCE_ENABLED, ['courseid' => $course->id, 'enrol' => 'guest']);
        $this->setGuestUser();

        $reply = $this->read($course);

        $this->assertFalse($reply['persistent']);
        $this->assertSame([], $reply['items']);
    }

    /**
     * Someone logged in as a student sees that student's progress, but it cannot be stored.
     */
    public function test_log_in_as_is_not_persistent(): void {
        [$course, $student] = $this->course();
        flow_progress::record($course->id, $student->id, self::MAP, 'video', 1, 'completed');
        $this->setAdminUser();
        \core\session\manager::loginas($student->id, \context_system::instance());

        $reply = $this->read($course);

        $this->assertFalse($reply['persistent']);
        $this->assertSame(['video'], array_column($reply['items'], 'stepKey'));
    }

    // The write.

    /**
     * A step is stored and the answer is the refreshed progress.
     */
    public function test_a_step_is_recorded(): void {
        global $DB;
        [$course, $student] = $this->course();
        $this->setUser($student);

        $reply = $this->done($course, 'video', 2, 'fallback');

        $this->assertSame('', $reply['error']);
        $this->assertSame('stored', $reply['outcome']);
        $this->assertTrue($reply['persistent']);
        $this->assertSame([['video', 2, 'fallback']], array_map(
            fn($item) => [$item['stepKey'], $item['revision'], $item['method']],
            $reply['items']
        ));
        $row = $DB->get_record(flow_progress::TABLE, []);
        $this->assertSame(
            [(int) $course->id, (int) $student->id, self::MAP],
            [(int) $row->courseid, (int) $row->userid, $row->mapid]
        );
    }

    /**
     * The same step twice changes nothing; a higher revision replaces it.
     */
    public function test_the_write_is_idempotent(): void {
        global $DB;
        [$course, $student] = $this->course();
        $this->setUser($student);

        $first = $this->done($course, 'video', 1);
        $again = $this->done($course, 'video', 1, 'fallback');
        $higher = $this->done($course, 'video', 2, 'unavailable');

        $this->assertSame(['stored', 'unchanged', 'replaced'], [$first['outcome'], $again['outcome'], $higher['outcome']]);
        $this->assertSame(1, $DB->count_records(flow_progress::TABLE));
        $this->assertSame([2, 'unavailable'], [$higher['items'][0]['revision'], $higher['items'][0]['method']]);
    }

    /**
     * Every value is checked again on the server, and nothing is stored for a refused call.
     *
     * @param array $override Arguments to replace.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bad_arguments')]
    public function test_the_write_checks_every_value(array $override): void {
        global $DB;
        [$course, $student] = $this->course();
        $this->setUser($student);
        $args = ['courseid' => $course->id, 'mapid' => self::MAP, 'stepkey' => 'video', 'revision' => 1, 'method' => 'completed'];

        try {
            $this->call(set_flow_step_done::class, $override + $args);
            $this->fail('The call was accepted');
        } catch (\invalid_parameter_exception $e) {
            $this->assertSame(0, $DB->count_records(flow_progress::TABLE));
        }
    }

    /**
     * Arguments that must be refused.
     *
     * @return array
     */
    public static function bad_arguments(): array {
        return [
            'map id' => [['mapid' => '1234']],
            'map id with a newline' => [['mapid' => self::MAP . "\n"]],
            'empty step key' => [['stepkey' => '']],
            'long step key' => [['stepkey' => str_repeat('a', 17)]],
            'step key with a space' => [['stepkey' => 'a b']],
            'step key with markup' => [['stepkey' => '<b>']],
            'negative revision' => [['revision' => -1]],
            'huge revision' => [['revision' => 2000000]],
            'unknown method' => [['method' => 'done']],
            'method in capitals' => [['method' => 'COMPLETED']],
        ];
    }

    /**
     * A guest and someone logged in as another user get "readonly", not an exception, and nothing is stored.
     */
    public function test_the_write_is_readonly_for_a_guest_and_for_log_in_as(): void {
        global $DB;
        [$course, $student] = $this->course();
        $DB->set_field('enrol', 'status', ENROL_INSTANCE_ENABLED, ['courseid' => $course->id, 'enrol' => 'guest']);

        $this->setGuestUser();
        $guest = $this->done($course);
        $this->assertSame(['readonly', 'readonly', false], [$guest['error'], $guest['outcome'], $guest['persistent']]);

        $this->setAdminUser();
        \core\session\manager::loginas($student->id, \context_system::instance());
        $loggedinas = $this->done($course);
        $this->assertSame(['readonly', false], [$loggedinas['error'], $loggedinas['persistent']]);

        $this->assertSame(0, $DB->count_records(flow_progress::TABLE));
    }

    /**
     * Somebody with no access to the course cannot write.
     */
    public function test_the_write_needs_access_to_the_course(): void {
        global $DB;
        [$course] = $this->course();
        $this->setUser($this->getDataGenerator()->create_user());

        try {
            $this->done($course);
            $this->fail('The call was accepted');
        } catch (\moodle_exception $e) {
            $this->assertSame(0, $DB->count_records(flow_progress::TABLE));
        }
    }

    /**
     * A user who may see the course but takes no part in it gets "readonly".
     */
    public function test_a_user_outside_the_course_gets_readonly(): void {
        global $DB;
        [$course] = $this->course();
        $viewer = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('moodle/course:view', CAP_ALLOW, $roleid, context_course::instance($course->id)->id, true);
        role_assign($roleid, $viewer->id, context_course::instance($course->id)->id);
        $this->setUser($viewer);

        $reply = $this->done($course);

        $this->assertSame(['readonly', false], [$reply['error'], $reply['persistent']]);
        $this->assertSame(0, $DB->count_records(flow_progress::TABLE));
    }

    /**
     * After 30 writes in a minute the next one is refused, and the others are not affected.
     */
    public function test_the_rate_limit(): void {
        global $DB;
        [$course, $student, , $other] = $this->course();
        $this->setUser($student);

        for ($i = 0; $i < flow_rate_limit::LIMIT; $i++) {
            $this->assertSame('', $this->done($course, 's' . $i)['error'], 'write ' . $i);
        }
        $blocked = $this->done($course, 'extra');

        $this->assertSame(['unavailable', 'ratelimited'], [$blocked['error'], $blocked['outcome']]);
        $this->assertSame(flow_rate_limit::LIMIT, $DB->count_records(flow_progress::TABLE));
        $this->assertNotContains('extra', array_column($blocked['items'], 'stepKey'));

        $this->setUser($other);
        $this->assertSame('', $this->done($course, 'extra')['error'], 'another user has their own allowance');
    }

    /**
     * The window moves on: a minute later the user may write again.
     */
    public function test_the_rate_window_expires(): void {
        $now = 1700000000;
        for ($i = 0; $i < flow_rate_limit::LIMIT; $i++) {
            $this->assertTrue(flow_rate_limit::allow(7, $now));
        }
        $this->assertFalse(flow_rate_limit::allow(7, $now + 10));
        $this->assertTrue(flow_rate_limit::allow(7, $now + flow_rate_limit::WINDOW));
        $this->assertTrue(flow_rate_limit::allow(8, $now));
    }

    /**
     * At the row cap a new step is refused, and the answer says so without throwing.
     */
    public function test_the_row_cap_through_the_function(): void {
        global $DB;
        [$course, $student] = $this->course();
        $rows = [];
        for ($i = 0; $i < flow_progress::MAX_ROWS; $i++) {
            $rows[] = (object) [
                'courseid' => $course->id, 'userid' => $student->id, 'mapid' => self::MAP, 'stepkey' => 'k' . $i,
                'revision' => 1, 'method' => 'completed', 'timecompleted' => 1000,
            ];
        }
        $DB->insert_records(flow_progress::TABLE, $rows);
        $this->setUser($student);

        $reply = $this->done($course, 'extra');

        $this->assertSame(['unavailable', 'capped'], [$reply['error'], $reply['outcome']]);
        $this->assertSame(flow_progress::MAX_ROWS, $DB->count_records(flow_progress::TABLE));
    }

    /**
     * Whatever the answer, it holds nothing about other users.
     */
    public function test_the_answer_has_nothing_about_other_users(): void {
        [$course, $student, $teacher, $other] = $this->course();
        flow_progress::record($course->id, $other->id, self::MAP, 'theirs', 3, 'fallback', 1700000999);
        flow_progress::record($course->id, $teacher->id, self::MAP, 'teachers', 1, 'completed');
        $this->setUser($student);

        $reply = $this->done($course, 'mine');

        $this->assertSame(['mine'], array_column($reply['items'], 'stepKey'));
        $this->assertSame(['error', 'outcome', 'persistent', 'config', 'items'], array_keys($reply));
        $this->assertStringNotContainsString((string) $other->id, json_encode($reply));
        $this->assertStringNotContainsString('1700000999', json_encode($reply));
    }

    // The reset.

    /**
     * Resetting your own progress needs the edit capability, and a student does not have it.
     */
    public function test_a_student_cannot_reset(): void {
        global $DB;
        [$course, $student] = $this->course();
        flow_progress::record($course->id, $student->id, self::MAP, 'video', 1, 'completed');
        $this->setUser($student);

        try {
            $this->call(reset_flow_progress::class, ['courseid' => $course->id, 'mapid' => self::MAP, 'userid' => 0]);
            $this->fail('The call was accepted');
        } catch (\required_capability_exception $e) {
            $this->assertSame(1, $DB->count_records(flow_progress::TABLE));
        }
    }

    /**
     * An editor resets their own progress on that map only.
     */
    public function test_an_editor_resets_their_own_progress(): void {
        global $DB;
        [$course, $student, $teacher] = $this->course();
        flow_progress::record($course->id, $teacher->id, self::MAP, 'video', 1, 'completed');
        flow_progress::record($course->id, $teacher->id, self::OTHERMAP, 'video', 1, 'completed');
        flow_progress::record($course->id, $student->id, self::MAP, 'video', 1, 'completed');
        $this->setUser($teacher);

        $reply = $this->call(reset_flow_progress::class, ['courseid' => $course->id, 'mapid' => self::MAP]);

        $this->assertSame(['', 'reset', []], [$reply['error'], $reply['outcome'], $reply['items']]);
        $this->assertSame(0, $DB->count_records(flow_progress::TABLE, ['userid' => $teacher->id, 'mapid' => self::MAP]));
        $this->assertSame(1, $DB->count_records(flow_progress::TABLE, ['userid' => $teacher->id, 'mapid' => self::OTHERMAP]));
        $this->assertSame(1, $DB->count_records(flow_progress::TABLE, ['userid' => $student->id]));
    }

    /**
     * Resetting another user needs the new capability, and the answer is still the caller's own.
     */
    public function test_resetting_another_user_needs_the_resetflow_capability(): void {
        global $DB;
        [$course, $student, $teacher, $other] = $this->course();
        flow_progress::record($course->id, $student->id, self::MAP, 'video', 1, 'completed');
        flow_progress::record($course->id, $other->id, self::MAP, 'video', 1, 'completed');
        $context = context_course::instance($course->id);
        $teacherrole = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        $this->setUser($teacher);

        $args = ['courseid' => $course->id, 'mapid' => self::MAP, 'userid' => $student->id];

        assign_capability('format/rumbos:resetflow', CAP_PROHIBIT, $teacherrole, $context->id, true);
        try {
            $this->call(reset_flow_progress::class, $args);
            $this->fail('The call was accepted');
        } catch (\required_capability_exception $e) {
            $this->assertSame(2, $DB->count_records(flow_progress::TABLE));
        }

        assign_capability('format/rumbos:resetflow', CAP_ALLOW, $teacherrole, $context->id, true);
        $reply = $this->call(reset_flow_progress::class, $args);

        $this->assertSame([], $reply['items'], 'the answer is the caller\'s progress, not the student\'s');
        $this->assertSame(0, $DB->count_records(flow_progress::TABLE, ['userid' => $student->id]));
        $this->assertSame(1, $DB->count_records(flow_progress::TABLE, ['userid' => $other->id]), 'only the one asked for');
    }

    /**
     * A student may not reset anybody else either.
     */
    public function test_a_student_cannot_reset_another_user(): void {
        [$course, $student, , $other] = $this->course();
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);
        $this->call(reset_flow_progress::class, ['courseid' => $course->id, 'mapid' => self::MAP, 'userid' => $other->id]);
    }

    /**
     * The target of a reset must be a real, active account.
     */
    public function test_the_target_of_a_reset_must_be_a_real_user(): void {
        [$course, , $teacher] = $this->course();
        $deleted = $this->getDataGenerator()->create_user();
        delete_user($deleted);
        $this->setUser($teacher);

        foreach ([999999, $deleted->id, guest_user()->id] as $userid) {
            try {
                $this->call(reset_flow_progress::class, ['courseid' => $course->id, 'mapid' => self::MAP, 'userid' => $userid]);
                $this->fail('The call was accepted for ' . $userid);
            } catch (\moodle_exception $e) {
                $this->assertNotEmpty($e->errorcode);
            }
        }
    }

    /**
     * An editor who is logged in as does not reset anything: "readonly".
     */
    public function test_the_reset_is_readonly_with_log_in_as(): void {
        global $DB;
        [$course, , $teacher] = $this->course();
        flow_progress::record($course->id, $teacher->id, self::MAP, 'video', 1, 'completed');
        $this->setAdminUser();
        \core\session\manager::loginas($teacher->id, \context_system::instance());

        $reply = $this->call(reset_flow_progress::class, ['courseid' => $course->id, 'mapid' => self::MAP]);

        $this->assertSame(['readonly', false], [$reply['error'], $reply['persistent']]);
        $this->assertSame(1, $DB->count_records(flow_progress::TABLE));
    }

    /**
     * The reset takes the same checks of the map id as everything else.
     */
    public function test_the_reset_refuses_a_bad_map_id(): void {
        [$course, , $teacher] = $this->course();
        $this->setUser($teacher);

        $this->expectException(\invalid_parameter_exception::class);
        $this->call(reset_flow_progress::class, ['courseid' => $course->id, 'mapid' => 'nope']);
    }

    /**
     * The new capability exists, for the roles that edit the course.
     */
    public function test_the_resetflow_capability(): void {
        global $DB;
        $this->resetAfterTest();
        $capability = get_capability_info('format/rumbos:resetflow');
        $this->assertNotFalse($capability);
        $this->assertSame('write', $capability->captype);

        foreach (['editingteacher', 'manager'] as $shortname) {
            $roleid = $DB->get_field('role', 'id', ['shortname' => $shortname]);
            $this->assertTrue($DB->record_exists('role_capabilities', [
                'roleid' => $roleid, 'capability' => 'format/rumbos:resetflow', 'permission' => CAP_ALLOW,
            ]), $shortname);
        }
        foreach (['student', 'teacher'] as $shortname) {
            $roleid = $DB->get_field('role', 'id', ['shortname' => $shortname]);
            $this->assertFalse($DB->record_exists('role_capabilities', [
                'roleid' => $roleid, 'capability' => 'format/rumbos:resetflow',
            ]), $shortname);
        }
    }
}
