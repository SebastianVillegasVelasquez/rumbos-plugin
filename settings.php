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
use format_rumbos\admin\setting_height;
use format_rumbos\admin\setting_newtabmodules;
use format_rumbos\admin\setting_registration;
use format_rumbos\local\map_size;
use format_rumbos\local\popup;

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

    $settings->add(new setting_newtabmodules(
        'format_rumbos/newtabmodules',
        get_string('newtabmodules', 'format_rumbos'),
        get_string('newtabmodules_desc', 'format_rumbos')
    ));

    $settings->add(new setting_newtabmodules(
        'format_rumbos/pollmodules',
        get_string('pollmodules', 'format_rumbos'),
        get_string('pollmodules_desc', 'format_rumbos'),
        popup::DEFAULT_POLL_MODULES
    ));

    $settings->add(new admin_setting_heading(
        'format_rumbos/sizeheading',
        get_string('sizeheading', 'format_rumbos'),
        get_string('sizeheading_desc', 'format_rumbos')
    ));

    $settings->add(new admin_setting_configselect(
        'format_rumbos/heightmode',
        new lang_string('heightmode', 'format_rumbos'),
        new lang_string('heightmode_desc', 'format_rumbos'),
        map_size::MODE_AUTO,
        [
            map_size::MODE_AUTO => get_string('heightmode_auto', 'format_rumbos'),
            map_size::MODE_VIEWPORT => get_string('heightmode_viewport', 'format_rumbos'),
            map_size::MODE_FIXED => get_string('heightmode_fixed', 'format_rumbos'),
        ]
    ));

    $setting = new setting_height(
        'format_rumbos/heightvalue',
        get_string('heightvalue', 'format_rumbos'),
        get_string('heightvalue_desc', 'format_rumbos'),
        0,
        map_size::VIEWPORT_RANGE,
        true,
        'heightmode'
    );
    $settings->add($setting);
    $settings->hide_if('format_rumbos/heightvalue', 'format_rumbos/heightmode', 'eq', map_size::MODE_AUTO);

    $settings->add(new setting_height(
        'format_rumbos/mobileheight',
        get_string('mobileheight', 'format_rumbos'),
        get_string('mobileheight_desc', 'format_rumbos'),
        map_size::DEFAULT_MOBILE,
        map_size::MOBILE_RANGE
    ));

    $settings->add(new setting_height(
        'format_rumbos/minheight',
        get_string('minheight', 'format_rumbos'),
        get_string('minheight_desc', 'format_rumbos'),
        map_size::DEFAULT_MIN,
        map_size::SITE_MIN_RANGE
    ));

    $settings->add(new setting_height(
        'format_rumbos/maxheight',
        get_string('maxheight', 'format_rumbos'),
        get_string('maxheight_desc', 'format_rumbos'),
        map_size::DEFAULT_MAX,
        map_size::SITE_MAX_RANGE
    ));

    $settings->add(new setting_registration(
        'format_rumbos/registration',
        get_string('registration', 'format_rumbos'),
        get_string('registration_desc', 'format_rumbos')
    ));
}
