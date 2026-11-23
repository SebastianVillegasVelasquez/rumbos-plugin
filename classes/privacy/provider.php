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
use format_rumbos\local\result_store;

/**
 * Privacy provider for the Rumbos course format.
 *
 * Moodle stores three kinds of personal data for the plugin, all of them in the course context: which step of a
 * welcome flow a user completed (table format_rumbos_flow); the results of check-in and final exams that the Rumbos
 * service reported (table format_rumbos_result, with the grade and the course completion those cause, which Moodle's
 * own privacy providers cover); and the audit of every result statement received (table format_rumbos_audit, which
 * holds the pseudonymous reference and the score, never a name, an email or a network address). None of this is sent
 * to the Rumbos service, which only receives what the launch assertion carries.
 *
 * This declaration is a DRAFT that NEEDS LEGAL REVIEW: the retention of the results and of the audit is not decided,
 * and no channel exists to forward an erasure request to the Rumbos service.
 *
 * What is sent to the external Rumbos service is declared too: a pseudonymous id, the course id and the role
 * (in the launch assertion), and the user's browser connects to that service directly to load the app. What the
 * service keeps about what the user does in the app is declared in the same location.
 *
 * The completion statistics for teachers are aggregates (counts per activity, never a user id) held for
 * a few minutes in a Moodle application cache and handed to the browser. They are not personal data, are
 * not sent to the Rumbos service, and need no declaration. The same goes for the rate counters of the flow
 * functions and of the result receiver, which hold a count per minute and no content.
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

        $collection->add_database_table(result_store::RESULTS, [
            'courseid' => 'privacy:metadata:format_rumbos_result:courseid',
            'userid' => 'privacy:metadata:format_rumbos_result:userid',
            'purpose' => 'privacy:metadata:format_rumbos_result:purpose',
            'attemptid' => 'privacy:metadata:format_rumbos_result:attemptid',
            'jti' => 'privacy:metadata:format_rumbos_result:jti',
            'percent' => 'privacy:metadata:format_rumbos_result:percent',
            'passed' => 'privacy:metadata:format_rumbos_result:passed',
            'timefinished' => 'privacy:metadata:format_rumbos_result:timefinished',
            'timeapplied' => 'privacy:metadata:format_rumbos_result:timeapplied',
        ], 'privacy:metadata:format_rumbos_result');

        $collection->add_database_table(result_store::AUDIT, [
            'timecreated' => 'privacy:metadata:format_rumbos_audit:timecreated',
            'status' => 'privacy:metadata:format_rumbos_audit:status',
            'reason' => 'privacy:metadata:format_rumbos_audit:reason',
            'jti' => 'privacy:metadata:format_rumbos_audit:jti',
            'attemptid' => 'privacy:metadata:format_rumbos_audit:attemptid',
            'subject' => 'privacy:metadata:format_rumbos_audit:subject',
            'courseid' => 'privacy:metadata:format_rumbos_audit:courseid',
            'userid' => 'privacy:metadata:format_rumbos_audit:userid',
            'purpose' => 'privacy:metadata:format_rumbos_audit:purpose',
            'percent' => 'privacy:metadata:format_rumbos_audit:percent',
            'passed' => 'privacy:metadata:format_rumbos_audit:passed',
            'policy' => 'privacy:metadata:format_rumbos_audit:policy',
        ], 'privacy:metadata:format_rumbos_audit');

        $collection->add_external_location_link('rumbos', [
            'userid' => 'privacy:metadata:rumbos:userid',
            'uid' => 'privacy:metadata:rumbos:uid',
            'courseid' => 'privacy:metadata:rumbos:courseid',
            'role' => 'privacy:metadata:rumbos:role',
            'ipaddress' => 'privacy:metadata:rumbos:ipaddress',
            'practice' => 'privacy:metadata:rumbos:practice',
            'assessment' => 'privacy:metadata:rumbos:assessment',
            'progress' => 'privacy:metadata:rumbos:progress',
        ], 'privacy:metadata:rumbos');

        return $collection;
    }

    /**
     * The course contexts in which the user has a step, a result or an audit row.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $contextlist->add_from_sql(
            'SELECT ctx.id
               FROM {context} ctx
              WHERE ctx.contextlevel = :contextlevel
                AND ctx.instanceid IN (
                    SELECT courseid FROM {' . flow_progress::TABLE . '} WHERE userid = :flowuser
                     UNION
                    SELECT courseid FROM {' . result_store::RESULTS . '} WHERE userid = :resultuser
                     UNION
                    SELECT courseid FROM {' . result_store::AUDIT . '} WHERE userid = :audituser
                )',
            ['contextlevel' => CONTEXT_COURSE, 'flowuser' => $userid, 'resultuser' => $userid, 'audituser' => $userid]
        );
        return $contextlist;
    }

    /**
     * The users who have a step, a result or an audit row in a course context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof context_course) {
            return;
        }
        $params = ['courseid' => $context->instanceid];
        $userlist->add_from_sql(
            'userid',
            'SELECT userid FROM {' . flow_progress::TABLE . '} WHERE courseid = :courseid',
            $params
        );
        $userlist->add_from_sql(
            'userid',
            'SELECT userid FROM {' . result_store::RESULTS . '} WHERE courseid = :courseid',
            $params
        );
        $userlist->add_from_sql(
            'userid',
            'SELECT userid FROM {' . result_store::AUDIT . '} WHERE courseid = :courseid AND userid > 0',
            $params
        );
    }

    /**
     * Exports the steps, the results and the audit rows of a user, one set per course.
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
            $where = ['courseid' => $context->instanceid, 'userid' => $userid];

            $rows = $DB->get_records(flow_progress::TABLE, $where, 'mapid ASC, timecompleted ASC, id ASC');
            if ($rows) {
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

            $rows = $DB->get_records(result_store::RESULTS, $where, 'timefinished ASC, id ASC');
            if ($rows) {
                $results = [];
                foreach ($rows as $row) {
                    $results[] = (object) [
                        'purpose' => $row->purpose,
                        'attempt' => $row->attemptid,
                        'percent' => $row->percent,
                        'passed' => transform::yesno((bool) $row->passed),
                        'timefinished' => transform::datetime((int) $row->timefinished),
                        'timeapplied' => transform::datetime((int) $row->timeapplied),
                    ];
                }
                writer::with_context($context)->export_data(
                    [get_string('privacy:path:results', 'format_rumbos')],
                    (object) ['results' => $results]
                );
            }

            $rows = $DB->get_records(result_store::AUDIT, $where, 'timecreated ASC, id ASC');
            if ($rows) {
                $statements = [];
                foreach ($rows as $row) {
                    $statements[] = (object) [
                        'received' => transform::datetime((int) $row->timecreated),
                        'status' => (int) $row->status,
                        'reason' => $row->reason,
                        'purpose' => $row->purpose,
                        'attempt' => $row->attemptid,
                        'percent' => $row->percent,
                        'passed' => $row->passed === null ? null : transform::yesno((bool) $row->passed),
                        'policy' => $row->policy,
                    ];
                }
                writer::with_context($context)->export_data(
                    [get_string('privacy:path:statements', 'format_rumbos')],
                    (object) ['statements' => $statements]
                );
            }
        }
    }

    /**
     * Deletes the steps, the results and the audit rows of every user in a course context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        if ($context instanceof context_course) {
            flow_progress::delete_for_course($context->instanceid);
            result_store::erase_course($context->instanceid);
        }
    }

    /**
     * Deletes the steps and results of some users in a course context, and makes their audit rows anonymous.
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
        foreach ($userlist->get_userids() as $userid) {
            result_store::erase_user((int) $userid, $context->instanceid);
        }
    }

    /**
     * Deletes the steps and results of a user in the course contexts that were approved, and makes their audit rows
     * anonymous.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof context_course) {
                flow_progress::delete_for_user($userid, $context->instanceid);
                result_store::erase_user($userid, $context->instanceid);
            }
        }
    }
}
