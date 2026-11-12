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

namespace format_rumbos\privacy;

use core\context\course as context_course;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use format_rumbos\local\flow_progress;

/**
 * Tests for the privacy provider.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\privacy\provider::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\observer::class)]
final class provider_test extends \core_privacy\tests\provider_testcase {
    /** @var string A map id. */
    private const MAP = '3f0c2a52-6c1e-4f6e-9a8e-0d6b1f9a7c11';

    /**
     * Two users and two courses, each user with steps in both courses.
     *
     * @return array [user1, user2, course1, course2]
     */
    private function build(): array {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $users = [$generator->create_user(), $generator->create_user()];
        $courses = [$generator->create_course(['format' => 'rumbos']), $generator->create_course(['format' => 'rumbos'])];
        foreach ($users as $user) {
            foreach ($courses as $course) {
                flow_progress::record($course->id, $user->id, self::MAP, 'video', 1, 'completed', 1700000000);
                flow_progress::record($course->id, $user->id, self::MAP, 'quiz', 2, 'fallback', 1700000100);
            }
        }
        return [...$users, ...$courses];
    }

    /**
     * The metadata declares the table and the external location, and every field has a string.
     */
    public function test_metadata_declares_the_table_and_the_external_location(): void {
        $items = provider::get_metadata(new collection('format_rumbos'))->get_collection();

        $this->assertCount(2, $items);
        $strings = get_string_manager();

        $table = $items[0];
        $this->assertInstanceOf(\core_privacy\local\metadata\types\database_table::class, $table);
        $this->assertSame('format_rumbos_flow', $table->get_name());
        $this->assertEqualsCanonicalizing(
            ['courseid', 'userid', 'mapid', 'stepkey', 'revision', 'method', 'timecompleted'],
            array_keys($table->get_privacy_fields())
        );
        $this->assertTrue($strings->string_exists($table->get_summary(), 'format_rumbos'));

        $location = $items[1];
        $this->assertInstanceOf(\core_privacy\local\metadata\types\external_location::class, $location);
        $this->assertSame('rumbos', $location->get_name());
        $this->assertEqualsCanonicalizing(
            ['userid', 'courseid', 'role', 'ipaddress'],
            array_keys($location->get_privacy_fields())
        );
        $this->assertTrue($strings->string_exists($location->get_summary(), 'format_rumbos'));

        foreach ([$table, $location] as $item) {
            foreach ($item->get_privacy_fields() as $identifier) {
                $this->assertTrue($strings->string_exists($identifier, 'format_rumbos'), $identifier);
            }
        }
    }

    /**
     * The declaration does not claim that the plugin sends anything it does not.
     */
    public function test_metadata_does_not_declare_direct_identifiers(): void {
        $location = provider::get_metadata(new collection('format_rumbos'))->get_collection()[1];

        foreach (['name', 'email', 'username', 'fullname'] as $field) {
            $this->assertArrayNotHasKey($field, $location->get_privacy_fields());
        }
    }

    /**
     * A user with no steps has no contexts, and the export and the delete are harmless.
     */
    public function test_a_user_without_steps(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course(['format' => 'rumbos']);
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');

        $this->assertEmpty(provider::get_contexts_for_userid($user->id)->get_contextids());

        $contextlist = new approved_contextlist($user, 'format_rumbos', [context_course::instance($course->id)->id]);
        provider::export_user_data($contextlist);
        $this->assertFalse(writer::with_context(context_course::instance($course->id))->has_any_data());

        provider::delete_data_for_user($contextlist);
        provider::delete_data_for_all_users_in_context(context_course::instance($course->id));
    }

    /**
     * The contexts are the courses where the user completed a step, and only those.
     */
    public function test_get_contexts_for_userid(): void {
        [$user1, , $course1, $course2] = $this->build();
        $third = $this->getDataGenerator()->create_course(['format' => 'rumbos']);

        $contexts = provider::get_contexts_for_userid($user1->id)->get_contextids();

        $this->assertEqualsCanonicalizing(
            [context_course::instance($course1->id)->id, context_course::instance($course2->id)->id],
            array_map('intval', $contexts)
        );
        $this->assertNotContains(context_course::instance($third->id)->id, array_map('intval', $contexts));
    }

    /**
     * The users of a course context are the ones with a step in it; other context levels give none.
     */
    public function test_get_users_in_context(): void {
        [$user1, $user2, $course1] = $this->build();
        $nobody = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($nobody->id, $course1->id, 'student');

        $userlist = new userlist(context_course::instance($course1->id), 'format_rumbos');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing([$user1->id, $user2->id], array_map('intval', $userlist->get_userids()));

        $sitelist = new userlist(\core\context\system::instance(), 'format_rumbos');
        provider::get_users_in_context($sitelist);
        $this->assertEmpty($sitelist->get_userids());
    }

    /**
     * The export has the steps of the user in each course and nothing of anyone else.
     */
    public function test_export_user_data(): void {
        [$user1, $user2, $course1, $course2] = $this->build();
        flow_progress::record($course1->id, $user2->id, self::MAP, 'extra', 1, 'completed');
        $contexts = [context_course::instance($course1->id), context_course::instance($course2->id)];

        provider::export_user_data(new approved_contextlist($user1, 'format_rumbos', array_column($contexts, 'id')));

        foreach ($contexts as $context) {
            $data = writer::with_context($context)->get_data([get_string('privacy:path:flow', 'format_rumbos')]);
            $this->assertCount(2, $data->steps);
            $this->assertEqualsCanonicalizing(['video', 'quiz'], array_column($data->steps, 'step'));
            $this->assertSame(self::MAP, $data->steps[0]->map);
            foreach ($data->steps as $step) {
                $this->assertContains($step->method, ['completed', 'fallback']);
                $this->assertNotEmpty($step->timecompleted);
            }
        }
    }

    /**
     * The export covers only the contexts that were approved.
     */
    public function test_export_respects_the_approved_contexts(): void {
        [$user1, , $course1, $course2] = $this->build();

        provider::export_user_data(
            new approved_contextlist($user1, 'format_rumbos', [context_course::instance($course1->id)->id])
        );

        $this->assertTrue(writer::with_context(context_course::instance($course1->id))->has_any_data());
        $this->assertFalse(writer::with_context(context_course::instance($course2->id))->has_any_data());
    }

    /**
     * Everything in a course context goes, and nothing in another.
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;
        [, , $course1, $course2] = $this->build();

        provider::delete_data_for_all_users_in_context(context_course::instance($course1->id));

        $this->assertSame(0, $DB->count_records('format_rumbos_flow', ['courseid' => $course1->id]));
        $this->assertSame(4, $DB->count_records('format_rumbos_flow', ['courseid' => $course2->id]));

        provider::delete_data_for_all_users_in_context(\core\context\system::instance());
        $this->assertSame(4, $DB->count_records('format_rumbos_flow'));
    }

    /**
     * Deleting a user removes their steps in the approved contexts only.
     */
    public function test_delete_data_for_user(): void {
        global $DB;
        [$user1, $user2, $course1, $course2] = $this->build();

        provider::delete_data_for_user(
            new approved_contextlist($user1, 'format_rumbos', [context_course::instance($course1->id)->id])
        );

        $this->assertSame(0, $DB->count_records('format_rumbos_flow', ['userid' => $user1->id, 'courseid' => $course1->id]));
        $this->assertSame(2, $DB->count_records('format_rumbos_flow', ['userid' => $user1->id, 'courseid' => $course2->id]));
        $this->assertSame(4, $DB->count_records('format_rumbos_flow', ['userid' => $user2->id]));
    }

    /**
     * Deleting a list of users removes exactly those users in that course.
     */
    public function test_delete_data_for_users(): void {
        global $DB;
        [$user1, $user2, $course1, $course2] = $this->build();

        provider::delete_data_for_users(
            new approved_userlist(context_course::instance($course1->id), 'format_rumbos', [$user1->id])
        );

        $this->assertSame(0, $DB->count_records('format_rumbos_flow', ['userid' => $user1->id, 'courseid' => $course1->id]));
        $this->assertSame(2, $DB->count_records('format_rumbos_flow', ['userid' => $user1->id, 'courseid' => $course2->id]));
        $this->assertSame(4, $DB->count_records('format_rumbos_flow', ['userid' => $user2->id]));

        provider::delete_data_for_users(new approved_userlist(context_course::instance($course2->id), 'format_rumbos', []));
        $this->assertSame(6, $DB->count_records('format_rumbos_flow'));
    }

    /**
     * Deleting a course deletes the progress of everybody in it.
     */
    public function test_the_course_deleted_observer(): void {
        global $DB;
        [, , $course1, $course2] = $this->build();

        delete_course($course1, false);

        $this->assertSame(0, $DB->count_records('format_rumbos_flow', ['courseid' => $course1->id]));
        $this->assertSame(4, $DB->count_records('format_rumbos_flow', ['courseid' => $course2->id]));
    }

    /**
     * Deleting a user deletes their progress in every course.
     */
    public function test_the_user_deleted_observer(): void {
        global $DB;
        [$user1, $user2] = $this->build();

        delete_user($user1);

        $this->assertSame(0, $DB->count_records('format_rumbos_flow', ['userid' => $user1->id]));
        $this->assertSame(4, $DB->count_records('format_rumbos_flow', ['userid' => $user2->id]));
    }
}
