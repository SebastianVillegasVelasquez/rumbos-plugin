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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use format_rumbos\local\access;
use format_rumbos\local\course_state;

/**
 * External function returning the CourseState of the current user.
 *
 * There is deliberately no user id parameter: the state is always the
 * caller's own.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_course_state extends external_api {
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
     * Computes the current user's course state.
     *
     * @param int $courseid
     * @return array
     */
    public static function execute(int $courseid): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);
        $context = context_course::instance($params['courseid']);
        self::validate_context($context);
        if (!access::can_use($context)) {
            throw new \required_capability_exception($context, 'format/rumbos:editmap', 'nopermissions', '');
        }

        return course_state::for_user($params['courseid'], (int) $USER->id);
    }

    /**
     * Return structure: the CourseState of the shared contract.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'schemaVersion' => new external_value(PARAM_INT, 'Contract schema version'),
            'courseId' => new external_value(PARAM_INT, 'Course id'),
            'generatedAt' => new external_value(PARAM_INT, 'Unix timestamp of generation'),
            'sections' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Section id'),
                'number' => new external_value(PARAM_INT, 'Section number'),
                'name' => new external_value(PARAM_RAW, 'Plain-text section name'),
            ])),
            'activities' => new external_multiple_structure(new external_single_structure([
                'cmid' => new external_value(PARAM_INT, 'Course module id'),
                'name' => new external_value(PARAM_RAW, 'Plain-text activity name'),
                'modname' => new external_value(PARAM_PLUGIN, 'Module type'),
                'sectionId' => new external_value(PARAM_INT, 'Section id'),
                'url' => new external_value(
                    PARAM_URL,
                    'View URL, null when the user cannot open it',
                    VALUE_REQUIRED,
                    null,
                    NULL_ALLOWED
                ),
                'availability' => new external_value(PARAM_ALPHA, 'available, restricted or hidden'),
                'availabilityInfo' => new external_value(
                    PARAM_RAW,
                    'Plain-text restriction info',
                    VALUE_REQUIRED,
                    null,
                    NULL_ALLOWED
                ),
                'completion' => new external_value(PARAM_ALPHA, 'none, incomplete, complete or failed'),
            ])),
            'unavailableCmids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Course module id the user cannot see')
            ),
        ]);
    }
}
