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
 * External functions of the Rumbos course format. AJAX only: they are not
 * added to any web service.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'format_rumbos_get_course_state' => [
        'classname' => \format_rumbos\external\get_course_state::class,
        'description' => 'Returns the course state (visibility, restrictions, completion) of the current user.',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'format_rumbos_get_activity_settings_url' => [
        'classname' => \format_rumbos\external\get_activity_settings_url::class,
        'description' => 'Returns the address of the settings page of an activity, for map editors who may manage it.',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'format_rumbos_get_assertion_preview' => [
        'classname' => \format_rumbos\external\get_assertion_preview::class,
        'description' => 'Returns the decoded header and claims of a fresh assertion, never the token or its signature.',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'moodle/site:config',
    ],
    'format_rumbos_get_launch_assertion' => [
        'classname' => \format_rumbos\external\get_launch_assertion::class,
        'description' => 'Returns a short-lived signed launch assertion for the current user.',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'format_rumbos_start_popup_session' => [
        'classname' => \format_rumbos\external\start_popup_session::class,
        'description' => 'Marks the session as having an activity popup open, so its pages can drop the site chrome.',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'format_rumbos_end_popup_session' => [
        'classname' => \format_rumbos\external\end_popup_session::class,
        'description' => 'Clears the activity popup marker of the session.',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
];
