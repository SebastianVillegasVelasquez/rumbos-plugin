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

    /** @var string Modules that open in a new tab unless the admin changes the list. */
    public const DEFAULT_NEWTAB_MODULES = 'bigbluebuttonbn,lti';

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
}
