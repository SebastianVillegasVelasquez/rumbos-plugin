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
 * What the level gate decided for one user in one course: whether the Rumbos content may open, and why.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class level_decision {
    /** @var string The gate is switched off: today's behaviour. */
    public const OFF = 'off';

    /** @var string The course is not in the chain, or is its first level: it has no prerequisite. */
    public const NOT_GATED = 'notgated';

    /** @var string Staff (map editors and administrators) are never blocked. */
    public const BYPASS = 'bypass';

    /** @var string An administrator recorded an exception for this user (capability on the course). */
    public const OVERRIDE = 'override';

    /** @var string The user completed the previous level in Moodle. */
    public const MET = 'met';

    /** @var string The user has not completed the previous level. */
    public const LOCKED = 'locked';

    /** @var string The check could not run; the content stays closed. */
    public const ERROR = 'error';

    /** @var string Why a locked user is locked: not enrolled in the previous level. */
    public const REASON_NOT_ENROLLED = 'notenrolled';

    /** @var string Why a locked user is locked: enrolled but not complete. */
    public const REASON_INCOMPLETE = 'incomplete';

    /** @var string One of the status constants. */
    public string $status;

    /** @var \stdClass|null The previous level's course record, when there is one. */
    public ?\stdClass $prerequisite;

    /** @var string Detail: a REASON_* constant when locked, a precise message for the log on error. */
    public string $reason;

    /**
     * Constructor.
     *
     * @param string $status One of the status constants.
     * @param \stdClass|null $prerequisite The previous level's course record, when there is one.
     * @param string $reason Detail: a REASON_* constant when locked, a log message on error.
     */
    public function __construct(string $status, ?\stdClass $prerequisite = null, string $reason = '') {
        $this->status = $status;
        $this->prerequisite = $prerequisite;
        $this->reason = $reason;
    }

    /**
     * Whether the Rumbos content may open.
     *
     * @return bool
     */
    public function is_open(): bool {
        return !in_array($this->status, [self::LOCKED, self::ERROR], true);
    }
}
