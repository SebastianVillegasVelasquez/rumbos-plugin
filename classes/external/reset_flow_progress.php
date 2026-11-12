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
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use format_rumbos\local\flow_progress;
use format_rumbos\local\flow_rate_limit;
use format_rumbos\local\flow_session;

/**
 * External function resetting the welcome-flow progress on a map, for testing the flow.
 *
 * User id 0 means the caller and needs format/rumbos:editmap. Another user needs format/rumbos:resetflow. The
 * answer is always the caller's own progress, so resetting somebody else tells the caller nothing about them.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reset_flow_progress extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(flow_reply::base_parameters() + [
            'userid' => new external_value(PARAM_INT, 'User whose progress is reset, 0 for the current user', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Resets the progress.
     *
     * @param int $courseid
     * @param string $mapid
     * @param int $userid
     * @return array
     */
    public static function execute(int $courseid, string $mapid, int $userid = 0): array {
        global $USER;

        $params = self::validate_parameters(
            self::execute_parameters(),
            ['courseid' => $courseid, 'mapid' => $mapid, 'userid' => $userid]
        );
        $mapid = flow_reply::clean_mapid($params['mapid']);
        $context = context_course::instance($params['courseid']);
        self::validate_context($context);

        $target = $params['userid'] === 0 ? (int) $USER->id : $params['userid'];
        if ($target === (int) $USER->id) {
            require_capability('format/rumbos:editmap', $context);
        } else {
            require_capability('format/rumbos:resetflow', $context);
            // A real, active account only: no guest, no deleted user, no id made up.
            $user = \core_user::get_user($target, 'id, deleted', MUST_EXIST);
            if ($user->deleted || isguestuser($target)) {
                throw new \invalid_parameter_exception('Invalid user');
            }
        }

        if (flow_session::readonly_reason($context) !== null) {
            return flow_reply::build($context, $mapid, 'readonly', flow_reply::ERROR_READONLY);
        }
        if (!flow_rate_limit::allow((int) $USER->id)) {
            return flow_reply::build($context, $mapid, 'ratelimited', flow_reply::ERROR_UNAVAILABLE);
        }

        flow_progress::reset_map((int) $params['courseid'], $target, $mapid);
        return flow_reply::build($context, $mapid, 'reset');
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return flow_reply::structure();
    }
}
