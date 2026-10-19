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

/**
 * Confirmation page for rotating the Rumbos signing key.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('format_rumbos_rotatekey');

$settingsurl = new moodle_url('/admin/settings.php', ['section' => 'formatsettingrumbos']);

if (optional_param('confirm', 0, PARAM_BOOL)) {
    require_sesskey();
    \format_rumbos\local\key_manager::rotate();
    redirect($settingsurl, get_string('keyrotated', 'format_rumbos'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('rotatekey', 'format_rumbos'));
echo $OUTPUT->confirm(
    get_string('rotatekey_confirm', 'format_rumbos'),
    new single_button(
        new moodle_url($PAGE->url, ['confirm' => 1]),
        get_string('rotatekey', 'format_rumbos'),
        'post',
        single_button::BUTTON_DANGER
    ),
    $settingsurl
);
echo $OUTPUT->footer();
