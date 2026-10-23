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

namespace format_rumbos\output\courseformat;

use core\context\course as context_course;
use core_courseformat\output\local\content as content_base;
use format_rumbos\local\access;
use format_rumbos\local\app_url;
use format_rumbos\local\config;
use format_rumbos\local\popup;
use renderer_base;

/**
 * Course page content: the Rumbos container above the standard content.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class content extends content_base {
    /**
     * Use the format's own template, which wraps the core one.
     *
     * @param renderer_base $renderer
     * @return string
     */
    public function get_template_name(renderer_base $renderer): string {
        return 'format_rumbos/local/content';
    }

    /**
     * Adds the Rumbos flags to the standard course content data.
     *
     * - showrumbos: the container is shown to enrolled users and editors, and
     *   only while Rumbos is enabled and configured.
     * - collapselist: students get the standard list behind a disclosure
     *   control; teachers in editing mode keep the core editor expanded.
     * - suggestediting: a teacher with editing off gets a note to turn it on.
     *   The map editor itself needs the capability and Moodle's editing mode.
     *
     * @param renderer_base $output
     * @return \stdClass
     */
    public function export_for_template(renderer_base $output) {
        global $CFG, $PAGE;

        $data = parent::export_for_template($output);

        $courseid = $this->format->get_courseid();
        $context = context_course::instance($courseid);
        // The activity popup frames pages of this course, and "Continue" there can land on the
        // course page: never nest the app inside the popup.
        $showrumbos = config::is_usable() && access::can_use($context) && !popup::is_popup_request($_SERVER);

        $data->showrumbos = $showrumbos;
        $data->courseid = $courseid;
        $data->collapselist = $showrumbos && !$this->format->show_editor();
        $editing = (bool) $PAGE->user_is_editing();
        $data->suggestediting = $showrumbos && access::should_suggest_editing($context, $editing);

        if ($showrumbos) {
            $appurl = config::get_app_url();
            $data->embedurl = app_url::embed_url($appurl, config::get_host_origin());
            $PAGE->requires->js_call_amd('format_rumbos/host', 'init', [[
                'courseId' => (int) $courseid,
                'appOrigin' => app_url::origin($appurl),
                'mode' => access::map_mode($context, $editing),
                'canEdit' => has_capability('format/rumbos:editmap', $context),
                'locale' => str_replace('_', '-', current_language()),
                'timeoutMs' => config::get_app_timeout_ms(),
                'wwwroot' => $CFG->wwwroot,
                'popupFrameName' => popup::FRAME_NAME,
                'newTabModules' => popup::get_newtab_modules(),
                'pollModules' => popup::get_poll_modules(),
            ]]);
        }

        return $data;
    }
}
