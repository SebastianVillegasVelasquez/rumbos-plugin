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
 * Pseudonymous user ids sent to the Rumbos service.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pseudonym {
    /** @var int Number of hex characters kept from the HMAC. */
    private const LENGTH = 32;

    /**
     * Lowercase hex HMAC-SHA256(site secret, userid), truncated to 32 characters.
     *
     * Stable for a user on this site and unlinkable to the Moodle id without
     * the site secret.
     *
     * @param int $userid
     * @return string
     */
    public static function for_user(int $userid): string {
        return substr(hash_hmac('sha256', (string) $userid, key_manager::get_hmac_secret()), 0, self::LENGTH);
    }
}
