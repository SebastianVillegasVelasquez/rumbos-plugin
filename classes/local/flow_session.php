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
 * Whether the progress of the current user can be stored in a course.
 *
 * Progress is only stored for a real participant who is really themselves: not a guest, not somebody who
 * "logged in as" the user (the progress would be written in the user's name by another person), and not someone
 * who has nothing to do in the course. Everything else is read-only: the app is told so (persistent = false)
 * and keeps the flow to itself.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class flow_session {
    /** @var string The user is a guest or not logged in. */
    public const READONLY_GUEST = 'guest';

    /** @var string Somebody is logged in as this user. */
    public const READONLY_LOGGEDINAS = 'loggedinas';

    /** @var string The user takes no part in the course. */
    public const READONLY_NOT_PARTICIPANT = 'notparticipant';

    /**
     * Why the current user's progress cannot be stored, or null when it can.
     *
     * @param context_course $context
     * @return string|null One of the READONLY_ constants.
     */
    public static function readonly_reason(context_course $context): ?string {
        global $USER;

        if (empty($USER->id) || isguestuser()) {
            return self::READONLY_GUEST;
        }
        if (\core\session\manager::is_loggedinas()) {
            return self::READONLY_LOGGEDINAS;
        }
        if (!access::can_use($context)) {
            return self::READONLY_NOT_PARTICIPANT;
        }
        return null;
    }
}
