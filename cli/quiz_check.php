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
 * CLI script: why does a user's result in a quiz say what it says?
 *
 * Read only: it changes nothing, stores nothing and logs nothing. It runs as the site administrator, which is why
 * it is a command line tool: whoever can run it has the server already.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

$help = "Shows why a user's result in a quiz is what it is: whether the user may attempt the quiz, how many attempts they
have by state (previews apart), whether the grade is hidden, and the reason the Rumbos app is given.

Read only. Run it as the web server user, from the Moodle directory.

Options:
    -h --help           Print this help.
    --cmid=<id>         Course module id of the quiz (the number after id= in the quiz URL). Required.
    --username=<name>   The user to look at, by username.
    --email=<address>   The user to look at, by email address (it must match one user).
    --userid=<id>       The user to look at, by id.
    --json              Print JSON instead of text.

Example:
    php public/course/format/rumbos/cli/quiz_check.php --cmid=310 --username=jdoe
";

[$options, $unrecognised] = cli_get_params([
    'help' => false,
    'cmid' => 0,
    'username' => '',
    'email' => '',
    'userid' => 0,
    'json' => false,
], ['h' => 'help']);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognised)));
}
if ($options['help']) {
    cli_writeln($help);
    exit(0);
}

$cmid = (int) $options['cmid'];
$lookups = array_filter([
    'id' => (int) $options['userid'],
    'username' => core_text::strtolower((string) $options['username']),
    'email' => (string) $options['email'],
]);
if ($cmid <= 0 || count($lookups) !== 1) {
    cli_error("Give --cmid and exactly one of --username, --email and --userid. Use --help for the options.", 2);
}

$conditions = ['deleted' => 0, 'mnethostid' => $CFG->mnet_localhost_id] + $lookups;
$users = $DB->get_records('user', $conditions, '', 'id, username, firstname, lastname, suspended');
if (count($users) !== 1) {
    cli_error(count($users) . " users match that, expected exactly one.", 1);
}
$subject = reset($users);

$cm = $DB->get_record('course_modules', ['id' => $cmid]);
if (!$cm) {
    cli_error("There is no activity with the course module id {$cmid}.", 1);
}

$admin = get_admin();
\core\session\manager::set_user($admin);
$reply = \format_rumbos\local\quiz_result::diagnose((int) $cm->course, $cmid, (int) $subject->id, (int) $admin->id);
if ($reply['error'] !== '') {
    cli_error("The activity {$cmid} is not a quiz of the course {$cm->course} ({$reply['error']}).", 1);
}
$check = $reply['check'];

if ($options['json']) {
    $report = ['cmid' => $cmid, 'course' => (int) $cm->course, 'userid' => (int) $subject->id] + $check;
    cli_writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    exit(0);
}

$yesno = fn(bool $value): string => $value ? 'yes' : 'no';
cli_writeln("Quiz (cmid {$cmid}, course {$cm->course}) for user {$subject->id} {$subject->username}"
    . ($subject->suspended ? ' (SUSPENDED)' : ''));
cli_writeln('  Reason given to the app : ' . $check['reason']);
cli_writeln('  Enrolled, active        : ' . $yesno($check['enrolled']));
cli_writeln('  Quiz visible to them    : ' . $yesno($check['visible']) . '   may view: ' . $yesno($check['canView']));
$noattempt = $check['canAttempt'] ? '' : '   (so nothing they do counts as an attempt)';
$ispreview = $check['canPreview'] ? '   (so their attempts are previews and are not counted)' : '';
cli_writeln('  mod/quiz:attempt        : ' . $yesno($check['canAttempt']) . $noattempt);
cli_writeln('  mod/quiz:preview        : ' . $yesno($check['canPreview']) . $ispreview);
cli_writeln('  Attempts by state       : ' . implode(', ', array_map(
    fn($state, $count) => "{$state} {$count}",
    array_keys($check['counts']),
    $check['counts']
)));
cli_writeln('  Waiting for grading     : ' . $yesno($check['pendingGrading']));
cli_writeln('  Grade hidden            : ' . $yesno($check['hiddenGrade']));
cli_writeln('  Percentage withheld by  : ' . ($check['percentWithheldBy'] ?: '(not withheld)'));
cli_writeln('  What the app receives   : ' . json_encode($check['contract']['result'] ?? $check['contract']));
cli_writeln('  Capabilities are the user\'s own roles: a role switched in a browser session is not visible from here.');
