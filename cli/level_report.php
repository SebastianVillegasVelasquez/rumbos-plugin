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
 * CLI script: dry run of the level prerequisites.
 *
 * Read only: it changes nothing, stores nothing and does not need the switch to be on. For the current enrolments it lists,
 * level by level, who would be blocked from the Rumbos content if the switch were turned on today.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use core\context\course as context_course;
use format_rumbos\local\config;
use format_rumbos\local\level_chain;
use format_rumbos\local\level_decision;
use format_rumbos\local\level_gate;

$help = "Dry run of the level prerequisites: for the current active enrolments, who would be BLOCKED from the Rumbos content
of a level if the switch were on today, grouped by level.

Read only. It ignores the switch, changes nothing and sends nothing. Run it as the web server user, from the Moodle directory.
The output lists user ids, usernames and names: it is for the administrator who reviews it, do not paste it into a ticket.

For each level after the first it counts the users who would open it (completed the previous level; an exception
recorded with the capability format/rumbos:skiplevelcheck; staff, who are never blocked), and lists the users who would be
blocked, with the reason: 'notenrolled' (not enrolled in the previous level) or 'incomplete' (enrolled, not complete).
'unverifiable' means the check cannot run for that level (the previous level has no course completion criteria, completion is
off, or the course is missing): while the switch is on, every student of that level is shown the retry message.

Options:
    -h --help        Print this help.
    --chain=<ids>    Try another chain, for example --chain=1000,1001,1003,1002. Default: the saved setting.
    --json           Print JSON instead of text.

Exit status: 0 when the report ran, 1 when the chain is invalid.

Example:
    php public/course/format/rumbos/cli/level_report.php --chain=1000,1001,1003,1002
";

[$options, $unrecognised] = cli_get_params([
    'help' => false,
    'chain' => '',
    'json' => false,
], ['h' => 'help']);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognised)));
}
if ($options['help']) {
    cli_writeln($help);
    exit(0);
}

$chain = $options['chain'] !== '' ? level_chain::parse($options['chain']) : config::get_level_chain();
$check = level_chain::validate($chain);
if ($check['error'] !== level_chain::OK) {
    cli_error('Invalid chain: ' . get_string('errorlevelchain' . $check['error'], 'format_rumbos', $check['idnumber']));
}

$report = [
    'switch' => config::is_level_gate_enabled() ? 'on' : 'off',
    'chain' => $chain,
    'levels' => [],
];

foreach ($chain as $position => $idnumber) {
    $course = $DB->get_record_select('course', 'idnumber = :idnumber AND id <> :site', [
        'idnumber' => $idnumber,
        'site' => SITEID,
    ], 'id, shortname, fullname, idnumber', MUST_EXIST);
    $level = [
        'idnumber' => $idnumber,
        'course' => (int) $course->id,
        'shortname' => $course->shortname,
        'requires' => $position === 0 ? null : $chain[$position - 1],
        'enrolled' => 0,
        'open' => ['completed' => 0, 'exception' => 0, 'staff' => 0],
        'blocked' => [],
        'unverifiable' => 0,
        'unverifiablereason' => '',
    ];
    $context = context_course::instance($course->id);
    if ($position === 0) {
        $level['enrolled'] = count_enrolled_users($context, '', 0, true);
        $report['levels'][] = $level;
        continue;
    }

    $users = get_enrolled_users($context, '', 0, 'u.id, u.username, u.firstname, u.lastname, u.firstnamephonetic, ' .
        'u.lastnamephonetic, u.middlename, u.alternatename', 'u.id', 0, 0, true);
    $level['enrolled'] = count($users);
    foreach ($users as $user) {
        $decision = level_gate::decide((int) $course->id, (int) $user->id, $chain, false);
        switch ($decision->status) {
            case level_decision::MET:
                $level['open']['completed']++;
                break;
            case level_decision::OVERRIDE:
                $level['open']['exception']++;
                break;
            case level_decision::BYPASS:
                $level['open']['staff']++;
                break;
            case level_decision::LOCKED:
                $level['blocked'][] = [
                    'id' => (int) $user->id,
                    'username' => $user->username,
                    'name' => fullname($user),
                    'reason' => $decision->reason,
                ];
                break;
            default:
                $level['unverifiable']++;
                $level['unverifiablereason'] = $decision->reason;
        }
    }
    $report['levels'][] = $level;
}

if ($options['json']) {
    cli_writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    exit(0);
}

cli_writeln('Level prerequisites: DRY RUN (nothing was changed). The switch is currently ' . strtoupper($report['switch']) . '.');
cli_writeln('Chain: ' . implode(' > ', $chain));
foreach ($report['levels'] as $level) {
    cli_writeln('');
    cli_writeln("Level {$level['idnumber']} \"{$level['shortname']}\" (course {$level['course']})");
    if ($level['requires'] === null) {
        cli_writeln("  first level: no prerequisite, nobody is blocked; active enrolments: {$level['enrolled']}");
        continue;
    }
    $open = $level['open'];
    cli_writeln("  requires {$level['requires']}; active enrolments: {$level['enrolled']}");
    cli_writeln('  would open: ' . array_sum($open) . " (completed {$open['completed']}, exception {$open['exception']}, " .
        "staff {$open['staff']})");
    cli_writeln('  would be BLOCKED: ' . count($level['blocked']));
    foreach ($level['blocked'] as $row) {
        cli_writeln("    {$row['id']}\t{$row['username']}\t{$row['name']}\t{$row['reason']}");
    }
    if ($level['unverifiable'] > 0) {
        cli_writeln("  unverifiable: {$level['unverifiable']} ({$level['unverifiablereason']})");
    }
}
