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
use format_rumbos\local\course_stats;

/**
 * External function returning the completion counts of a course or one of its groups.
 *
 * Aggregates only: there is no user id parameter and no user data in the answer. The viewer is always the
 * current user, who needs format/rumbos:viewstats in the course and gets only the groups they may see.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_course_stats extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'groupid' => new external_value(PARAM_INT, 'Group id, 0 for everyone the user may see', VALUE_DEFAULT, 0),
            'refresh' => new external_value(
                PARAM_BOOL,
                'Recompute instead of using the cache; ignored when the cached result is less than 30 seconds old',
                VALUE_DEFAULT,
                false
            ),
        ]);
    }

    /**
     * Computes the statistics.
     *
     * @param int $courseid
     * @param int $groupid
     * @param bool $refresh
     * @return array
     */
    public static function execute(int $courseid, int $groupid = 0, bool $refresh = false): array {
        global $USER;

        $params = self::validate_parameters(
            self::execute_parameters(),
            ['courseid' => $courseid, 'groupid' => $groupid, 'refresh' => $refresh]
        );
        $context = context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('format/rumbos:viewstats', $context);

        return course_stats::for_user($params['courseid'], (int) $USER->id, $params['groupid'], $params['refresh']);
    }

    /**
     * Return structure: the CourseStats of the shared contract.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'schemaVersion' => new external_value(PARAM_INT, 'Contract schema version'),
            'generatedAt' => new external_value(PARAM_RAW, 'ISO 8601 time the counts were computed, in UTC'),
            'courseId' => new external_value(PARAM_INT, 'Course id'),
            'groupId' => new external_value(PARAM_INT, 'Group the counts are for, 0 for everyone the user may see'),
            'groups' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Group id'),
                'name' => new external_value(PARAM_RAW, 'Plain-text group name'),
            ]), 'The groups the user may see'),
            'tracked' => new external_value(PARAM_INT, 'Tracked students in scope, 0 when suppressed'),
            'minCohort' => new external_value(PARAM_INT, 'Fewest tracked students for which counts are given'),
            'suppressed' => new external_value(PARAM_BOOL, 'Whether there are too few students to give counts'),
            'activities' => new external_multiple_structure(new external_single_structure([
                'cmid' => new external_value(PARAM_INT, 'Course module id'),
                'completionTracked' => new external_value(PARAM_BOOL, 'Whether the activity has a completion condition'),
                'complete' => new external_value(PARAM_INT, 'Students who completed it, passing or not'),
                'failed' => new external_value(PARAM_INT, 'Students who completed it with a fail grade'),
            ]), 'Counts per activity; absent when suppressed', VALUE_OPTIONAL),
        ]);
    }
}
