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
 * An activity was marked complete for a student because a verified passing result said so.
 *
 * One per activity. The event's user is the account Moodle recorded as having overridden (the site administrator); the
 * related user is the student; `other` holds the id and purpose of the statement that is the reason, the state the
 * activity was in, and its module. This is the record the client can audit.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class content_autocompleted extends \core\event\base {
    /**
     * Initialises the event.
     */
    protected function init(): void {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'course_modules';
    }

    /**
     * Name of the event.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('eventcontentautocompleted', 'format_rumbos');
    }

    /**
     * Description for the logs.
     *
     * @return string
     */
    public function get_description(): string {
        return "Course module {$this->objectid} ({$this->other['modname']}) was marked complete for the user with id " .
            "'{$this->relateduserid}', recorded as an override by the user with id '{$this->userid}', because the verified " .
            "{$this->other['purpose']} result '{$this->other['jti']}' was a pass. It was in state {$this->other['previousstate']}.";
    }

    /**
     * Where the activity is.
     *
     * @return \moodle_url
     */
    public function get_url(): \moodle_url {
        return new \moodle_url("/mod/{$this->other['modname']}/view.php", ['id' => $this->objectid]);
    }

    /**
     * Checks the data.
     */
    protected function validate_data(): void {
        parent::validate_data();
        if (empty($this->relateduserid) || empty($this->objectid)) {
            throw new \coding_exception('The relateduserid and the objectid are required.');
        }
        foreach (['jti', 'purpose', 'previousstate', 'modname'] as $key) {
            if (!isset($this->other[$key])) {
                throw new \coding_exception("The other field '$key' is required.");
            }
        }
    }

    /**
     * The course module id is mapped on restore.
     *
     * @return array
     */
    public static function get_objectid_mapping(): array {
        return ['db' => 'course_modules', 'restore' => 'course_module'];
    }

    /**
     * The other data holds ids of the statement, which are not Moodle ids.
     *
     * @return false
     */
    public static function get_other_mapping(): bool {
        return false;
    }
}
