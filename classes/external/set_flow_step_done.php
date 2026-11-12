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
 * External function recording that the current user completed a step of a welcome flow.
 *
 * The user is always the caller. What the client reports about its own progress is a convenience for the app,
 * never evidence for anything else. Every value is checked here again; when the progress cannot be stored (a
 * guest, "log in as", a user outside the course) the answer says "readonly" instead of throwing.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class set_flow_step_done extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(flow_reply::base_parameters() + [
            'stepkey' => new external_value(PARAM_RAW, 'Step key: letters, digits, "_" and "-", at most 16'),
            'revision' => new external_value(PARAM_INT, 'Revision of the step that was completed'),
            'method' => new external_value(PARAM_RAW, 'completed, fallback or unavailable'),
        ]);
    }

    /**
     * Records the step.
     *
     * @param int $courseid
     * @param string $mapid
     * @param string $stepkey
     * @param int $revision
     * @param string $method
     * @return array
     */
    public static function execute(int $courseid, string $mapid, string $stepkey, int $revision, string $method): array {
        global $USER;

        $params = self::validate_parameters(
            self::execute_parameters(),
            ['courseid' => $courseid, 'mapid' => $mapid, 'stepkey' => $stepkey, 'revision' => $revision, 'method' => $method]
        );
        $mapid = flow_reply::clean_mapid($params['mapid']);
        if (!flow_progress::is_stepkey($params['stepkey'])) {
            throw new \invalid_parameter_exception('Invalid step key');
        }
        if (!flow_progress::is_revision($params['revision'])) {
            throw new \invalid_parameter_exception('Invalid revision');
        }
        if (!flow_progress::is_method($params['method'])) {
            throw new \invalid_parameter_exception('Invalid method');
        }
        $context = context_course::instance($params['courseid']);
        self::validate_context($context);

        if (flow_session::readonly_reason($context) !== null) {
            return flow_reply::build($context, $mapid, 'readonly', flow_reply::ERROR_READONLY);
        }
        if (!flow_rate_limit::allow((int) $USER->id)) {
            return flow_reply::build($context, $mapid, 'ratelimited', flow_reply::ERROR_UNAVAILABLE);
        }

        $result = flow_progress::record(
            (int) $params['courseid'],
            (int) $USER->id,
            $mapid,
            $params['stepkey'],
            $params['revision'],
            $params['method']
        );
        $error = $result === flow_progress::RESULT_CAPPED ? flow_reply::ERROR_UNAVAILABLE : flow_reply::ERROR_NONE;
        return flow_reply::build($context, $mapid, $result, $error);
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
