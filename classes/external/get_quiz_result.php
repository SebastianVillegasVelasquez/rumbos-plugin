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
use format_rumbos\local\quiz_result;

/**
 * External function returning the current user's own result in a quiz of the course.
 *
 * There is no user id parameter. What comes back is never more than Moodle would show the student (see
 * {@see quiz_result}); the host hands it to the student's own browser and to nobody else.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_quiz_result extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'cmid' => new external_value(PARAM_INT, 'Course module id of the quiz'),
        ]);
    }

    /**
     * Computes the result.
     *
     * @param int $courseid
     * @param int $cmid
     * @return array
     */
    public static function execute(int $courseid, int $cmid): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid, 'cmid' => $cmid]);
        $context = context_course::instance($params['courseid']);
        self::validate_context($context);

        $reply = quiz_result::for_user($params['courseid'], $params['cmid'], (int) $USER->id);
        return $reply['result'] === null ? ['error' => $reply['error']] : ['error' => '', 'result' => $reply['result']];
    }

    /**
     * Return structure: the QuizResult of the shared contract, or an error.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'error' => new external_value(PARAM_ALPHAEXT, 'Empty, "not_found" or "capability"'),
            'result' => new external_single_structure([
                'attempts' => new external_value(PARAM_INT, 'Attempts of the user, previews and unstarted ones not counted'),
                'finishedAttempts' => new external_value(PARAM_INT, 'Attempts in the finished state'),
                'inProgress' => new external_value(PARAM_BOOL, 'Whether an attempt is open'),
                'percent' => new external_value(
                    PARAM_FLOAT,
                    'Grade as a percentage, null when the student may not see it',
                    VALUE_REQUIRED,
                    null,
                    NULL_ALLOWED
                ),
                'pendingGrading' => new external_value(PARAM_BOOL, 'Whether a finished attempt still needs manual grading'),
                'passedThreshold' => new external_value(
                    PARAM_BOOL,
                    'Whether the percentage reaches the course threshold, null when it cannot be told',
                    VALUE_REQUIRED,
                    null,
                    NULL_ALLOWED
                ),
            ], 'The result; absent when there is an error', VALUE_OPTIONAL),
        ]);
    }
}
