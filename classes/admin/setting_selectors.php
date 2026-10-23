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
 * Admin setting: CSS selectors, one per line, from the safe subset popup::parse_selectors() accepts.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_selectors extends \admin_setting_configtextarea {
    /** @var int Most selectors the list may hold. */
    private int $maxcount;

    /**
     * Constructor.
     *
     * @param string $name Setting name including the component.
     * @param string $visiblename
     * @param string $description
     * @param int $maxcount Most selectors the list may hold.
     */
    public function __construct(string $name, string $visiblename, string $description, int $maxcount = popup::MAX_SELECTORS) {
        $this->maxcount = $maxcount;
        parent::__construct($name, $visiblename, $description, '', PARAM_RAW, '60', '6');
    }

    /**
     * Accepts an empty list or valid selectors, one per line.
     *
     * @param string $data
     * @return true|string True when valid, an error message otherwise.
     */
    public function validate($data) {
        $selectors = popup::parse_selectors((string) $data);
        if ($selectors === null || count($selectors) > $this->maxcount) {
            return get_string('errorselectors', 'format_rumbos', $this->maxcount);
        }
        return true;
    }

    /**
     * Stores the clean list, one selector per line.
     *
     * @param string $data
     * @return string Empty on success, an error message otherwise.
     */
    public function write_setting($data) {
        $valid = $this->validate($data);
        if ($valid !== true) {
            return $valid;
        }
        return parent::write_setting(implode("\n", popup::parse_selectors((string) $data)));
    }
}
