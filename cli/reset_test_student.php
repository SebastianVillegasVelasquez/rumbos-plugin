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
 * CLI script, STAGING ONLY: put a test student's Rumbos journey back, without deleting evidence.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/completionlib.php');

use format_rumbos\local\config;
use format_rumbos\local\staging_completion;
use format_rumbos\local\test_student_reset;

$help = "STAGING ONLY. Resets one test student in one course, without deleting evidence:
  1. deletes the student's flow progress rows (the welcome flow and the closing flow);
  2. takes back the completion overrides THIS PLUGIN recorded for the student (auto-completion and the staging helper),
     by overriding those activities to incomplete. Overrides made by anybody else are left alone.
It never deletes SCORM tracks, quiz attempts, grades, results or course completion, and it cannot reset what the Rumbos
service holds (check-in and final attempts). Moodle holds only an override to complete: an activity set back to
incomplete is calculated again at its next trigger, so a SCORM with tracks can become complete again.

It refuses to run unless config.php of the site contains  \$CFG->format_rumbos_staging_tools = true;  A production
site must never have that line. It changes nothing unless --apply is given: the default is a dry run.

Options:
    -h --help          Print this help.
    --course=<id>      The course id. Required.
    --user=<id>        The Moodle id of the test student, enrolled in the course and not an editor. Required.
    --apply            Make the changes. Without it, only print what would change.

Examples:
    php public/course/format/rumbos/cli/reset_test_student.php --course=10 --user=42
    php public/course/format/rumbos/cli/reset_test_student.php --course=10 --user=42 --apply
";

[$options, $unrecognised] = cli_get_params(
    ['help' => false, 'course' => 0, 'user' => 0, 'apply' => false],
    ['h' => 'help']
);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognised)));
}
if ($options['help']) {
    cli_writeln($help);
    exit(0);
}

// The first check, before anything is read: a production site stops here.
$refusal = staging_completion::refusal();
if ($refusal !== null) {
    cli_error($refusal, 3);
}

$courseid = (int) $options['course'];
$userid = (int) $options['user'];
if ($courseid <= SITEID || !$DB->record_exists('course', ['id' => $courseid])) {
    cli_error('Give --course with the id of an existing course. Use --help for the options.', 2);
}
if ($userid <= 0) {
    cli_error('Give --user with the Moodle id of the test student. Use --help for the options.', 2);
}
$apply = (bool) $options['apply'];

// An override is the administrator's: that is who the completion records, and whom Moodle checks.
\core\session\manager::set_user(get_admin());

try {
    $report = $apply ? test_student_reset::apply($courseid, $userid) : test_student_reset::plan($courseid, $userid);
} catch (moodle_exception $e) {
    cli_error($e->getMessage(), 1);
}

$states = [
    COMPLETION_INCOMPLETE => 'incomplete',
    COMPLETION_COMPLETE => 'complete',
    COMPLETION_COMPLETE_PASS => 'complete (pass)',
    COMPLETION_COMPLETE_FAIL => 'complete (fail)',
];
$actions = [
    test_student_reset::REVERT => 'would be set to incomplete',
    test_student_reset::REVERTED => 'set to incomplete',
    test_student_reset::FAILED => 'FAILED: Moodle did not change it',
    test_student_reset::NOT_COMPLETE => 'left alone: not complete',
    test_student_reset::NOT_AN_OVERRIDE => 'left alone: no longer an override (Moodle calculated it again)',
    test_student_reset::CHANGED_SINCE => 'left alone: overridden or changed since the plugin\'s record',
    test_student_reset::GONE => 'left alone: the activity is no longer in the course',
    test_student_reset::UNTRACKED => 'left alone: the activity no longer tracks completion',
];

cli_writeln(($apply ? 'Applied' : 'Dry run') . ": reset of user $userid in course $courseid.");

cli_writeln("\nFlow progress rows " . ($apply ? 'deleted' : 'that would be deleted') . ': ' . count($report['flow']));
foreach ($report['flow'] as $row) {
    cli_writeln(sprintf(
        '  map %s  step %s  revision %d  %s  %s',
        $row->mapid,
        $row->stepkey,
        $row->revision,
        $row->method,
        gmdate('Y-m-d H:i:s \U\T\C', (int) $row->timecompleted)
    ));
}

cli_writeln("\nCompletion overrides the plugin recorded: " . count($report['overrides']));
if (!$report['readable']) {
    cli_writeln('  WARNING: no log store can be read (is "Standard log" enabled?). The plugin knows its overrides only from '
        . 'its log events, so NO override is taken back.');
}
$failed = false;
$withtracks = [];
foreach ($report['overrides'] as $item) {
    $failed = $failed || $item['action'] === test_student_reset::FAILED;
    $state = $item['state'] === null ? '-' : ($states[$item['state']] ?? $item['state']);
    cli_writeln(sprintf(
        '  cmid %d  %s %s  was %s  %s',
        $item['cmid'],
        $item['modname'],
        $item['name'],
        $state,
        $actions[$item['action']]
    ));
    if (
        $item['scormattempts'] > 0 &&
            in_array($item['action'], [test_student_reset::REVERT, test_student_reset::REVERTED], true)
    ) {
        $withtracks[] = $item['cmid'];
    }
}
if ($report['unrecorded']) {
    cli_writeln('  Overrides the plugin has no record of, left alone: cmid ' . implode(', ', $report['unrecorded'])
        . ' (made by somebody else, or while logging was off, or the log was purged).');
}

$untouched = $report['untouched'];
cli_writeln("\nNot touched:");
cli_writeln("  SCORM attempts and their tracks: {$untouched['scormattempts']}");
cli_writeln("  quiz attempts: {$untouched['quizattempts']}");
cli_writeln("  grades: {$untouched['grades']}");
cli_writeln("  verified Rumbos results: {$untouched['results']}");
cli_writeln('  course completion: ' . ($untouched['coursecomplete'] ? 'the course is complete and stays so' : 'not complete'));
cli_writeln('  what the Rumbos service holds (check-in and final attempts): not Moodle\'s.');

cli_writeln("\nWarnings:");
cli_writeln('  SCORM completion may recompute as complete if tracks exist: Moodle does not hold an override to incomplete, and '
    . 'calculates it again when the activity is next opened or reports.');
if ($withtracks) {
    cli_writeln('  This student has SCORM attempts in cmid ' . implode(', ', $withtracks)
        . ': expect these to become complete again if the tracks meet the completion rules.');
}
cli_writeln('  The same applies to other automatic completion whose rules are already met (for example "view the activity").');
if (config::is_autocomplete_enabled() && $untouched['results'] > 0) {
    cli_writeln('  Auto-completion is ON and verified results are kept: if the service delivers a passing statement again, the '
        . 'content is completed again.');
}
if ($untouched['autocompletequeued'] > 0) {
    cli_writeln("  {$untouched['autocompletequeued']} queued auto-completion retry task(s) for this student can complete the "
        . 'content again when cron runs.');
}

if (!$apply) {
    cli_writeln("\nNothing was changed. Add --apply to make the change.");
}
exit($failed ? 1 : 0);
