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

use format_rumbos\local\key_manager;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/adminlib.php');

/**
 * Read-only display of the public key and its kid, with a copy button and the
 * rotation link. It never writes anything.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_publickey extends \admin_setting {
    /**
     * Constructor.
     *
     * @param string $name Setting name including the component.
     * @param string $visiblename
     * @param string $description
     */
    public function __construct(string $name, string $visiblename, string $description) {
        parent::__construct($name, $visiblename, $description, '');
    }

    /**
     * Nothing is stored under this setting's own name.
     *
     * @return bool
     */
    public function get_setting() {
        return true;
    }

    /**
     * Read-only: ignores submitted data.
     *
     * @param mixed $data
     * @return string
     */
    public function write_setting($data) {
        return '';
    }

    /**
     * Renders the key, kid, copy button and rotation link.
     *
     * @param mixed $data
     * @param string $query
     * @return string
     */
    public function output_html($data, $query = '') {
        global $PAGE;

        $PAGE->requires->js_amd_inline("require(['core/copy_to_clipboard']);");

        $publickey = key_manager::get_public_key();
        $kid = key_manager::get_kid();
        $inputid = 'format_rumbos_publickey';

        $html = \html_writer::tag('p', get_string('kid', 'format_rumbos') . ': ' . \html_writer::tag('code', s($kid)));
        $html .= \html_writer::tag('textarea', s($publickey), [
            'id' => $inputid,
            'readonly' => 'readonly',
            'rows' => 9,
            'class' => 'form-control text-monospace',
            'aria-label' => get_string('publickey', 'format_rumbos'),
        ]);
        $html .= \html_writer::tag('button', get_string('copykey', 'format_rumbos'), [
            'type' => 'button',
            'class' => 'btn btn-secondary mt-2 mr-2',
            'data-action' => 'copytoclipboard',
            'data-clipboard-target' => '#' . $inputid,
            'data-clipboard-success-message' => get_string('keycopied', 'format_rumbos'),
        ]);
        $html .= \html_writer::link(
            new \moodle_url('/course/format/rumbos/rotatekey.php'),
            get_string('rotatekey', 'format_rumbos'),
            ['class' => 'btn btn-outline-danger mt-2']
        );

        return format_admin_setting($this, $this->visiblename, $html, $this->description, true, '', null, $query);
    }
}
