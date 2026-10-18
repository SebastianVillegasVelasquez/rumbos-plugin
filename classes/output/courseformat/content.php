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
use format_rumbos\local\config;
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
     * - showrumbos: the container is shown to enrolled users and editors.
     * - collapselist: students get the standard list behind a disclosure
     *   control; teachers in editing mode keep the core editor expanded.
     *
     * @param renderer_base $output
     * @return \stdClass
     */
    public function export_for_template(renderer_base $output) {
        $data = parent::export_for_template($output);

        $context = context_course::instance($this->format->get_courseid());
        $showrumbos = config::is_enabled()
            && (is_enrolled($context, null, '', true) || has_capability('format/rumbos:editmap', $context));

        $data->showrumbos = $showrumbos;
        $data->courseid = $this->format->get_courseid();
        $data->collapselist = $showrumbos && !$this->format->show_editor();

        return $data;
    }
}
