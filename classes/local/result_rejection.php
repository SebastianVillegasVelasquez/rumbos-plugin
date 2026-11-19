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
 * A result statement that the receiver refuses, with the HTTP status and the machine readable reason it answers.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class result_rejection extends \Exception {
    /** @var int HTTP status of the answer. */
    public int $status;

    /** @var string Reason of the answer, one of the values of docs/RESULT-PASSBACK.md. */
    public string $reason;

    /**
     * Constructor.
     *
     * @param int $status HTTP status.
     * @param string $reason Short snake_case reason.
     */
    public function __construct(int $status, string $reason) {
        parent::__construct($reason);
        $this->status = $status;
        $this->reason = $reason;
    }
}
