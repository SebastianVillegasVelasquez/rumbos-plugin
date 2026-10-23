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
$string['backtomap'] = 'Back to the map';
$string['containerlabel'] = 'Rumbos course map';
$string['copied'] = 'Copied';
$string['copyvalue'] = 'Copy';
$string['enabled'] = 'Enable Rumbos';
$string['enabled_desc'] = 'Master switch. When off, Rumbos course pages show only the standard Moodle content.';
$string['errorappurl'] = 'Enter a valid https:// address without credentials, query string or fragment.';
$string['errorappurlsameorigin'] = 'The Rumbos app must be served from a different origin than Moodle.';
$string['errorheightfixed'] = 'Enter a height in pixels from {$a->min} to {$a->max}, or leave it empty to use the site default.';
$string['errorheightmode'] = 'Choose one of the listed height modes.';
$string['errorheightviewport'] = 'Enter a percentage of the screen height from {$a->min} to {$a->max}, or leave it empty to use the site default.';
$string['errorintrange'] = 'Enter a whole number from {$a->min} to {$a->max}.';
$string['errorkeydecrypt'] = 'The Rumbos signing keys cannot be decrypted with this site\'s encryption key. The encryption key file (dataroot/secret) was probably lost or replaced. An administrator must reset the Rumbos keys.';
$string['errorkeygeneration'] = 'The signing key pair could not be generated. Check that the PHP OpenSSL extension is available.';
$string['errormobileheight'] = 'Enter a percentage of the screen height from {$a->min} to {$a->max}, or leave it empty to use the site default.';
$string['errornewtabmodules'] = 'Enter module names such as bigbluebuttonbn, lti separated by commas.';
$string['errornotenabled'] = 'This Rumbos action is not available: Rumbos is disabled or the app URL is not configured.';
$string['errornotrumbos'] = 'This course does not use the Rumbos format.';
$string['exitfullscreen'] = 'Exit full screen';
$string['fallback_apperror'] = 'Rumbos reported an error (code: {$a}). Students are seeing the standard course content.';
$string['fallback_error'] = 'Rumbos could not be loaded for this course because the service returned an error. Students are seeing the standard course content.';
$string['fallback_emptymap'] = 'The map has no bubbles yet. Students are seeing the standard course content.';
$string['fallback_nomap'] = 'There is no saved map yet. Turn editing on for the course and press Save. Until then students see the standard course content.';
$string['fallback_timeout'] = 'Rumbos did not respond in time. Students are seeing the standard course content. Check the app URL and the proxy or CSP settings of this site.';
$string['frametitle'] = 'Rumbos: interactive course map';
$string['heightmode'] = 'Height of the Rumbos area';
$string['heightmode_auto'] = 'Automatic: follow the proportions of the map level';
$string['heightmode_desc'] = 'Default for every course that does not choose its own. Automatic makes the area as tall as the displayed level needs for the width available, between the minimum and the maximum below. A percentage of the screen height or a fixed height in pixels give the same height to every level.';
$string['heightmode_fixed'] = 'Fixed height in pixels';
$string['heightmode_inherit'] = 'Use the site default';
$string['heightmode_viewport'] = 'Percentage of the screen height';
$string['heightvalue'] = 'Height';
$string['heightvalue_desc'] = 'For a percentage of the screen height, a number from 40 to 100. For a fixed height, pixels from 360 to 1600. Use 0 for the built-in value (75 percent, or 700 pixels). It is not used with the automatic mode.';
$string['keyreset'] = 'The signing key pair and the identifier secret were regenerated. Register the new public key in the Rumbos backend. Every user now has a new pseudonymous id.';
$string['keyrotated'] = 'A new signing key was generated. Register the new public key in the Rumbos backend; launches fail until you do.';
$string['keyunhealthy'] = 'The stored Rumbos keys cannot be decrypted with this site\'s encryption key (the key file in dataroot/secret was probably lost or replaced). Rumbos cannot sign launches until the keys are reset.';
$string['maxheight'] = 'Maximum height (pixels)';
$string['maxheight_desc'] = 'No course can make the Rumbos area taller than this on a computer screen, whatever its own settings say. From 400 to 4000.';
$string['minheight'] = 'Minimum height (pixels)';
$string['minheight_desc'] = 'No course can make the Rumbos area shorter than this on a computer screen, whatever its own settings say. From 200 to 1000. If it is above the maximum, the maximum is raised to it.';
$string['mobileheight'] = 'Height on small screens (percent)';
$string['mobileheight_desc'] = 'Percentage of the screen height the Rumbos area takes on a phone or a narrow window. Under 100, a strip of the normal page stays below it, which keeps scrolling usable. From 50 to 100.';
$string['newsection'] = 'New section';
$string['newtabmodules'] = 'Modules that open in a new tab';
$string['newtabmodules_desc'] = 'Activities open in a popup over the map. List here, separated by commas, the modules (for example bigbluebuttonbn, lti) whose activities work poorly inside a frame and must open in a new tab instead. Leave it empty to open everything in the popup.';
$string['pollmodules'] = 'Modules the popup keeps checking';
$string['pollmodules_desc'] = 'Completion is normally read when the activity popup loads a page and when it closes. A package that reports completion without loading a page (a SCORM package or an H5P activity) would only show on the map after the popup closes. List here, separated by commas, the modules whose open popup also checks the course progress every 15 seconds while the browser tab is visible. Leave it empty to turn this off. Each check reads the whole course state, so keep the list short.';
$string['pluginname'] = 'Rumbos';
$string['popupframetitle'] = 'Activity: {$a}';
$string['previewassertion'] = 'View sample assertion (unsigned)';
$string['previewexpires'] = 'Expires at (UTC)';
$string['previewissued'] = 'Issued at (UTC)';
$string['previewwarning'] = 'For diagnosis only. This is the decoded header and claims of a fresh assertion for you. The token and its signature are not shown and are not kept.';
$string['privacy:metadata:rumbos'] = 'The Rumbos service, an external system operated outside Moodle, receives the data below when a user opens a Rumbos course page. Requests to export or delete that data must be directed to the operator of the service; Moodle holds no copy of it.';
$string['privacy:metadata:rumbos:courseid'] = 'The id of the course being opened.';
$string['privacy:metadata:rumbos:ipaddress'] = 'The user\'s browser connects to the Rumbos service directly to load the embedded app, so the service also receives the network address and browser details of that request.';
$string['privacy:metadata:rumbos:role'] = 'Whether the user can edit the Rumbos map (editor) or not (student).';
$string['privacy:metadata:rumbos:userid'] = 'A pseudonymous identifier derived from the Moodle user id with a secret that is unique to this site (HMAC-SHA256). It contains no name, email address or Moodle user id.';
$string['regaudience'] = 'Audience (aud)';
$string['regaudience_note'] = 'The Rumbos service must be configured with the same audience.';
$string['regcommand'] = 'Registration command';
$string['regcommand_note'] = 'Run it where the Rumbos backend is installed and replace the site name. The flags follow the backend command line at the time of writing: verify them with --help, because they can change until it is final.';
$string['regfingerprint'] = 'Public key fingerprint (SHA-256)';
$string['regfingerprint_note'] = 'Compare it with what the service reports for the key. To compute it from the key file: openssl pkey -pubin -in moodle.pub.pem -outform DER | openssl dgst -sha256';
$string['reghostorigin'] = 'Host origin';
$string['reghostorigin_note'] = 'The origin of this site, which the service allows to embed the app.';
$string['regissuer'] = 'Issuer (iss)';
$string['regissuer_note'] = 'The Moodle wwwroot as configured, in the form the plugin signs. It can differ from the address in your browser\'s address bar, for example behind a reverse proxy. The service compares it character by character.';
$string['registration'] = 'Registration in the Rumbos service';
$string['registration_desc'] = 'Register this site in the Rumbos service with the values shown here. Everything here is read-only and none of it is secret: the private key never leaves Moodle.';
$string['regkid'] = 'Key id (kid)';
$string['regkid_note'] = 'Pass it explicitly when registering. Without it the service derives a different id from the key, and launches fail with an unknown key.';
$string['regpublickey'] = 'Public key (PEM)';
$string['resetkeys'] = 'Reset keys';
$string['resetkeys_confirm'] = 'Regenerate the signing key pair AND the secret used for pseudonymous ids? Use this only because the stored keys cannot be decrypted. Every user will get a new pseudonymous id, so the Rumbos service will treat them all as new, and the new public key must be registered in the backend.';
$string['rotatekey'] = 'Rotate key';
$string['rotatekey_confirm'] = 'Generate a new signing key pair? The current public key stops working immediately and Rumbos will reject launches from this site until the new public key is registered in the backend.';
$string['rumbos:editmap'] = 'Edit the Rumbos map';
$string['section0name'] = 'General';
$string['settingsblocked'] = 'Your browser blocked the new tab. Allow pop-ups for this site and try again.';
$string['showactivitylist'] = 'View the activity list';
$string['sizeheading'] = 'Size of the Rumbos area';
$string['sizeheading_desc'] = 'The defaults below apply to every Rumbos course. A course can choose its own mode and height in its settings; the minimum and the maximum always apply.';
$string['skipmap'] = 'Skip the Rumbos map and go to the activity list';
$string['turneditingon'] = 'Turn editing on to modify the map.';
