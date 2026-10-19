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
 * Read access to the plugin's admin settings.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class config {
    /**
     * Master switch. When off the format renders the standard content only.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return (bool) get_config('format_rumbos', 'enabled');
    }

    /**
     * The configured app URL (normalised), or null when unset or invalid.
     *
     * @return string|null
     */
    public static function get_app_url(): ?string {
        return app_url::normalize((string) get_config('format_rumbos', 'appurl'));
    }

    /**
     * Audience claim for launch assertions.
     *
     * @return string
     */
    public static function get_audience(): string {
        $audience = (string) get_config('format_rumbos', 'audience');
        return $audience === '' ? 'rumbos' : $audience;
    }
}
