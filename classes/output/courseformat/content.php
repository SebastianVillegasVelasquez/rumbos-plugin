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
use format_rumbos\local\course_name;
use format_rumbos\local\level_decision;
use format_rumbos\local\level_gate;
use format_rumbos\local\map_size;
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
     * - heightmode, sizestyle: how tall the container is, from the options of the course and the site;
     *   the style carries integers only, as custom properties the stylesheet turns into a clamped height.
     * - suggestediting: a teacher with editing off gets a note to turn it on.
     *   The map editor itself needs the capability and Moodle's editing mode.
     *
     * @param renderer_base $output
     * @return \stdClass
     */
    public function export_for_template(renderer_base $output) {
        global $CFG, $PAGE, $USER;

        $data = parent::export_for_template($output);

        $courseid = $this->format->get_courseid();
        $context = context_course::instance($courseid);
        // The activity popup frames pages of this course, and "Continue" there can land on the
        // course page: never nest the app inside the popup.
        $showrumbos = config::is_usable() && access::can_use($context) && !popup::is_popup_request($_SERVER);

        // A student who has not completed the previous level gets no iframe and no launch: a message instead. The standard
        // content below stays as it is; the gate only covers the Rumbos launch (see docs/LEVEL-PREREQUISITES.md).
        $locked = false;
        if ($showrumbos) {
            $decision = level_gate::check($courseid, (int) $USER->id);
            if (!$decision->is_open()) {
                $showrumbos = false;
                $locked = true;
                $this->export_level_message($data, $decision, $context);
            }
        }

        $data->showrumbos = $showrumbos;
        $data->courseid = $courseid;
        $data->collapselist = ($showrumbos || $locked) && !$this->format->show_editor();
        $editing = (bool) $PAGE->user_is_editing();
        $data->suggestediting = $showrumbos && access::should_suggest_editing($context, $editing);

        if ($showrumbos) {
            $size = $this->format->get_map_size();
            $data->heightmode = $size['mode'];
            $data->sizestyle = map_size::css_variables($size);
            $appurl = config::get_app_url();
            $data->embedurl = app_url::embed_url($appurl, config::get_host_origin());
            $PAGE->requires->js_call_amd('format_rumbos/host', 'init', [[
                'courseId' => (int) $courseid,
                'courseName' => course_name::for_host($this->format->get_course()->fullname, $context),
                'appOrigin' => app_url::origin($appurl),
                'mode' => access::map_mode($context, $editing),
                'size' => ['mode' => $size['mode'], 'min' => $size['min'], 'max' => $size['max']],
                'canEdit' => has_capability('format/rumbos:editmap', $context),
                'locale' => str_replace('_', '-', current_language()),
                'timeoutMs' => config::get_app_timeout_ms(),
                'wwwroot' => $CFG->wwwroot,
                'popupFrameName' => popup::FRAME_NAME,
                'popupHeaderSelector' => popup::get_position() === popup::POSITION_BELOW_HEADER
                    ? popup::get_header_selector() : '',
                'popupSession' => popup::get_mode() !== popup::MODE_OFF,
                'newTabModules' => popup::get_newtab_modules(),
                'pollModules' => popup::get_poll_modules(),
                'tutorUrl' => (string) config::get_tutor_url(),
            ]]);
        }

        return $data;
    }

    /**
     * Fills in the message shown instead of the map when the level is locked or the check could not run.
     *
     * Students are told which level to complete first, with a link to it only when they can open that course. Nothing else
     * is revealed: no ID numbers and no reason for a failed check.
     *
     * @param \stdClass $data Template data, extended in place.
     * @param level_decision $decision A closed decision.
     * @param context_course $context
     */
    private function export_level_message(\stdClass $data, level_decision $decision, context_course $context): void {
        global $PAGE;

        if ($decision->status !== level_decision::LOCKED || $decision->prerequisite === null) {
            $data->levelerror = true;
            $data->levelmessage = get_string('levelerror_body', 'format_rumbos');
            $data->levelretrytext = get_string('levelerror_retry', 'format_rumbos');
            $data->levelretryurl = $PAGE->url->out(false);
            return;
        }

        $previous = $decision->prerequisite;
        $previouscontext = context_course::instance($previous->id);
        $previousname = format_string($previous->fullname, true, ['context' => $previouscontext]);
        $data->levellocked = true;
        $data->leveltitle = get_string('levellocked_title', 'format_rumbos');
        $data->levelmessage = get_string('levellocked_body', 'format_rumbos', (object) [
            'level' => format_string($this->format->get_course()->fullname, true, ['context' => $context]),
            'previous' => $previousname,
        ]);
        if (can_access_course($previous)) {
            $data->levellink = (new \moodle_url('/course/view.php', ['id' => $previous->id]))->out(false);
            $data->levellinktext = get_string('levellocked_link', 'format_rumbos', $previousname);
        }
    }
}
