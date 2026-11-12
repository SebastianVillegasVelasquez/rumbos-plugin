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
 * Limits how often a user may write flow progress: a fixed window of one minute per user.
 *
 * The count is kept in the application cache "flowrate", keyed by user and minute. It is a brake against a client
 * that loops, not a security boundary: two requests at the very same moment may both pass.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class flow_rate_limit {
    /** @var int How many writes a user may make in one window. */
    public const LIMIT = 30;

    /** @var int Length of a window in seconds. */
    public const WINDOW = 60;

    /**
     * Counts a write and says whether it is allowed.
     *
     * @param int $userid
     * @param int|null $now Defaults to the current time.
     * @return bool False when the user has used up the window.
     */
    public static function allow(int $userid, ?int $now = null): bool {
        $cache = \cache::make('format_rumbos', 'flowrate');
        $key = $userid . '_' . intdiv($now ?? time(), self::WINDOW);

        $count = (int) $cache->get($key);
        if ($count >= self::LIMIT) {
            return false;
        }
        $cache->set($key, $count + 1);
        return true;
    }
}
