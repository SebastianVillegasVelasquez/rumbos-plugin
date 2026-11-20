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

namespace format_rumbos\admin;

use format_rumbos\local\level_chain;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/adminlib.php');

/**
 * Admin setting for the ordered chain of level courses, given as course ID numbers.
 *
 * Saving is refused while a course ID number is missing, shared by two courses, or repeated.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_levelchain extends \admin_setting_configtext {
    /**
     * Constructor.
     *
     * @param string $name Setting name including the component.
     * @param string $visiblename
     * @param string $description
     */
    public function __construct(string $name, string $visiblename, string $description) {
        parent::__construct($name, $visiblename, $description, '', PARAM_RAW_TRIMMED, 60);
    }

    /**
     * Accepts an empty chain, or a list of ID numbers that each belong to exactly one course, with no repeats.
     *
     * @param string $data
     * @return true|string True when valid, an error message otherwise.
     */
    public function validate($data) {
        $chain = level_chain::parse((string) $data);
        foreach ($chain as $idnumber) {
            if (\core_text::strlen($idnumber) > level_chain::MAX_IDNUMBER) {
                return get_string('errorlevelchaintoolong', 'format_rumbos', level_chain::MAX_IDNUMBER);
            }
        }
        $result = level_chain::validate($chain);
        if ($result['error'] === level_chain::OK) {
            return true;
        }
        return get_string('errorlevelchain' . $result['error'], 'format_rumbos', s($result['idnumber']));
    }

    /**
     * Stores the chain in one canonical form: ID numbers separated by commas.
     *
     * @param string $data
     * @return string Empty on success, an error message otherwise.
     */
    public function write_setting($data) {
        $valid = $this->validate($data);
        if ($valid !== true) {
            return $valid;
        }
        return parent::write_setting(implode(',', level_chain::parse((string) $data)));
    }
}
