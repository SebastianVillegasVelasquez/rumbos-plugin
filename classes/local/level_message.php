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
 * The text a student reads when the previous level is not complete.
 *
 * The default is a language string. An administrator can replace it with plain text in the plugin settings; the
 * replacement may use {previous} and {level} for the course names. The result is plain text: whoever shows it escapes it.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class level_message {
    /** @var string The setting that holds the replacement text. */
    public const SETTING = 'levellockedtext';

    /** @var int Longest replacement text, in characters. */
    public const MAX_LENGTH = 500;

    /**
     * The replacement text an administrator saved, or an empty string when the default applies.
     *
     * @return string
     */
    public static function override(): string {
        return trim((string) get_config('format_rumbos', self::SETTING));
    }

    /**
     * Whether a text is acceptable as the replacement: plain text of a bounded length.
     *
     * @param string $text
     * @return bool
     */
    public static function is_valid(string $text): bool {
        $text = trim($text);
        return \core_text::strlen($text) <= self::MAX_LENGTH && $text === strip_tags($text);
    }

    /**
     * The message for a locked student.
     *
     * @param string $previous Name of the previous level, as plain text.
     * @param string $level Name of the level the student tried to open, as plain text.
     * @return string Plain text, not escaped.
     */
    public static function locked_text(string $previous, string $level): string {
        $override = self::override();
        if ($override === '') {
            return get_string('levellocked_body', 'format_rumbos', (object) ['previous' => $previous, 'level' => $level]);
        }
        return strtr($override, ['{previous}' => $previous, '{level}' => $level]);
    }
}
