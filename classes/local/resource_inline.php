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

use core\context\module as context_module;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/resource/locallib.php');

/**
 * Shows a file resource inside the overlay whatever display mode its teacher chose.
 *
 * A file resource has seven display modes (Moodle's RESOURCELIB_DISPLAY_*). Inside a frame four of them show the file
 * ("automatic" for a PDF, "open" and "embed"), and the others do not: "download" ends in an attachment the browser
 * saves, "in pop-up" and "new window" show a page with a link that opens a window the overlay cannot hold, and "in
 * frame" nests a Moodle page and the file in a frameset. For a PDF or an image, and for the overlay only, this class
 * sends those to a page of the plugin that records the view exactly as the resource's own view page does and then
 * redirects to the file with an inline disposition. The resource itself is not changed, and its link in the course
 * keeps behaving as its teacher set it. The matrix is in docs/HOST-CONTRACT.md ("Moodle activities in a flow").
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class resource_inline {
    /** @var int[] The display modes whose own page does not show the file inside a frame. */
    public const FRAME_UNFRIENDLY = [RESOURCELIB_DISPLAY_FRAME, RESOURCELIB_DISPLAY_NEW, RESOURCELIB_DISPLAY_DOWNLOAD,
        RESOURCELIB_DISPLAY_POPUP];

    /**
     * The page of the plugin that shows a resource inline.
     *
     * @param int $cmid
     * @return \moodle_url
     */
    public static function url(int $cmid): \moodle_url {
        return new \moodle_url('/course/format/rumbos/resource.php', ['id' => $cmid]);
    }

    /**
     * Whether a file is one the browser shows itself: a PDF or a web image.
     *
     * Anything else (an office document, an archive) is not shown by a browser, so the resource's own page is the
     * best that can be done and the overlay is no worse than it is without this class.
     *
     * @param \stored_file $file
     * @return bool
     */
    public static function is_inline_type(\stored_file $file): bool {
        $mimetype = $file->get_mimetype();
        return $mimetype === 'application/pdf' || file_mimetype_in_typegroup($mimetype, 'web_image');
    }

    /**
     * The main file of a resource, the same one its view page picks.
     *
     * @param context_module $context
     * @return \stored_file|null
     */
    public static function main_file(context_module $context): ?\stored_file {
        $files = get_file_storage()->get_area_files($context->id, 'mod_resource', 'content', 0, 'sortorder DESC, id ASC', false);
        return $files ? reset($files) : null;
    }

    /**
     * Whether the overlay must go through {@see self::url()} for this resource.
     *
     * @param \cm_info $cm A resource.
     * @return bool
     */
    public static function needs_inline_view(\cm_info $cm): bool {
        global $DB;

        $file = self::main_file(context_module::instance($cm->id));
        $resource = $DB->get_record('resource', ['id' => $cm->instance]);
        if ($file === null || !$resource || !self::is_inline_type($file)) {
            return false;
        }
        $resource->mainfile = $file->get_filename();
        return in_array((int) resource_get_final_display_type($resource), self::FRAME_UNFRIENDLY, true);
    }

    /**
     * Where the browser is sent to see the file inline.
     *
     * The address is Moodle's own file address (the one the resource's view page redirects to), with no forced download.
     *
     * @param \stdClass $resource
     * @param \stored_file $file
     * @return \moodle_url
     */
    public static function file_url(\stdClass $resource, \stored_file $file): \moodle_url {
        $path = '/' . $file->get_contextid() . '/mod_resource/content/' . $resource->revision . $file->get_filepath()
            . $file->get_filename();
        return \moodle_url::make_file_url('/pluginfile.php', $path, false);
    }
}
