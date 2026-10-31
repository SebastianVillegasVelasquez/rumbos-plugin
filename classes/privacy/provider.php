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
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;

/**
 * Privacy provider for the Rumbos course format.
 *
 * The plugin stores no personal data in Moodle's database. It does send a
 * pseudonymous id, the course id and the role to the external Rumbos service
 * (in the launch assertion), and the user's browser connects to that service
 * directly to load the embedded app. Only that disclosure is declared here:
 * there is nothing to export or delete locally.
 *
 * The completion statistics for teachers are aggregates (counts per activity, never a user id) held for
 * a few minutes in a Moodle application cache and handed to the browser. They are not personal data, are
 * not sent to the Rumbos service, and need no declaration.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Declares what is sent to the external Rumbos service.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_external_location_link('rumbos', [
            'userid' => 'privacy:metadata:rumbos:userid',
            'courseid' => 'privacy:metadata:rumbos:courseid',
            'role' => 'privacy:metadata:rumbos:role',
            'ipaddress' => 'privacy:metadata:rumbos:ipaddress',
        ], 'privacy:metadata:rumbos');

        return $collection;
    }

    /**
     * Nothing is stored in Moodle for a user, so there are no contexts.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        return new contextlist();
    }

    /**
     * Nothing is stored in Moodle for any user.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
    }

    /**
     * Nothing is stored in Moodle, so nothing is exported.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
    }

    /**
     * Nothing is stored in Moodle, so nothing is deleted.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
    }

    /**
     * Nothing is stored in Moodle, so nothing is deleted.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
    }

    /**
     * Nothing is stored in Moodle, so nothing is deleted.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
    }
}
