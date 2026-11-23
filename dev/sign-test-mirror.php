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
 * Development tool: plays the Rumbos backend's part for the mirror receiver, on a LOCAL test site.
 *
 * It prints the JSON body of a signed exam definition or exam attempt built from the test fixture (shaped like the B2
 * form A; the real export contract does not exist yet), ready to POST to mirror.php. It uses the throwaway key of
 * sign-test-statement.php, so run that tool with --setup first. It refuses to run unless the site address is localhost.
 * It is not in the release ZIP (the dev folder is export-ignored).
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once(__DIR__ . '/../tests/fixtures/mirror_fixture.php');

use Firebase\JWT\JWT;
use format_rumbos\local\launch_assertion;
use format_rumbos\local\pseudonym;
use format_rumbos\local\result_config;
use format_rumbos\mirror_fixture;

$help = "Prints the JSON body of a signed mirror message for a LOCAL test site.

Options:
    -h --help           Print this help.
    --enable            Switch the mirror on (setting mirrorenabled) and print the endpoint.
    --disable           Switch the mirror off.
    --kind=<k>          definition (default) or attempt.
    --course=<id>       The course id.
    --username=<name>   The student (attempt only).
    --version=<v>       The content version of the form, default v1hash.
    --purpose=<p>       check_in (default) or final.
    --section=<list>    Points per section: listening,reading,use of English,writing,speaking. Default 6,1,3,5,5.
    --passed=<0|1>      What the service decided, default: strictly above 80 percent.
    --attempt=<id>      Default: a new one.
    --tamper            Flip a character in the middle of the signature.

Example:
    php public/course/format/rumbos/dev/sign-test-statement.php --setup
    php public/course/format/rumbos/dev/sign-test-mirror.php --enable
    php public/course/format/rumbos/dev/sign-test-mirror.php --course=2 --username=stu1 --kind=attempt --section=7,3,0,5,5
";

[$options, $unrecognised] = cli_get_params([
    'help' => false, 'enable' => false, 'disable' => false, 'kind' => 'definition', 'course' => 0, 'username' => '',
    'version' => 'v1hash', 'purpose' => 'check_in', 'section' => '6,1,3,5,5', 'passed' => '', 'attempt' => '',
    'tamper' => false,
], ['h' => 'help']);
if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognised)));
}
if ($options['help']) {
    cli_writeln($help);
    exit(0);
}
if (!in_array(parse_url($CFG->wwwroot, PHP_URL_HOST), ['localhost', '127.0.0.1'], true)) {
    cli_error('This tool only runs on a site whose address is localhost.', 1);
}
if ($options['enable'] || $options['disable']) {
    set_config('mirrorenabled', $options['enable'] ? 1 : 0, 'format_rumbos');
    cli_writeln('Mirror ' . ($options['enable'] ? 'on' : 'off') . '. Endpoint ' .
        \format_rumbos\local\mirror\mirror_config::endpoint_url());
    exit(0);
}

$keyfile = $CFG->dataroot . '/rumbos-test-signer.pem';
if (!is_readable($keyfile)) {
    cli_error('Run sign-test-statement.php with --setup first.', 1);
}
$privatepem = file_get_contents($keyfile);
$publicpem = openssl_pkey_get_details(openssl_pkey_get_private($privatepem))['key'];

$form = mirror_fixture::form($options['version']);
$form['purpose'] = $options['purpose'];
$now = time();
$claims = [
    'iss' => result_config::issuer(),
    'aud' => launch_assertion::issuer(),
    'jti' => bin2hex(random_bytes(12)),
    'iat' => $now,
    'exp' => $now + 120,
    'tenant' => result_config::tenant(),
    'course_id' => (int) $options['course'],
    'exam' => $form,
];
if ($options['kind'] === 'attempt') {
    $user = $DB->get_record('user', ['username' => core_text::strtolower($options['username']), 'deleted' => 0], 'id', MUST_EXIST);
    $section = array_map('intval', explode(',', $options['section']));
    $total = mirror_fixture::total105($section);
    $passed = $options['passed'] === '' ? $total > 8400 : (bool) (int) $options['passed'];
    $attemptid = $options['attempt'] !== '' ? $options['attempt'] : bin2hex(random_bytes(12));
    $attempt = mirror_fixture::attempt(
        $form,
        $attemptid,
        mirror_fixture::item_points($section),
        mirror_fixture::display($total),
        $passed
    );
    $attempt['purpose'] = $options['purpose'];
    $claims += ['kind' => 'exam_attempt', 'sub' => pseudonym::for_user((int) $user->id), 'attempt' => $attempt];
} else {
    $claims += ['kind' => 'exam_definition'];
}
$jws = JWT::encode($claims, $privatepem, 'RS256', result_config::key_id($publicpem));
if ($options['tamper']) {
    $middle = intdiv(strlen($jws), 2);
    $jws[$middle] = $jws[$middle] === 'A' ? 'B' : 'A';
}
cli_writeln(json_encode(['statement' => $jws]));
