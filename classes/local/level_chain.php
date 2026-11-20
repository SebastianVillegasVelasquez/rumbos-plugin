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
 * The ordered chain of level courses (for example A1, A2, B1, B2), identified by course ID number.
 *
 * The chain is configuration, not code: a level's prerequisite is the course just before it in the list. A course that
 * is not in the list has no prerequisite, and neither has the first one.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class level_chain {
    /** @var string Validation result: the chain is usable. */
    public const OK = '';

    /** @var string Validation result: the same ID number appears more than once (which would make a cycle). */
    public const ERROR_DUPLICATE = 'duplicate';

    /** @var string Validation result: no course has an ID number from the list. */
    public const ERROR_MISSING = 'missing';

    /** @var string Validation result: two courses share an ID number from the list. */
    public const ERROR_AMBIGUOUS = 'ambiguous';

    /** @var int Longest accepted ID number (the length of the course.idnumber column). */
    public const MAX_IDNUMBER = 100;

    /**
     * Splits the stored text into ID numbers.
     *
     * Commas, semicolons and line breaks separate them; spaces around an ID number are dropped, spaces inside one are kept.
     *
     * @param string $text
     * @return string[] In order, empty entries removed.
     */
    public static function parse(string $text): array {
        $ids = [];
        foreach (preg_split('/[,;\r\n]+/', $text) as $id) {
            $id = trim($id);
            if ($id !== '') {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * The ID number of the level that must be completed before this one, or null.
     *
     * @param string[] $chain
     * @param string $idnumber The level being opened.
     * @return string|null Null when the level is not in the chain or is its first one.
     */
    public static function predecessor(array $chain, string $idnumber): ?string {
        $position = array_search($idnumber, $chain, true);
        if ($position === false || $position === 0) {
            return null;
        }
        return $chain[$position - 1];
    }

    /**
     * Checks a chain: no duplicates, and every ID number belongs to exactly one course.
     *
     * A chain is a straight line, so a repeated ID number is the only way it could loop back on itself.
     *
     * @param string[] $chain
     * @return array{error: string, idnumber: string} The first problem found; error is {@see self::OK} when there is none.
     */
    public static function validate(array $chain): array {
        global $DB;

        $seen = [];
        foreach ($chain as $idnumber) {
            if (isset($seen[$idnumber])) {
                return ['error' => self::ERROR_DUPLICATE, 'idnumber' => $idnumber];
            }
            $seen[$idnumber] = true;
        }
        foreach ($chain as $idnumber) {
            $count = $DB->count_records_select('course', 'idnumber = :idnumber AND id <> :site', [
                'idnumber' => $idnumber,
                'site' => SITEID,
            ]);
            if ($count === 0) {
                return ['error' => self::ERROR_MISSING, 'idnumber' => $idnumber];
            }
            if ($count > 1) {
                return ['error' => self::ERROR_AMBIGUOUS, 'idnumber' => $idnumber];
            }
        }
        return ['error' => self::OK, 'idnumber' => ''];
    }
}
