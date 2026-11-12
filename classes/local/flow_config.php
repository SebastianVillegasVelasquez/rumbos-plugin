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

namespace format_rumbos\local;

/**
 * The course settings of the welcome flow.
 *
 * Only the threshold of the entry quiz so far: the percentage of the quiz grade at or above which the student
 * counts as having passed. Zero means the flow does not branch on the result. The option is part of the course
 * format options, so only someone who may update the course can change it, and it holds no ids, so a backup
 * carries it to the restored course as it is.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class flow_config {
    /** @var string Name of the course option. */
    public const OPTION_QUIZ_THRESHOLD = 'rumbos_quizthreshold';

    /** @var int Default threshold, in percent. */
    public const DEFAULT_QUIZ_THRESHOLD = 80;

    /** @var int[] Valid thresholds: minimum and maximum. */
    public const QUIZ_THRESHOLD_RANGE = [0, 100];

    /**
     * The threshold of a course, in percent.
     *
     * A stored value that is not a valid threshold (the options can also be written through the API) counts as
     * the default, so a bad value never turns the branching off or makes it impossible to pass.
     *
     * @param int $courseid
     * @return int 0 to 100; 0 means no branching.
     */
    public static function get_quiz_threshold(int $courseid): int {
        $options = course_get_format($courseid)->get_format_options();
        $value = $options[self::OPTION_QUIZ_THRESHOLD] ?? self::DEFAULT_QUIZ_THRESHOLD;
        return map_size::is_int_in($value, self::QUIZ_THRESHOLD_RANGE) ? (int) $value : self::DEFAULT_QUIZ_THRESHOLD;
    }

    /**
     * Checks the threshold of the course form.
     *
     * @param array $data The submitted form data.
     * @return array Error message keyed by element name.
     */
    public static function validate_course_data(array $data): array {
        $value = $data[self::OPTION_QUIZ_THRESHOLD] ?? self::DEFAULT_QUIZ_THRESHOLD;
        if (!map_size::is_int_in($value, self::QUIZ_THRESHOLD_RANGE)) {
            return [self::OPTION_QUIZ_THRESHOLD => get_string(
                'errorquizthreshold',
                'format_rumbos',
                (object) ['min' => self::QUIZ_THRESHOLD_RANGE[0], 'max' => self::QUIZ_THRESHOLD_RANGE[1]]
            )];
        }
        return [];
    }
}
