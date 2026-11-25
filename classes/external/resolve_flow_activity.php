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
use format_rumbos\local\flow_activities;

/**
 * External function the host calls to open an activity of a flow step in the overlay.
 *
 * It answers with the address to frame, or with the reason it cannot: the host keeps the address to itself and tells the
 * app only that the activity opened or why it did not. The address is built by Moodle from the course module, never
 * taken from the caller.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class resolve_flow_activity extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
        ]);
    }

    /**
     * Resolves the activity for the current user.
     *
     * @param int $courseid
     * @param int $cmid
     * @return array
     */
    public static function execute(int $courseid, int $cmid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid, 'cmid' => $cmid]);
        $context = context_course::instance($params['courseid']);
        self::validate_context($context);
        if (!access::can_use($context)) {
            return ['error' => flow_activities::FORBIDDEN];
        }

        $result = flow_activities::resolve($params['courseid'], $params['cmid']);
        return $result + ['url' => '', 'title' => '', 'modname' => '', 'error' => ''];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'url' => new external_value(PARAM_URL, 'Address to frame, empty when there is an error'),
            'title' => new external_value(PARAM_TEXT, 'Name of the activity as plain text'),
            'modname' => new external_value(PARAM_ALPHANUMEXT, 'Module name'),
            'error' => new external_value(PARAM_ALPHAEXT, 'not_found, forbidden or unsupported; empty when it can open'),
        ]);
    }
}
