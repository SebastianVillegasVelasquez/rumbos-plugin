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

use format_rumbos\local\popup;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/adminlib.php');

/**
 * Admin setting: a list of module names (the modules that open in a new tab, the modules the popup polls for).
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_newtabmodules extends \admin_setting_configtext {
    /**
     * Constructor.
     *
     * @param string $name Setting name including the component.
     * @param string $visiblename
     * @param string $description
     * @param string $default Module names the setting holds until the admin changes it.
     */
    public function __construct(
        string $name,
        string $visiblename,
        string $description,
        string $default = popup::DEFAULT_NEWTAB_MODULES
    ) {
        parent::__construct($name, $visiblename, $description, $default, PARAM_RAW, 50);
    }

    /**
     * Accepts an empty list (everything opens in the popup) or module names separated by commas.
     *
     * @param string $data
     * @return true|string True when valid, an error message otherwise.
     */
    public function validate($data) {
        return popup::parse_modules((string) $data) === null ? get_string('errornewtabmodules', 'format_rumbos') : true;
    }

    /**
     * Stores the clean, comma separated list.
     *
     * @param string $data
     * @return string Empty on success, an error message otherwise.
     */
    public function write_setting($data) {
        $valid = $this->validate($data);
        if ($valid !== true) {
            return $valid;
        }
        return parent::write_setting(implode(',', popup::parse_modules((string) $data)));
    }
}
