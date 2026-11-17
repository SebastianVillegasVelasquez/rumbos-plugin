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
 * Limits how often the result receiver is called: fixed windows of one minute, for the whole site.
 *
 * The only legitimate caller is one backend, so the limit is not per client. Two counters: every request, and the
 * requests that fail before their signature is checked, which an attacker can send for free and which would
 * otherwise fill the audit table. The counts are kept in the application cache "resultrate". It is a brake, not a
 * security boundary: two requests at the very same moment may both pass.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class result_rate_limit {
    /** @var int Requests the receiver handles in one window. A school finishing an exam at once is the burst to allow. */
    public const LIMIT = 600;

    /** @var int Audit rows written in one window for requests that were never authenticated. */
    public const UNAUTHENTICATED_LIMIT = 60;

    /** @var int Length of a window in seconds. */
    public const WINDOW = 60;

    /**
     * Counts a request and says whether it is allowed.
     *
     * @param int|null $now Defaults to the current time.
     * @return bool False when the window is used up.
     */
    public static function allow(?int $now = null): bool {
        return self::count('all', self::LIMIT, $now);
    }

    /**
     * Counts a request that was not authenticated and says whether it may be written to the audit.
     *
     * @param int|null $now Defaults to the current time.
     * @return bool False when the window is used up: the request is answered but not written.
     */
    public static function allow_unauthenticated_audit(?int $now = null): bool {
        return self::count('unauthenticated', self::UNAUTHENTICATED_LIMIT, $now);
    }

    /**
     * Increments one counter of the current window.
     *
     * @param string $name Which counter.
     * @param int $limit Its limit.
     * @param int|null $now Defaults to the current time.
     * @return bool Whether the count is still within the limit.
     */
    private static function count(string $name, int $limit, ?int $now): bool {
        $cache = \cache::make('format_rumbos', 'resultrate');
        $key = $name . '_' . intdiv($now ?? time(), self::WINDOW);

        $count = (int) $cache->get($key);
        if ($count >= $limit) {
            return false;
        }
        $cache->set($key, $count + 1);
        return true;
    }
}
