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
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use format_rumbos\local\flow_progress;

/**
 * Privacy provider for the Rumbos course format.
 *
 * Moodle stores one kind of personal data for the plugin: which step of a welcome flow a user completed in a
 * course, with the revision, the method and the time (table format_rumbos_flow). It belongs to the course
 * context. It is not sent to the Rumbos service, which only receives what the launch assertion carries.
 *
 * What is sent to the external Rumbos service is declared too: a pseudonymous id, the course id and the role
 * (in the launch assertion), and the user's browser connects to that service directly to load the app.
 *
 * The completion statistics for teachers are aggregates (counts per activity, never a user id) held for
 * a few minutes in a Moodle application cache and handed to the browser. They are not personal data, are
 * not sent to the Rumbos service, and need no declaration. The same goes for the rate counters of the flow
 * functions, which hold a count per user and minute for a minute and no content.
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
     * Declares what Moodle stores and what is sent to the external Rumbos service.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(flow_progress::TABLE, [
            'courseid' => 'privacy:metadata:format_rumbos_flow:courseid',
            'userid' => 'privacy:metadata:format_rumbos_flow:userid',
            'mapid' => 'privacy:metadata:format_rumbos_flow:mapid',
            'stepkey' => 'privacy:metadata:format_rumbos_flow:stepkey',
            'revision' => 'privacy:metadata:format_rumbos_flow:revision',
            'method' => 'privacy:metadata:format_rumbos_flow:method',
            'timecompleted' => 'privacy:metadata:format_rumbos_flow:timecompleted',
        ], 'privacy:metadata:format_rumbos_flow');

        $collection->add_external_location_link('rumbos', [
            'userid' => 'privacy:metadata:rumbos:userid',
            'courseid' => 'privacy:metadata:rumbos:courseid',
            'role' => 'privacy:metadata:rumbos:role',
            'ipaddress' => 'privacy:metadata:rumbos:ipaddress',
        ], 'privacy:metadata:rumbos');

        return $collection;
    }

    /**
     * The course contexts in which the user completed a step.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $contextlist->add_from_sql(
            'SELECT ctx.id
               FROM {context} ctx
               JOIN {' . flow_progress::TABLE . '} f ON f.courseid = ctx.instanceid
              WHERE ctx.contextlevel = :contextlevel AND f.userid = :userid',
            ['contextlevel' => CONTEXT_COURSE, 'userid' => $userid]
        );
        return $contextlist;
    }

    /**
     * The users who completed a step in a course context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof context_course) {
            return;
        }
        $userlist->add_from_sql(
            'userid',
            'SELECT userid FROM {' . flow_progress::TABLE . '} WHERE courseid = :courseid',
            ['courseid' => $context->instanceid]
        );
    }

    /**
     * Exports the steps a user completed, one set per course.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_course) {
                continue;
            }
            $rows = $DB->get_records(
                flow_progress::TABLE,
                ['courseid' => $context->instanceid, 'userid' => $userid],
                'mapid ASC, timecompleted ASC, id ASC'
            );
            if (!$rows) {
                continue;
            }
            $steps = [];
            foreach ($rows as $row) {
                $steps[] = (object) [
                    'map' => $row->mapid,
                    'step' => $row->stepkey,
                    'revision' => (int) $row->revision,
                    'method' => $row->method,
                    'timecompleted' => transform::datetime((int) $row->timecompleted),
                ];
            }
            writer::with_context($context)->export_data(
                [get_string('privacy:path:flow', 'format_rumbos')],
                (object) ['steps' => $steps]
            );
        }
    }

    /**
     * Deletes the steps of every user in a course context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        if ($context instanceof context_course) {
            flow_progress::delete_for_course($context->instanceid);
        }
    }

    /**
     * Deletes the steps of some users in a course context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();
        if (!$context instanceof context_course || !$userlist->get_userids()) {
            return;
        }
        [$insql, $inparams] = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED);
        $DB->delete_records_select(
            flow_progress::TABLE,
            "courseid = :courseid AND userid $insql",
            ['courseid' => $context->instanceid] + $inparams
        );
    }

    /**
     * Deletes the steps of a user in the course contexts that were approved.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof context_course) {
                flow_progress::delete_for_user($userid, $context->instanceid);
            }
        }
    }
}
