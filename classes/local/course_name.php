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

use core\context;

/**
 * The course name the host hands to the app (host-init, contract v5.6).
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_name {
    /** @var int Longest name the app receives (characters). */
    public const MAX_LENGTH = 200;

    /**
     * The course full name as the user sees it, but as plain text.
     *
     * The string filters run first (so a multi-language name shows the user's language), then every
     * tag is dropped, entities become characters and whitespace collapses. The result is text for
     * the app to render with textContent; it is never HTML.
     *
     * @param string $fullname The course full name as stored.
     * @param context $context The course context, which decides the filters.
     * @return string At most {@see self::MAX_LENGTH} characters.
     */
    public static function for_host(string $fullname, context $context): string {
        $text = format_string($fullname, true, ['context' => $context, 'escape' => false]);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        return trim(\core_text::substr($text, 0, self::MAX_LENGTH));
    }
}
