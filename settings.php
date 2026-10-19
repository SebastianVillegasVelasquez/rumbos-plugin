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
 * Admin settings for the Rumbos course format.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use format_rumbos\admin\setting_appurl;
use format_rumbos\admin\setting_publickey;

// The rotation confirmation page lives outside the settings page but inside the admin tree.
$ADMIN->add('formatsettings', new admin_externalpage(
    'format_rumbos_rotatekey',
    get_string('rotatekey', 'format_rumbos'),
    new moodle_url('/course/format/rumbos/rotatekey.php'),
    'moodle/site:config',
    true
));

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_configcheckbox(
        'format_rumbos/enabled',
        new lang_string('enabled', 'format_rumbos'),
        new lang_string('enabled_desc', 'format_rumbos'),
        0
    ));

    $settings->add(new setting_appurl(
        'format_rumbos/appurl',
        get_string('appurl', 'format_rumbos'),
        get_string('appurl_desc', 'format_rumbos')
    ));

    $settings->add(new admin_setting_configtext(
        'format_rumbos/audience',
        new lang_string('audience', 'format_rumbos'),
        new lang_string('audience_desc', 'format_rumbos'),
        'rumbos',
        '/^[A-Za-z0-9._:\/-]{1,128}$/'
    ));

    $settings->add(new setting_publickey(
        'format_rumbos/publickeyinfo',
        get_string('publickey', 'format_rumbos'),
        get_string('publickey_desc', 'format_rumbos')
    ));
}
