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
 * CLI script: the JWK of the Rumbos service's public key, as the "Result keys" setting takes it.
 *
 * It reads a public key and prints it. It changes nothing.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use format_rumbos\local\result_config;

$help = "Prints the JWK of an EC P-256 PUBLIC key, to paste into the 'Result keys' setting. It changes nothing.

The kid must be the one the Rumbos service signs with (its PASSBACK_KEY_ID).

Options:
    -h --help          Print this help.
    --kid=<id>         The key id. Required.
    --file=<path>      The PEM file with the public key. Default: read it from standard input.

Make the public key where the private key is, so that the private key never leaves:
    openssl pkey -in passback-private.pem -pubout -out passback-public.pem

Example:
    php public/course/format/rumbos/cli/ec_public_jwk.php --kid=passback-1 --file=/tmp/passback-public.pem
";

[$options, $unrecognised] = cli_get_params(['help' => false, 'kid' => '', 'file' => ''], ['h' => 'help']);
if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognised)), 2);
}
if ($options['help']) {
    cli_writeln($help);
    exit(0);
}

$pem = $options['file'] !== '' ? @file_get_contents($options['file']) : stream_get_contents(STDIN);
$jwk = is_string($pem) ? result_config::jwk_from_pem($pem, (string) $options['kid']) : null;
if ($jwk === null) {
    cli_error('Nothing printed: give --kid (1 to 32 letters, digits, dots, dashes or underscores) and an EC P-256 '
        . 'PUBLIC key in PEM. A private key is refused.', 1);
}
cli_writeln(json_encode($jwk, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
