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

use format_rumbos\local\app_url;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/adminlib.php');

/**
 * Admin setting for the Rumbos app URL: https only, stored normalised.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_appurl extends \admin_setting_configtext {
    /**
     * Constructor.
     *
     * @param string $name Setting name including the component.
     * @param string $visiblename
     * @param string $description
     */
    public function __construct(string $name, string $visiblename, string $description) {
        parent::__construct($name, $visiblename, $description, '', PARAM_RAW_TRIMMED, 50);
    }

    /**
     * Accepts an empty value (not configured yet) or a valid https URL.
     *
     * @param string $data
     * @return true|string True when valid, an error message otherwise.
     */
    public function validate($data) {
        global $CFG;

        if (trim($data) === '') {
            return true;
        }
        $normalised = app_url::normalize($data);
        if ($normalised === null) {
            return get_string('errorappurl', 'format_rumbos');
        }
        if (app_url::is_same_origin($normalised, $CFG->wwwroot)) {
            return get_string('errorappurlsameorigin', 'format_rumbos');
        }
        return true;
    }

    /**
     * Stores the normalised URL.
     *
     * @param string $data
     * @return string Empty on success, an error message otherwise.
     */
    public function write_setting($data) {
        $data = trim($data);
        if ($data !== '') {
            $valid = $this->validate($data);
            if ($valid !== true) {
                return $valid;
            }
            $data = app_url::normalize($data);
        }
        return parent::write_setting($data);
    }
}
