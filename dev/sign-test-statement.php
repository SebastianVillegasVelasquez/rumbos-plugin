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
 * Development tool: plays the Rumbos backend's part for the result receiver, on a LOCAL test site.
 *
 * It generates a throwaway key pair, configures the receiver to trust it, and prints the JSON body of a signed
 * statement, ready to POST to passback.php. It refuses to run unless the site address is localhost. It is not in
 * the release ZIP (the dev folder is export-ignored).
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use Firebase\JWT\JWT;
use format_rumbos\local\launch_assertion;
use format_rumbos\local\pseudonym;
use format_rumbos\local\result_config;

$help = "Prints the JSON body of a signed result statement for a LOCAL test site.

Options:
    -h --help           Print this help.
    --setup             Create the throwaway key and configure the receiver (issuer 'rumbos-test', tenant 'test').
    --course=<id>       The course id.
    --username=<name>   The student.
    --purpose=<p>       check_in (default) or final.
    --percent=<n.nn>    Default 90.00.
    --passed=<0|1>      Default 1.
    --operator=<op>     gt (default) or gte.
    --attempt=<id>      Default: a new one.
    --jti=<id>          Default: a new one.
    --lifetime=<s>      Seconds the statement lives, default 120.
    --age=<s>           Pretend it was signed this many seconds ago (to make an expired one).
    --tamper            Flip a character of the signature.

Example:
    php public/course/format/rumbos/dev/sign-test-statement.php --setup
    php public/course/format/rumbos/dev/sign-test-statement.php --course=2 --username=stu1 --percent=85.50
";

[$options, $unrecognised] = cli_get_params([
    'help' => false, 'setup' => false, 'course' => 0, 'username' => '', 'purpose' => 'check_in',
    'percent' => '90.00', 'passed' => '1', 'operator' => 'gt', 'attempt' => '', 'jti' => '',
    'lifetime' => 120, 'age' => 0, 'tamper' => false,
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

$keyfile = $CFG->dataroot . '/rumbos-test-signer.pem';
if ($options['setup']) {
    $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($resource, $privatepem);
    $publicpem = openssl_pkey_get_details($resource)['key'];
    file_put_contents($keyfile, $privatepem);
    set_config('resultsenabled', 1, 'format_rumbos');
    set_config('resultissuer', 'rumbos-test', 'format_rumbos');
    set_config('resulttenant', 'test', 'format_rumbos');
    set_config('resultkeys', $publicpem, 'format_rumbos');
    cli_writeln('Receiver configured. kid ' . result_config::key_id($publicpem) . ', endpoint ' . result_config::endpoint_url());
    exit(0);
}

if (!is_readable($keyfile)) {
    cli_error('Run with --setup first.', 1);
}
$user = $DB->get_record('user', ['username' => core_text::strtolower($options['username']), 'deleted' => 0], 'id', MUST_EXIST);
$privatepem = file_get_contents($keyfile);
$publicpem = openssl_pkey_get_details(openssl_pkey_get_private($privatepem))['key'];

$iat = time() - (int) $options['age'];
$claims = [
    'iss' => result_config::issuer(),
    'aud' => launch_assertion::issuer(),
    'jti' => $options['jti'] !== '' ? $options['jti'] : bin2hex(random_bytes(12)),
    'iat' => $iat,
    'exp' => $iat + (int) $options['lifetime'],
    'tenant' => result_config::tenant(),
    'sub' => pseudonym::for_user((int) $user->id),
    'course_id' => (int) $options['course'],
    'purpose' => $options['purpose'],
    'attempt_id' => $options['attempt'] !== '' ? $options['attempt'] : bin2hex(random_bytes(12)),
    'percent' => $options['percent'],
    'passed' => (bool) (int) $options['passed'],
    'policy' => ['pass_percent' => 80, 'pass_operator' => $options['operator']],
    'finished_at' => gmdate('Y-m-d\TH:i:s\Z', $iat),
];
$jws = JWT::encode($claims, $privatepem, 'RS256', result_config::key_id($publicpem));
if ($options['tamper']) {
    $last = substr($jws, -1);
    $jws = substr($jws, 0, -1) . ($last === 'A' ? 'B' : 'A');
}
cli_writeln(json_encode(['statement' => $jws]));
