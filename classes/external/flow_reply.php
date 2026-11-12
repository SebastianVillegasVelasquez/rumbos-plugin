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
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use format_rumbos\local\flow_config;
use format_rumbos\local\flow_progress;
use format_rumbos\local\flow_session;

/**
 * What the three flow functions answer with: the current user's own progress on a map, whether it can be
 * stored, and the course's settings for the flow.
 *
 * It never holds data about another user: every function that returns it reads the rows of the current user.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class flow_reply {
    /** @var string Nothing went wrong. */
    public const ERROR_NONE = '';

    /** @var string The progress cannot be stored for this user. */
    public const ERROR_READONLY = 'readonly';

    /** @var string The request could not be carried out now. */
    public const ERROR_UNAVAILABLE = 'unavailable';

    /**
     * The parameters every flow function starts with: the course and the map.
     *
     * @return external_value[]
     */
    public static function base_parameters(): array {
        return [
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'mapid' => new external_value(PARAM_RAW, 'Rumbos map id (a UUID)'),
        ];
    }

    /**
     * Checks a map id and normalises it.
     *
     * @param string $mapid
     * @return string
     * @throws \invalid_parameter_exception
     */
    public static function clean_mapid(string $mapid): string {
        if (!flow_progress::is_mapid($mapid)) {
            throw new \invalid_parameter_exception('Invalid map id');
        }
        return strtolower($mapid);
    }

    /**
     * The structure of the answer.
     *
     * @return external_single_structure
     */
    public static function structure(): external_single_structure {
        return new external_single_structure([
            'error' => new external_value(PARAM_ALPHA, 'Empty, "readonly" or "unavailable"'),
            'outcome' => new external_value(PARAM_ALPHA, 'What the call did, for diagnosis'),
            'persistent' => new external_value(PARAM_BOOL, 'Whether the progress of this user can be stored'),
            'config' => new external_single_structure([
                'quizThreshold' => new external_value(PARAM_INT, 'Entry quiz threshold in percent, 0 for none'),
            ]),
            'items' => new external_multiple_structure(new external_single_structure([
                'stepKey' => new external_value(PARAM_ALPHANUMEXT, 'Step key'),
                'revision' => new external_value(PARAM_INT, 'Revision of the step that was completed'),
                'method' => new external_value(PARAM_ALPHA, 'completed, fallback or unavailable'),
                'completedAt' => new external_value(PARAM_RAW, 'ISO 8601 time of completion in UTC'),
            ])),
        ]);
    }

    /**
     * Builds the answer for the current user.
     *
     * @param context_course $context
     * @param string $mapid A checked map id.
     * @param string $outcome What the call did.
     * @param string $error One of the ERROR_ constants.
     * @return array
     */
    public static function build(context_course $context, string $mapid, string $outcome, string $error = self::ERROR_NONE): array {
        global $USER;

        $courseid = (int) $context->instanceid;
        return [
            'error' => $error,
            'outcome' => $outcome,
            'persistent' => flow_session::readonly_reason($context) === null,
            'config' => ['quizThreshold' => flow_config::get_quiz_threshold($courseid)],
            'items' => flow_progress::get_items($courseid, (int) $USER->id, $mapid),
        ];
    }

    /**
     * The parameters object of a function that takes only the course and the map.
     *
     * @return external_function_parameters
     */
    public static function base_function_parameters(): external_function_parameters {
        return new external_function_parameters(self::base_parameters());
    }
}
