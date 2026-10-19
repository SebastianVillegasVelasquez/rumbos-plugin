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

use core\context\course as context_course;

/**
 * Who may use Rumbos in a course.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class access {
    /**
     * Whether a user is a real participant of the course for Rumbos purposes:
     * actively enrolled, or allowed to edit the map. Guests never qualify.
     *
     * @param context_course $context
     * @param int|null $userid Defaults to the current user.
     * @return bool
     */
    public static function can_use(context_course $context, ?int $userid = null): bool {
        global $USER;

        $userid = $userid ?? (int) $USER->id;
        if ($userid <= 0 || isguestuser($userid)) {
            return false;
        }
        return is_enrolled($context, $userid, '', true)
            || has_capability('format/rumbos:editmap', $context, $userid);
    }
}
