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

use format_rumbos\local\map_size;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/adminlib.php');

/**
 * Admin setting: a whole number inside a range, optionally 0 for "use the built-in default".
 *
 * The height of the site default also depends on the mode chosen next to it (a percentage in "viewport",
 * pixels in "fixed"). When the page submits that mode too, the number is checked against the mode's own range.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_height extends \admin_setting_configtext {
    /** @var int[] Valid range, minimum and maximum. */
    private array $range;

    /** @var bool Whether 0 is accepted and means "built-in default". */
    private bool $allowzero;

    /** @var string|null Name of the setting that holds the height mode, when this number depends on it. */
    private ?string $modesetting;

    /**
     * Constructor.
     *
     * @param string $name Setting name including the component.
     * @param string $visiblename
     * @param string $description
     * @param int $default
     * @param int[] $range Valid values, minimum and maximum.
     * @param bool $allowzero Whether 0 ("built-in default") is accepted too.
     * @param string|null $modesetting Plain name of the mode setting this number is read with.
     */
    public function __construct(
        string $name,
        string $visiblename,
        string $description,
        int $default,
        array $range,
        bool $allowzero = false,
        ?string $modesetting = null
    ) {
        $this->range = $range;
        $this->allowzero = $allowzero;
        $this->modesetting = $modesetting;
        parent::__construct($name, $visiblename, $description, $default, PARAM_INT, 6);
    }

    /**
     * Accepts a whole number in the range (or 0 when allowed), checked against the submitted mode if there is one.
     *
     * @param string $data
     * @return true|string True when valid, an error message otherwise.
     */
    public function validate($data) {
        $range = $this->range;
        if ($this->modesetting !== null) {
            $mode = optional_param('s_format_rumbos_' . $this->modesetting, '', PARAM_ALPHA);
            $range = map_size::value_range($mode) ?? $range;
        }
        $data = trim((string) $data);
        if ($this->allowzero && $data === '0') {
            return true;
        }
        if (!map_size::is_int_in($data, $range)) {
            return get_string('errorintrange', 'format_rumbos', (object) ['min' => $range[0], 'max' => $range[1]]);
        }
        return true;
    }

    /**
     * Stores the number once it has been checked.
     *
     * @param string $data
     * @return string Empty on success, an error message otherwise.
     */
    public function write_setting($data) {
        $valid = $this->validate($data);
        if ($valid !== true) {
            return $valid;
        }
        return parent::write_setting((string) (int) trim((string) $data));
    }
}
