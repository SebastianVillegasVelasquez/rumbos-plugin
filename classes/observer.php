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

namespace format_rumbos;

use core\event\course_deleted;
use core\event\user_deleted;
use format_rumbos\local\flow_progress;

/**
 * Event observers: the flow progress goes when its course or its user goes.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * A course was deleted: nobody's progress in it is needed any more.
     *
     * @param course_deleted $event
     */
    public static function course_deleted(course_deleted $event): void {
        flow_progress::delete_for_course((int) $event->objectid);
    }

    /**
     * A user was deleted: their progress in every course goes with them.
     *
     * @param user_deleted $event
     */
    public static function user_deleted(user_deleted $event): void {
        flow_progress::delete_for_user((int) $event->objectid);
    }
}
