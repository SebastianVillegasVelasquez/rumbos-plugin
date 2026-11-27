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
 * Limits how often the mirror receiver is called: fixed windows of one minute, for the whole site.
 *
 * It has its own counter, in the same application cache as the passback's, so that a burst of mirror messages can never
 * use up the passback's allowance: a student's completion must not wait for the mirror.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class mirror_rate_limit {
    /** @var int Messages handled in one window. Writing an attempt is heavier than a passback. */
    public const LIMIT = 120;

    /** @var int Length of a window in seconds. */
    public const WINDOW = 60;

    /**
     * Counts a request and says whether it is allowed.
     *
     * @param int $now
     * @return bool False when the window is used up.
     */
    public static function allow(int $now): bool {
        $cache = \cache::make('format_rumbos', 'resultrate');
        $key = 'mirror_' . intdiv($now, self::WINDOW);
        $count = (int) $cache->get($key);
        if ($count >= self::LIMIT) {
            return false;
        }
        $cache->set($key, $count + 1);
        return true;
    }
}
