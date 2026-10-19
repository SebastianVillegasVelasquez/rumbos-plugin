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
 * Strings for the Rumbos course format, language 'en'.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['appurl'] = 'Rumbos app URL';
$string['appurl_desc'] = 'HTTPS address of the Rumbos app, for example https://rumbos.example.com. The course page embeds its /embed page in an iframe. Query strings and credentials are not accepted.';
$string['audience'] = 'Audience';
$string['audience_desc'] = 'Value of the aud claim in launch assertions. The Rumbos backend must be configured with the same value.';
$string['containerlabel'] = 'Rumbos course map';
$string['copykey'] = 'Copy public key';
$string['enabled'] = 'Enable Rumbos';
$string['enabled_desc'] = 'Master switch. When off, Rumbos course pages show only the standard Moodle content.';
$string['errorappurl'] = 'Enter a valid https:// address without credentials, query string or fragment.';
$string['errorappurlsameorigin'] = 'The Rumbos app must be served from a different origin than Moodle.';
$string['errorkeygeneration'] = 'The signing key pair could not be generated. Check that the PHP OpenSSL extension is available.';
$string['errornotenabled'] = 'This Rumbos action is not available: Rumbos is disabled or the app URL is not configured.';
$string['errornotrumbos'] = 'This course does not use the Rumbos format.';
$string['exitfullscreen'] = 'Exit full screen';
$string['fallback_error'] = 'Rumbos could not be loaded for this course because the service returned an error. Students are seeing the standard course content.';
$string['fallback_timeout'] = 'Rumbos did not respond in time. Students are seeing the standard course content. Check the app URL and the proxy or CSP settings of this site.';
$string['frametitle'] = 'Rumbos: interactive course map';
$string['keycopied'] = 'Public key copied';
$string['keyrotated'] = 'A new signing key was generated. Register the new public key in the Rumbos backend; launches fail until you do.';
$string['kid'] = 'Key ID';
$string['newsection'] = 'New section';
$string['pluginname'] = 'Rumbos';
$string['privacy:metadata'] = 'The Rumbos course format plugin does not store any personal data.';
$string['publickey'] = 'Public key';
$string['publickey_desc'] = 'Register this key in the Rumbos backend to trust launch assertions from this site. It is safe to share; the private key never leaves Moodle.';
$string['rotatekey'] = 'Rotate key';
$string['rotatekey_confirm'] = 'Generate a new signing key pair? The current public key stops working immediately and Rumbos will reject launches from this site until the new public key is registered in the backend.';
$string['rumbos:editmap'] = 'Edit the Rumbos map';
$string['section0name'] = 'General';
$string['showactivitylist'] = 'View the activity list';
$string['skipmap'] = 'Skip the Rumbos map and go to the activity list';
