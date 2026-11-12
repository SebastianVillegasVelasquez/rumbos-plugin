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

/**
 * External function returning the welcome-flow progress of the current user on a map.
 *
 * There is no user id parameter: the rows are always the caller's own. A guest, someone logged in as another
 * user, or a user who takes no part in the course gets whatever they have and persistent = false.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_flow_progress extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return flow_reply::base_function_parameters();
    }

    /**
     * Reads the progress.
     *
     * @param int $courseid
     * @param string $mapid
     * @return array
     */
    public static function execute(int $courseid, string $mapid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid, 'mapid' => $mapid]);
        $mapid = flow_reply::clean_mapid($params['mapid']);
        $context = context_course::instance($params['courseid']);
        self::validate_context($context);

        return flow_reply::build($context, $mapid, 'read');
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
