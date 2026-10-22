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
use core\context\module as context_module;
use moodle_url;

/**
 * Where an editor goes to configure the completion of an activity.
 *
 * The map editor cannot change completion itself; it hands the editor over to Moodle's own
 * activity settings page, in the same way Moodle's own completion dialog does.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activity_settings {
    /**
     * The settings page of an activity with only its "Activity completion" section expanded.
     *
     * It is the address core_courseformat's completion dialog links to ("Edit conditions").
     *
     * @param int $cmid Course module id.
     * @return moodle_url
     */
    public static function completion_url(int $cmid): moodle_url {
        return new moodle_url('/course/modedit.php', ['update' => $cmid, 'showonly' => 'activitycompletionheader']);
    }

    /**
     * Whether the current user may open the settings of an activity from the Rumbos map editor.
     *
     * They must be a map editor of the course (the same capability that makes the page run in
     * editor mode) and be allowed to manage activities in the activity itself, which is what
     * Moodle's settings page checks.
     *
     * @param context_course $course
     * @param context_module $module
     * @return bool
     */
    public static function can_open(context_course $course, context_module $module): bool {
        return has_capability('format/rumbos:editmap', $course) && has_capability('moodle/course:manageactivities', $module);
    }
}
