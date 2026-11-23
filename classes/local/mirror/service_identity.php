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

/**
 * Runs the mirror's writes as a site administrator.
 *
 * The receiver has no session and so no user, but Moodle records who made a quiz, a question and a manual grade, and
 * some of its code reads the current user. Everything the mirror writes is attributed to the first site administrator,
 * for the time of one call, and the user that was current is put back whatever happens. The authority is the signature
 * of the statement, checked before this is ever called, not this identity.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class service_identity {
    /**
     * Calls a function as the first site administrator.
     *
     * @param callable $callback
     * @return mixed What the function returns.
     */
    public static function run(callable $callback): mixed {
        global $USER;

        $before = isset($USER->id) && $USER->id ? clone $USER : null;
        \core\session\manager::set_user(get_admin());
        try {
            return $callback();
        } finally {
            if ($before !== null) {
                \core\session\manager::set_user($before);
            } else {
                \core\session\manager::init_empty_session();
            }
        }
    }
}
