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

use format_rumbos\local\level_message;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/adminlib.php');

/**
 * Admin setting for the text shown to a student whose previous level is not complete: empty (the default text) or plain text.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_lockedmessage extends \admin_setting_configtextarea {
    /**
     * Constructor.
     *
     * @param string $name Setting name including the component.
     * @param string $visiblename
     * @param string $description
     */
    public function __construct(string $name, string $visiblename, string $description) {
        parent::__construct($name, $visiblename, $description, '', PARAM_RAW_TRIMMED, '60', '4');
    }

    /**
     * Accepts empty (the default text) or plain text of at most the allowed length, with no markup.
     *
     * @param string $data
     * @return true|string True when valid, an error message otherwise.
     */
    public function validate($data) {
        if (!level_message::is_valid((string) $data)) {
            return get_string('errorlevellockedtext', 'format_rumbos', level_message::MAX_LENGTH);
        }
        return parent::validate($data);
    }
}
