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

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\writer;

/**
 * Tests for the privacy provider.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\privacy\provider::class)]
final class provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * The external location lists exactly what is sent, and every field has a string.
     */
    public function test_metadata_declares_the_external_location(): void {
        $items = provider::get_metadata(new collection('format_rumbos'))->get_collection();

        $this->assertCount(1, $items);
        $location = $items[0];
        $this->assertInstanceOf(\core_privacy\local\metadata\types\external_location::class, $location);
        $this->assertSame('rumbos', $location->get_name());
        $this->assertEqualsCanonicalizing(
            ['userid', 'courseid', 'role', 'ipaddress'],
            array_keys($location->get_privacy_fields())
        );

        $strings = get_string_manager();
        $this->assertTrue($strings->string_exists($location->get_summary(), 'format_rumbos'));
        foreach ($location->get_privacy_fields() as $identifier) {
            $this->assertTrue($strings->string_exists($identifier, 'format_rumbos'), $identifier);
        }
    }

    /**
     * The declaration does not claim that the plugin sends anything it does not.
     */
    public function test_metadata_does_not_declare_direct_identifiers(): void {
        $location = provider::get_metadata(new collection('format_rumbos'))->get_collection()[0];

        foreach (['name', 'email', 'username', 'fullname'] as $field) {
            $this->assertArrayNotHasKey($field, $location->get_privacy_fields());
        }
    }

    /**
     * Nothing is stored in Moodle, so there is nothing to export or delete.
     */
    public function test_nothing_is_stored_locally(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course(['format' => 'rumbos']);
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');

        $this->assertEmpty(provider::get_contexts_for_userid($user->id)->get_contextids());

        $contextlist = new approved_contextlist($user, 'format_rumbos', []);
        provider::export_user_data($contextlist);
        $this->assertFalse(writer::with_context(\core\context\course::instance($course->id))->has_any_data());

        provider::delete_data_for_user($contextlist);
        provider::delete_data_for_all_users_in_context(\core\context\course::instance($course->id));
    }
}
