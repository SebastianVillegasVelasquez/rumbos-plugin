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
    /** @var int Default of the minimum cohort setting. */
    public const DEFAULT_MIN_COHORT = 5;

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
     * Whether Rumbos can be shown: enabled, with a valid app URL on another origin.
     *
     * The app must not share the origin of Moodle: the iframe sandbox needs
     * allow-same-origin together with allow-scripts, which would let a
     * same-origin page lift its own sandbox.
     *
     * @return bool
     */
    public static function is_usable(): bool {
        global $CFG;

        $appurl = self::get_app_url();
        return self::is_enabled()
            && $appurl !== null
            && !app_url::is_same_origin($appurl, $CFG->wwwroot);
    }

    /**
     * Origin of this Moodle site, as the app sees it in the host parameter.
     *
     * @return string
     */
    public static function get_host_origin(): string {
        global $CFG;

        return app_url::origin($CFG->wwwroot);
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

    /**
     * How long the page waits for the app before showing the standard content, in milliseconds.
     *
     * It has no admin setting: 8 seconds is right for users. Tests that play the app's part
     * need longer and raise it with the plugin config value timeoutms.
     *
     * @return int
     */
    public static function get_app_timeout_ms(): int {
        $timeout = (int) get_config('format_rumbos', 'timeoutms');
        return $timeout > 0 ? min($timeout, 300000) : 8000;
    }

    /**
     * Fewest tracked students a statistic may describe before it is shown.
     *
     * Below this number the counts of an activity could point at one or two people, so the host sends none.
     *
     * @return int At least 1.
     */
    public static function get_min_cohort(): int {
        $value = get_config('format_rumbos', 'mincohort');
        return $value === false || $value === '' ? self::DEFAULT_MIN_COHORT : max(1, (int) $value);
    }
}
