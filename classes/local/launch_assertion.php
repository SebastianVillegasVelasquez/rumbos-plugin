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

use core\context\course as context_course;
use Firebase\JWT\JWT;

/**
 * Builds the short-lived launch assertion (JWS, RS256) for the Rumbos backend.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class launch_assertion {
    /** @var string The only algorithm ever used; it is pinned, never read from input. */
    public const ALGORITHM = 'RS256';

    /** @var int Lifetime in seconds. The contract allows at most 60. */
    public const LIFETIME = 60;

    /**
     * Signs an assertion for a user in a course.
     *
     * Claims: iss, aud, sub (pseudonym), course, role, iat, exp, jti. No name,
     * email or any other personal data is included.
     *
     * @param int $courseid
     * @param int $userid
     * @param int|null $now Unix time override, for tests.
     * @return array{token: string, expiresat: int}
     */
    public static function create(int $courseid, int $userid, ?int $now = null): array {
        global $CFG;

        $now = $now ?? time();
        $context = context_course::instance($courseid);
        $iseditor = has_capability('format/rumbos:editmap', $context, $userid);

        $claims = [
            'iss' => rtrim($CFG->wwwroot, '/'),
            'aud' => config::get_audience(),
            'sub' => pseudonym::for_user($userid),
            'course' => $courseid,
            'role' => $iseditor ? 'editor' : 'student',
            'iat' => $now,
            'exp' => $now + self::LIFETIME,
            'jti' => bin2hex(random_bytes(16)),
        ];

        $token = JWT::encode($claims, key_manager::get_private_key(), self::ALGORITHM, key_manager::get_kid());

        return ['token' => $token, 'expiresat' => $claims['exp']];
    }
}
