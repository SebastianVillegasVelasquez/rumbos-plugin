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
     * actively enrolled, allowed to edit the map, or a user who switched to
     * another role to see the course as that role sees it. Guests never qualify.
     *
     * The last case matters because a manager who is not enrolled and switches to
     * "Student" holds neither enrolment nor the edit capability any more, yet is
     * looking at the course page and must get exactly what a student gets.
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
            || has_capability('format/rumbos:editmap', $context, $userid)
            || ($userid === (int) $USER->id && is_role_switched($context->instanceid));
    }

    /**
     * The mode the page tells the app to run in (host-init "mode").
     *
     * The map editor is for users who may edit the map AND have Moodle's editing
     * mode switched on ("Turn editing on"); with editing off a teacher sees the
     * map exactly as a student does. The role claim of the launch assertion is
     * capability based and does not depend on this.
     *
     * @param context_course $context
     * @param bool $editing Whether the page is in editing mode ($PAGE->user_is_editing()).
     * @return string "editor" or "student"
     */
    public static function map_mode(context_course $context, bool $editing): string {
        return $editing && has_capability('format/rumbos:editmap', $context) ? 'editor' : 'student';
    }

    /**
     * Whether to remind the user to switch editing on: they may edit the map but editing is off.
     *
     * @param context_course $context
     * @param bool $editing Whether the page is in editing mode.
     * @return bool
     */
    public static function should_suggest_editing(context_course $context, bool $editing): bool {
        return !$editing && has_capability('format/rumbos:editmap', $context);
    }
}
