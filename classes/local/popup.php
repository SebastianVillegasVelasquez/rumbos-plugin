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

/**
 * Settings and request checks for the activity popup.
 *
 * The popup is an overlay the host page opens over the map; it frames the
 * same-origin Moodle activity page. See docs/DESIGN-activity-popups.md.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class popup {
    /** @var string Name of the popup iframe, the signal the activity page looks for. */
    public const FRAME_NAME = 'rumbos-popup';

    /** @var string Popup mode: try the embedded layout, then hide the chrome with CSS. */
    public const MODE_AUTO = 'auto';

    /** @var string Popup mode: only hide the chrome with CSS. */
    public const MODE_CSS = 'css';

    /** @var string Popup mode: leave the activity pages alone. */
    public const MODE_OFF = 'off';

    /** @var string Popup position: the overlay covers the whole window. */
    public const POSITION_FULLSCREEN = 'fullscreen';

    /** @var string Popup position: the overlay starts below the site's own header. */
    public const POSITION_BELOW_HEADER = 'below-site-header';

    /** @var string The request is not from the popup: serve the page as usual. */
    public const APPLY_NONE = 'none';

    /** @var string The browser vouches for a same-origin iframe request of the marked course. */
    public const APPLY_FULL = 'full';

    /** @var string The browser sends no Sec-Fetch headers: only the page script, checking the frame name, may act. */
    public const APPLY_FALLBACK = 'fallback';

    /** @var int Most selectors the hide list may hold. */
    public const MAX_SELECTORS = 30;

    /** @var int Longest selector (characters). */
    public const MAX_SELECTOR_LENGTH = 200;

    /** @var string Body class the popup page styles are scoped to. */
    public const PAGE_CLASS = 'format-rumbos-popup-page';

    /** @var string Modules that open in a new tab unless the admin changes the list. */
    public const DEFAULT_NEWTAB_MODULES = 'bigbluebuttonbn,lti';

    /** @var string Modules that report completion without the page navigating, so the popup polls for them. */
    public const DEFAULT_POLL_MODULES = 'scorm,h5pactivity';

    /**
     * Parses a list of module names (commas, spaces or new lines) into a clean list.
     *
     * @param string $value
     * @return string[]|null The unique names in order, or null when any entry is not a module name.
     */
    public static function parse_modules(string $value): ?array {
        $names = [];
        foreach (preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY) as $name) {
            $name = strtolower($name);
            if (!preg_match('/^[a-z][a-z0-9]*$/', $name)) {
                return null;
            }
            $names[$name] = $name;
        }
        return array_values($names);
    }

    /**
     * Modules whose activities open in a new tab instead of the popup.
     *
     * @return string[]
     */
    public static function get_newtab_modules(): array {
        $value = get_config('format_rumbos', 'newtabmodules');
        if ($value === false) {
            $value = self::DEFAULT_NEWTAB_MODULES;
        }
        return self::parse_modules((string) $value) ?? [];
    }

    /**
     * Modules whose completion can change while their page stays put (a package reporting inside its own frame).
     *
     * @return string[]
     */
    public static function get_poll_modules(): array {
        $value = get_config('format_rumbos', 'pollmodules');
        if ($value === false) {
            $value = self::DEFAULT_POLL_MODULES;
        }
        return self::parse_modules((string) $value) ?? [];
    }

    /**
     * Whether a request comes from a same-origin iframe, as the popup makes.
     *
     * The browser sets the Sec-Fetch-* headers and page scripts cannot forge them. A
     * browser that does not send them gets the activity page with its full chrome
     * (usable, only less tidy), so nothing breaks; the page script still checks the
     * frame name before changing anything.
     *
     * @param array $server The $_SERVER array.
     * @return bool
     */
    public static function is_popup_request(array $server): bool {
        return ($server['HTTP_SEC_FETCH_DEST'] ?? '') === 'iframe'
            && ($server['HTTP_SEC_FETCH_SITE'] ?? '') === 'same-origin';
    }

    /**
     * The popup mode setting.
     *
     * @return string One of the MODE_* constants.
     */
    public static function get_mode(): string {
        $value = get_config('format_rumbos', 'popupmode');
        return in_array($value, [self::MODE_CSS, self::MODE_OFF], true) ? $value : self::MODE_AUTO;
    }

    /**
     * The popup position setting.
     *
     * @return string One of the POSITION_* constants.
     */
    public static function get_position(): string {
        $value = get_config('format_rumbos', 'popupposition');
        return $value === self::POSITION_BELOW_HEADER ? $value : self::POSITION_FULLSCREEN;
    }

    /**
     * The selector of the site header the overlay sits below, or an empty string.
     *
     * @return string
     */
    public static function get_header_selector(): string {
        $selectors = self::parse_selectors((string) get_config('format_rumbos', 'popupheaderselector'));
        return count($selectors ?? []) === 1 ? $selectors[0] : '';
    }

    /**
     * Parses the list of selectors to hide: one per line, from a safe subset of CSS.
     *
     * The subset has no braces, no at-rules, no url(), no comments, no commas, balanced brackets
     * and quotes, and bounded length and count, so a selector can never close its rule or load anything.
     *
     * @param string $value
     * @return string[]|null The selectors in order, or null when any line is not acceptable.
     */
    public static function parse_selectors(string $value): ?array {
        $selectors = [];
        foreach (preg_split('/\R/', $value) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (
                strlen($line) > self::MAX_SELECTOR_LENGTH
                || !preg_match('/^[A-Za-z0-9_\-#.\s>+~*:()\[\]="\'^$|]+$/', $line)
                || stripos($line, 'url(') !== false
                || substr_count($line, '(') !== substr_count($line, ')')
                || substr_count($line, '[') !== substr_count($line, ']')
                || substr_count($line, '"') % 2 !== 0
                || substr_count($line, "'") % 2 !== 0
            ) {
                return null;
            }
            $selectors[$line] = $line;
        }
        if (count($selectors) > self::MAX_SELECTORS) {
            return null;
        }
        return array_values($selectors);
    }

    /**
     * The style rules that hide the configured selectors inside the popup.
     *
     * Each selector gets its own rule (one invalid selector must not void the others) and is scoped to
     * the popup body class, which only the popup frame ever has.
     *
     * @return string CSS, empty when nothing is configured.
     */
    public static function hide_css(): string {
        $selectors = self::parse_selectors((string) get_config('format_rumbos', 'popuphideselectors')) ?? [];
        $prefix = 'body.' . self::PAGE_CLASS . ' ';
        return implode("\n", array_map(fn($selector) => $prefix . $selector . '{display:none!important}', $selectors));
    }

    /**
     * Decides whether a request is the popup's, and how much to trust that.
     *
     * Everything must hold for the layout and styles to apply: the mode is not off, the session marker
     * (set when the host opened the popup) has not expired and belongs to this course, and the browser
     * says the request is a same-origin iframe. Browsers that send no Sec-Fetch headers at all get the
     * fallback, which only the page script acts on, after checking the frame name. A request that does
     * carry the headers but is not a same-origin iframe (another tab, another site) is never touched.
     *
     * @param array $server The $_SERVER array.
     * @param array|null $marker The session marker: ['courseid' => int, 'expires' => int].
     * @param int $courseid The course of the page being served.
     * @param int $now Current time.
     * @param string $mode The popup mode setting.
     * @return string One of the APPLY_* constants.
     */
    public static function decide(array $server, ?array $marker, int $courseid, int $now, string $mode): string {
        if ($mode === self::MODE_OFF || !popup_session::is_valid($marker, $courseid, $now)) {
            return self::APPLY_NONE;
        }
        if (!isset($server['HTTP_SEC_FETCH_DEST']) && !isset($server['HTTP_SEC_FETCH_SITE'])) {
            return self::APPLY_FALLBACK;
        }
        return self::is_popup_request($server) ? self::APPLY_FULL : self::APPLY_NONE;
    }
}
