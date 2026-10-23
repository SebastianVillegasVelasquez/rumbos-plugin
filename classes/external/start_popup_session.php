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

use core\context\module as context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use format_rumbos\local\popup_session;

/**
 * External function the host calls right before it loads the activity popup.
 *
 * It stores a short-lived marker in the user's session, bound to the course of an activity the user
 * can see. The marker alone changes nothing; see {@see popup_session} and docs/DESIGN-popup-full-bleed.md.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class start_popup_session extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id of the activity the popup opens'),
        ]);
    }

    /**
     * Marks the session as having a popup open for the course of the activity.
     *
     * @param int $cmid
     * @return array
     */
    public static function execute(int $cmid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);
        [$course, $cm] = get_course_and_cm_from_cmid($params['cmid']);
        self::validate_context(context_module::instance($cm->id));
        if ($course->format !== 'rumbos' || !$cm->uservisible || $cm->deletioninprogress) {
            throw new \moodle_exception('invalidcoursemodule');
        }
        popup_session::start((int) $course->id);
        return ['expires' => popup_session::get()['expires']];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'expires' => new external_value(PARAM_INT, 'Time the marker expires unless a popup page extends it'),
        ]);
    }
}
