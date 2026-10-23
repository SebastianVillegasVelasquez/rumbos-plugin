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

use core\hook\output\before_http_headers;
use core\hook\output\before_standard_head_html_generation;
use format_rumbos\local\config;
use format_rumbos\local\popup;
use format_rumbos\local\popup_session;

/**
 * Hook callbacks of the Rumbos format.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /** @var string[] Layouts that already suit a framed page, or that must stay as they are. */
    private const KEEP_LAYOUTS = ['embedded', 'popup', 'secure', 'maintenance', 'redirect', 'print', 'login'];

    /**
     * How to treat the page being served: see {@see popup::decide()}.
     *
     * @return string One of the popup::APPLY_* constants.
     */
    private static function decision(): string {
        global $PAGE;

        $marker = popup_session::get();
        if ($marker === null || !config::is_usable() || $PAGE->course->format !== 'rumbos') {
            return popup::APPLY_NONE;
        }
        return popup::decide($_SERVER, $marker, (int) $PAGE->course->id, time(), popup::get_mode());
    }

    /**
     * Gives an activity page framed by the popup the embedded layout, and keeps the popup marker alive.
     *
     * Runs inside the renderer's header(), just before the layout file is chosen, so it takes effect
     * whatever layout the activity script set earlier. Pages without a popup marker in the session are
     * left after one session lookup.
     *
     * @param before_http_headers $hook
     */
    public static function before_http_headers(before_http_headers $hook): void {
        global $PAGE;

        $decision = self::decision();
        if ($decision === popup::APPLY_NONE) {
            return;
        }
        popup_session::extend();
        if ($decision !== popup::APPLY_FULL) {
            return;
        }
        // The headers come from the browser, so the class can safely be set here.
        $PAGE->add_body_class(popup::PAGE_CLASS);
        if (popup::get_mode() === popup::MODE_AUTO && !in_array($PAGE->pagelayout, self::KEEP_LAYOUTS, true)) {
            $PAGE->set_pagelayout('embedded');
        }
    }

    /**
     * Loads the popup page script and the styles that hide the site chrome on pages framed by the popup.
     *
     * Only same-origin iframe requests (or, where the browser sends no such headers, requests in a
     * session with a popup marker) for a page of a Rumbos course get anything, so every other page is
     * served exactly as before. The styles apply only under the popup body class.
     *
     * @param before_standard_head_html_generation $hook
     */
    public static function before_standard_head_html_generation(before_standard_head_html_generation $hook): void {
        global $PAGE;

        if (!config::is_usable() || $PAGE->course->format !== 'rumbos') {
            return;
        }
        $decision = self::decision();
        if ($decision === popup::APPLY_NONE && !popup::is_popup_request($_SERVER)) {
            return;
        }
        $PAGE->requires->js_call_amd('format_rumbos/popup_page', 'init', [[
            'frameName' => popup::FRAME_NAME,
            'courseView' => str_starts_with($PAGE->pagetype, 'course-view'),
        ]]);
        $css = $decision === popup::APPLY_NONE ? '' : popup::hide_css();
        if ($css !== '') {
            $hook->add_html('<style>' . $css . '</style>');
        }
    }
}
