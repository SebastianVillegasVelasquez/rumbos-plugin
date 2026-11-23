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

namespace format_rumbos\local\mirror;

use format_rumbos\local\result_config;

/**
 * Settings of the native quiz mirror: off by default, and it trusts the same issuer, tenant and keys as the passback.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class mirror_config {
    /**
     * Whether the administrator switched the mirror on.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return (bool) get_config('format_rumbos', 'mirrorenabled');
    }

    /**
     * Whether the mirror is on and the signature settings it shares with the passback are complete.
     *
     * @return bool
     */
    public static function is_ready(): bool {
        return self::is_enabled() && result_config::is_ready();
    }

    /**
     * The address of the mirror receiver script, as the backend must call it.
     *
     * @return string
     */
    public static function endpoint_url(): string {
        return (new \moodle_url('/course/format/rumbos/mirror.php'))->out(false);
    }
}
