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
use format_rumbos\local\access;
use format_rumbos\local\config;
use format_rumbos\local\launch_assertion;

/**
 * External function issuing a short-lived launch assertion for the current user.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_launch_assertion extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
        ]);
    }

    /**
     * Signs an assertion for the current user in the course.
     *
     * Only for courses that use the Rumbos format, while Rumbos is enabled
     * and an app URL is configured, and only for participants of the course.
     *
     * @param int $courseid
     * @return array
     */
    public static function execute(int $courseid): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);
        $context = context_course::instance($params['courseid']);
        self::validate_context($context);

        if (!config::is_usable()) {
            throw new \moodle_exception('errornotenabled', 'format_rumbos');
        }
        if (course_get_format($params['courseid'])->get_format() !== 'rumbos') {
            throw new \moodle_exception('errornotrumbos', 'format_rumbos');
        }
        if (!access::can_use($context)) {
            throw new \required_capability_exception($context, 'format/rumbos:editmap', 'nopermissions', '');
        }

        $assertion = launch_assertion::create($params['courseid'], (int) $USER->id);

        return ['assertion' => $assertion['token'], 'expiresAt' => $assertion['expiresat']];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'assertion' => new external_value(PARAM_RAW, 'Signed launch assertion (JWS)'),
            'expiresAt' => new external_value(PARAM_INT, 'Unix timestamp when the assertion expires'),
        ]);
    }
}
