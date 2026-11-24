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

/**
 * Shows a file resource inline for the overlay of a flow step.
 *
 * It does what the resource's own view page does for a user who opens it (login and access checks, then
 * resource_view(), Moodle's own record of the view and of completion on view), and then sends the browser to the file
 * with an inline disposition, whatever display mode the resource has. See {@see \format_rumbos\local\resource_inline}.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/mod/resource/lib.php');
require_once($CFG->libdir . '/completionlib.php');

use core\context\module as context_module;
use format_rumbos\local\resource_inline;

$id = required_param('id', PARAM_INT);
[$course, $cm] = get_course_and_cm_from_cmid($id, 'resource');
require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/resource:view', $context);

$file = resource_inline::main_file($context);
if ($course->format !== 'rumbos' || $file === null || !resource_inline::is_inline_type($file)) {
    // Not what this page is for: the resource's own page does the right thing.
    redirect(new moodle_url('/mod/resource/view.php', ['id' => $cm->id]));
}

$resource = $DB->get_record('resource', ['id' => $cm->instance], '*', MUST_EXIST);
resource_view($resource, $course, $cm, $context);
redirect(resource_inline::file_url($resource, $file));
