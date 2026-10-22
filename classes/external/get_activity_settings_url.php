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
use core\context\module as context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use format_rumbos\local\access;
use format_rumbos\local\activity_settings;

/**
 * External function returning where to configure the completion of an activity.
 *
 * Only map editors who may manage the activity get an answer. The address is built by Moodle,
 * never taken from the caller, and it always points at this site.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_activity_settings_url extends external_api {
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
     * Returns the settings address of an activity of the course.
     *
     * @param int $courseid
     * @param int $cmid
     * @return array
     */
    public static function execute(int $courseid, int $cmid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid, 'cmid' => $cmid]);
        $coursecontext = context_course::instance($params['courseid']);
        self::validate_context($coursecontext);
        if (!access::can_use($coursecontext)) {
            throw new \required_capability_exception($coursecontext, 'format/rumbos:editmap', 'nopermissions', '');
        }

        // The activity must be one of this course's (get_cm throws for anything else).
        $cm = get_fast_modinfo($params['courseid'])->get_cm($params['cmid']);
        if ($cm->deletioninprogress) {
            throw new \moodle_exception('invalidcoursemodule');
        }
        $modulecontext = context_module::instance($cm->id);
        if (!activity_settings::can_open($coursecontext, $modulecontext)) {
            throw new \required_capability_exception($modulecontext, 'moodle/course:manageactivities', 'nopermissions', '');
        }

        return ['url' => activity_settings::completion_url($cm->id)->out(false)];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'url' => new external_value(PARAM_URL, 'Settings page of the activity, on this site'),
        ]);
    }
}
