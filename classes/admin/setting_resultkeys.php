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

use format_rumbos\local\result_config;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/adminlib.php');

/**
 * The public keys the result receiver trusts: one or more PEM blocks.
 *
 * It refuses to save text that has a block which is not an RSA public key of at least 2048 bits, or text outside
 * the blocks, so that a typo cannot leave the receiver silently trusting nothing.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_resultkeys extends \admin_setting_configtextarea {
    /**
     * Validates the text.
     *
     * @param string $data The submitted text.
     * @return bool|string True, or the error message.
     */
    public function validate($data) {
        $valid = parent::validate($data);
        if ($valid !== true) {
            return $valid;
        }
        $data = (string) $data;
        if (trim($data) === '') {
            return true;
        }
        $parsed = result_config::parse_keys($data);
        if ($parsed['errors']) {
            return get_string('resultkeys_error_' . $parsed['errors'][0], 'format_rumbos');
        }
        return true;
    }
}
