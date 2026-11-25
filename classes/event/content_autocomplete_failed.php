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

namespace format_rumbos\event;

/**
 * Marking the course content complete failed, wholly or in part, for a student who passed.
 *
 * It is what an administrator finds in the logs; the student's result was applied regardless and a retry is queued.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class content_autocomplete_failed extends \core\event\base {
    /**
     * Initialises the event.
     */
    protected function init(): void {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Name of the event.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('eventcontentautocompletefailed', 'format_rumbos');
    }

    /**
     * Description for the logs.
     *
     * @return string
     */
    public function get_description(): string {
        return "Marking the content complete failed for the user with id '{$this->relateduserid}' after the verified result " .
            "'{$this->other['jti']}': {$this->other['message']}. A retry is queued.";
    }

    /**
     * Checks the data.
     */
    protected function validate_data(): void {
        parent::validate_data();
        if (empty($this->relateduserid)) {
            throw new \coding_exception('The relateduserid is required.');
        }
        foreach (['jti', 'cmids', 'message'] as $key) {
            if (!isset($this->other[$key])) {
                throw new \coding_exception("The other field '$key' is required.");
            }
        }
    }

    /**
     * Nothing in the other data is a Moodle id that restore could map.
     *
     * @return false
     */
    public static function get_other_mapping(): bool {
        return false;
    }
}
