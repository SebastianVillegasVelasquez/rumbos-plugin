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

/**
 * The Moodle activities a flow step may show (host contract v5.11).
 *
 * A flow step can point at a plain content activity of the course (a PDF, a link, a page) instead of a Rumbos upload. The
 * editor picks it from {@see self::list()}; the student opens it through {@see self::resolve()}. Moodle is the only
 * authority on both: the backend stores a bare course module id and cannot know whether it exists, and the page never
 * lets the app supply an address.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class flow_activities {
    /**
     * The modules a flow step may show: plain content, which opens in the overlay and records viewing on its own.
     *
     * Extending the list is a code change on purpose: a module that is interactive (a quiz, a SCORM, a forum) or that
     * navigates away from the site does not belong in a flow step without being looked at first. Add the name here,
     * check it in the overlay (docs/HOST-CONTRACT.md, "Moodle activities in a flow"), and add a test.
     *
     * @var string[]
     */
    public const ALLOWED_MODULES = ['resource', 'url', 'page'];

    /** @var string Reply when the course module is not in this course or does not exist. */
    public const NOT_FOUND = 'not_found';

    /** @var string Reply when the course module exists but this user may not open it. */
    public const FORBIDDEN = 'forbidden';

    /** @var string Reply when the module is not one a flow may show. */
    public const UNSUPPORTED = 'unsupported';

    /**
     * Whether a module name is one a flow may show.
     *
     * @param string $modname
     * @return bool
     */
    public static function is_allowed_module(string $modname): bool {
        return in_array($modname, self::ALLOWED_MODULES, true);
    }

    /**
     * The activities of the course an editor may pick for a flow step.
     *
     * Only this course, only the allowed modules, and never one being deleted. Hidden activities are listed with
     * `visible` false so the editor sees that students would not get them.
     *
     * @param int $courseid
     * @return array[] Each with cmid, name, modname and visible.
     * @throws \required_capability_exception When the user may not edit the map.
     */
    public static function list(int $courseid): array {
        $context = context_course::instance($courseid);
        if (!has_capability('format/rumbos:editmap', $context)) {
            throw new \required_capability_exception($context, 'format/rumbos:editmap', 'nopermissions', '');
        }

        $items = [];
        foreach (get_fast_modinfo($courseid)->get_cms() as $cm) {
            if (!self::is_allowed_module($cm->modname) || $cm->deletioninprogress || !$cm->uservisible) {
                continue;
            }
            $items[] = [
                'cmid' => (int) $cm->id,
                'name' => self::plain_name($cm, $context),
                'modname' => $cm->modname,
                'visible' => (bool) $cm->visible,
            ];
        }
        return $items;
    }

    /**
     * Decides whether the current user may open an activity in the overlay, and where.
     *
     * Every check is Moodle's: the course module must belong to this course, be visible to this user (hidden,
     * restricted and being-deleted all count as not), be of an allowed module, and the user must hold the module's view
     * capability. The address is built here and returned to the host only; it is never part of a message to the app.
     *
     * @param int $courseid
     * @param int $cmid
     * @return array Either ['url', 'title', 'modname'] or ['error' => one of the reason constants].
     */
    public static function resolve(int $courseid, int $cmid): array {
        try {
            $cm = get_fast_modinfo($courseid)->get_cm($cmid);
        } catch (\moodle_exception $e) {
            // Not a course module of this course, whatever the reason: the same answer, so ids cannot be probed.
            return ['error' => self::NOT_FOUND];
        }
        if ($cm->deletioninprogress) {
            return ['error' => self::NOT_FOUND];
        }
        if (!self::is_allowed_module($cm->modname)) {
            return ['error' => self::UNSUPPORTED];
        }
        $context = context_module::instance($cm->id);
        if (!$cm->uservisible || !has_capability("mod/{$cm->modname}:view", $context)) {
            return ['error' => self::FORBIDDEN];
        }

        return [
            'url' => self::overlay_url($cm)->out(false),
            'title' => self::plain_name($cm, context_course::instance($courseid)),
            'modname' => $cm->modname,
        ];
    }

    /**
     * The address the overlay loads for an activity.
     *
     * It is the activity's own view page, which is what records the view for completion, except for a file the
     * resource would not show inside a frame (see {@see resource_inline}).
     *
     * @param \cm_info $cm
     * @return \moodle_url
     */
    private static function overlay_url(\cm_info $cm): \moodle_url {
        if ($cm->modname === 'resource' && resource_inline::needs_inline_view($cm)) {
            return resource_inline::url($cm->id);
        }
        return new \moodle_url("/mod/{$cm->modname}/view.php", ['id' => $cm->id]);
    }

    /**
     * The name of an activity as plain text.
     *
     * @param \cm_info $cm
     * @param context_course $context
     * @return string
     */
    private static function plain_name(\cm_info $cm, context_course $context): string {
        $name = $cm->get_formatted_name(['context' => $context, 'escape' => false]);
        $text = html_entity_decode(strip_tags($name), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $text));
    }
}
