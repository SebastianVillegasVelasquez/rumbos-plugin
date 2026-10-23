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
 * The short-lived marker that says this session has an activity popup open.
 *
 * The host page sets it right before it loads the popup frame and clears it on close. Activity
 * pages restyle themselves only while it is valid, so it is the first of the conditions that keep
 * every other page, and every other tab of the same user, untouched.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class popup_session {
    /** @var string Name of the session property that holds the marker. */
    public const KEY = 'format_rumbos_popup';

    /** @var int Seconds the marker lasts; every qualifying request extends it. */
    public const TTL = 300;

    /**
     * Whether a marker is current and belongs to the course.
     *
     * @param array|null $marker
     * @param int $courseid
     * @param int $now
     * @return bool
     */
    public static function is_valid(?array $marker, int $courseid, int $now): bool {
        return $marker !== null
            && isset($marker['courseid'], $marker['expires'])
            && (int) $marker['courseid'] === $courseid
            && (int) $marker['expires'] > $now;
    }

    /**
     * Marks the session as having a popup open for the course.
     *
     * @param int $courseid
     * @param int|null $now
     */
    public static function start(int $courseid, ?int $now = null): void {
        global $SESSION;
        $SESSION->{self::KEY} = ['courseid' => $courseid, 'expires' => ($now ?? time()) + self::TTL];
    }

    /**
     * Clears the marker.
     */
    public static function end(): void {
        global $SESSION;
        unset($SESSION->{self::KEY});
    }

    /**
     * The marker of this session, if any.
     *
     * @return array|null
     */
    public static function get(): ?array {
        global $SESSION;
        $marker = $SESSION->{self::KEY} ?? null;
        return is_array($marker) ? $marker : null;
    }

    /**
     * Pushes the expiry forward after a request that matched.
     *
     * @param int|null $now
     */
    public static function extend(?int $now = null): void {
        global $SESSION;
        $marker = self::get();
        if ($marker !== null) {
            $marker['expires'] = ($now ?? time()) + self::TTL;
            $SESSION->{self::KEY} = $marker;
        }
    }
}
