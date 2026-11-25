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

namespace format_rumbos\task;

use format_rumbos\local\content_autocomplete;

/**
 * Retries marking the course content complete after a failure.
 *
 * It names a statement, not a decision: {@see content_autocomplete::run()} reads the verified result of that statement again
 * and does nothing unless it is a pass, so a task can never complete more than its statement justifies. When something
 * still fails the task throws, and Moodle repeats it later with a growing delay.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class autocomplete_content extends \core\task\adhoc_task {
    /**
     * Name of the task.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskautocompletecontent', 'format_rumbos');
    }

    /**
     * Runs the retry.
     *
     * @throws \moodle_exception When an activity could not be completed, so that Moodle retries.
     */
    public function execute(): void {
        $data = $this->get_custom_data();
        $summary = content_autocomplete::run((int) $data->courseid, (int) $data->userid, (string) $data->jti);
        if ($summary['outcome'] === content_autocomplete::PARTIAL) {
            throw new \moodle_exception('autocompleteretry', 'format_rumbos');
        }
    }
}
