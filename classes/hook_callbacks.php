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

use core\hook\output\before_standard_head_html_generation;
use format_rumbos\local\config;
use format_rumbos\local\popup;

/**
 * Hook callbacks of the Rumbos format.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * Loads the popup layout script on pages framed by the activity popup.
     *
     * Only same-origin iframe requests for a page of a Rumbos course get the script, so
     * every other page is served exactly as before.
     *
     * @param before_standard_head_html_generation $hook
     */
    public static function before_standard_head_html_generation(before_standard_head_html_generation $hook): void {
        global $PAGE;

        if (!popup::is_popup_request($_SERVER) || !config::is_usable() || $PAGE->course->format !== 'rumbos') {
            return;
        }
        $PAGE->requires->js_call_amd('format_rumbos/popup_page', 'init', [[
            'frameName' => popup::FRAME_NAME,
            'courseView' => str_starts_with($PAGE->pagetype, 'course-view'),
        ]]);
    }
}
