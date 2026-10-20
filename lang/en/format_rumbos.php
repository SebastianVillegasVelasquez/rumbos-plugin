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
$string['errorkeydecrypt'] = 'The Rumbos signing keys cannot be decrypted with this site\'s encryption key. The encryption key file (dataroot/secret) was probably lost or replaced. An administrator must reset the Rumbos keys.';
$string['errorkeygeneration'] = 'The signing key pair could not be generated. Check that the PHP OpenSSL extension is available.';
$string['errornewtabmodules'] = 'Enter module names such as bigbluebuttonbn, lti separated by commas.';
$string['errornotenabled'] = 'This Rumbos action is not available: Rumbos is disabled or the app URL is not configured.';
$string['errornotrumbos'] = 'This course does not use the Rumbos format.';
$string['exitfullscreen'] = 'Exit full screen';
$string['fallback_apperror'] = 'Rumbos reported an error (code: {$a}). Students are seeing the standard course content.';
$string['fallback_error'] = 'Rumbos could not be loaded for this course because the service returned an error. Students are seeing the standard course content.';
$string['fallback_timeout'] = 'Rumbos did not respond in time. Students are seeing the standard course content. Check the app URL and the proxy or CSP settings of this site.';
$string['frametitle'] = 'Rumbos: interactive course map';
$string['keyreset'] = 'The signing key pair and the identifier secret were regenerated. Register the new public key in the Rumbos backend. Every user now has a new pseudonymous id.';
$string['keyrotated'] = 'A new signing key was generated. Register the new public key in the Rumbos backend; launches fail until you do.';
$string['keyunhealthy'] = 'The stored Rumbos keys cannot be decrypted with this site\'s encryption key (the key file in dataroot/secret was probably lost or replaced). Rumbos cannot sign launches until the keys are reset.';
$string['newsection'] = 'New section';
$string['newtabmodules'] = 'Modules that open in a new tab';
$string['newtabmodules_desc'] = 'Activities open in a popup over the map. List here, separated by commas, the modules (for example bigbluebuttonbn, lti) whose activities work poorly inside a frame and must open in a new tab instead. Leave it empty to open everything in the popup.';
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
$string['showactivitylist'] = 'View the activity list';
$string['skipmap'] = 'Skip the Rumbos map and go to the activity list';
$string['turneditingon'] = 'Turn editing on to modify the map.';
