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
 * CLI script: set a course up to receive Rumbos results, or undo it.
 *
 * A dry run unless --apply is given: it prints the state of the course and what it would change. Nothing is
 * configured on install or upgrade; this command is the only way a course is set up.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use format_rumbos\local\result_course;
use format_rumbos\local\result_statement;

$help = "Sets a Moodle course up to record the results the Rumbos service reports, and to complete the course on a pass.

It changes nothing unless --apply is given: the default is a dry run that prints the state of the course and every
change it would make. Running it again changes nothing the second time.

What --apply leaves (see docs/RESULT-PASSBACK-AUDIT.md):
  - course completion switched on for the course;
  - the role 'rumbosresult', with only moodle/course:markcomplete, assigned to nobody;
  - the grade item 'Rumbos result' (idnumber rumbos_result, 0 to 100) holding the best percent;
  - its 'grade to pass' as a DISPLAY MIRROR of the pass rule (it decides nothing);
  - the completion criterion 'Manual completion by Rumbos result';
  - with --aggregation-any, the overall aggregation 'any criterion'.

Options:
    -h --help             Print this help.
    --course=<id>         The course id. Required.
    --dry-run             Print what would change and change nothing. This is the default.
    --apply               Make the changes.
    --pass-percent=<n>    The threshold the grade to pass mirrors, 0 to 100. Default 80.
    --pass-operator=<op>  gt (strictly more than) or gte (at least). Default gt.
    --aggregation-any     Also set the overall aggregation to 'any criterion'. Needed for 'check-in or final' when the
                          course has other criteria (for example the fallback quiz); never changed without it.
    --revert              Undo: remove the criterion, and the grade item when it holds no grades. The role, the
                          overall aggregation and every completion that already happened stay.
    --drop-grades         With --revert: delete the grade item even when it holds grades.
    --json                Print JSON instead of text.

Examples:
    php public/course/format/rumbos/cli/configure_course.php --course=10
    php public/course/format/rumbos/cli/configure_course.php --course=10 --pass-percent=80 --pass-operator=gt \
        --aggregation-any --apply
    php public/course/format/rumbos/cli/configure_course.php --course=10 --revert --apply
";

[$options, $unrecognised] = cli_get_params([
    'help' => false,
    'course' => 0,
    'dry-run' => false,
    'apply' => false,
    'pass-percent' => '80',
    'pass-operator' => 'gt',
    'aggregation-any' => false,
    'revert' => false,
    'drop-grades' => false,
    'json' => false,
], ['h' => 'help']);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognised)));
}
if ($options['help']) {
    cli_writeln($help);
    exit(0);
}

$courseid = (int) $options['course'];
if ($courseid <= SITEID || !$DB->record_exists('course', ['id' => $courseid])) {
    cli_error('Give --course with the id of an existing course. Use --help for the options.', 2);
}
if ($options['apply'] && $options['dry-run']) {
    cli_error('--apply and --dry-run exclude each other.', 2);
}
$passpercent = (string) $options['pass-percent'];
$operator = (string) $options['pass-operator'];
if (!preg_match('/^\d{1,3}(\.\d{1,2})?$/', $passpercent) || (float) $passpercent > 100) {
    cli_error('--pass-percent must be a number from 0 to 100 with at most two decimals.', 2);
}
if (!in_array($operator, result_statement::OPERATORS, true)) {
    cli_error('--pass-operator must be gt or gte.', 2);
}
$apply = (bool) $options['apply'];

$state = result_course::inspect($courseid);
try {
    $steps = $options['revert']
        ? result_course::plan_revert($courseid, (bool) $options['drop-grades'])
        : result_course::plan($courseid, $passpercent, $operator, (bool) $options['aggregation-any']);
} catch (moodle_exception $e) {
    cli_error($e->getMessage(), 1);
}

$planned = array_map(fn($step) => ['key' => $step['key'], 'text' => $step['text']], $steps);

if ($apply) {
    // The state is read again after the changes so that the report shows what is there now.
    foreach ($steps as $step) {
        $step['run']();
    }
}

if ($options['json']) {
    $report = ['mode' => $apply ? 'apply' : 'dry-run', 'before' => $state, 'changes' => $planned];
    if ($apply) {
        $report['after'] = result_course::inspect($courseid);
    }
    cli_writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    exit(0);
}

$yesno = fn(bool $value): string => $value ? 'yes' : 'no';
cli_writeln("Course {$state['course']['id']} ({$state['course']['shortname']}), format {$state['course']['format']}");
cli_writeln('State' . ($apply ? ' before the changes' : '') . ':');
cli_writeln('  Completion enabled on the site   : ' . $yesno($state['sitecompletion']));
cli_writeln('  Completion enabled in the course : ' . $yesno($state['coursecompletion']));
cli_writeln('  Overall aggregation              : ' . $state['aggregation']['overall']
    . ' (role criteria: ' . $state['aggregation']['role'] . ')');
cli_writeln('  Completion criteria              : ' . ($state['criteria'] ? '' : '(none)'));
foreach ($state['criteria'] as $criterion) {
    cli_writeln("    - [{$criterion['id']}] {$criterion['type']}: {$criterion['title']}");
}
cli_writeln('  Grade items                      : ');
foreach ($state['gradeitems'] as $item) {
    cli_writeln("    - [{$item['id']}] {$item['type']} '{$item['name']}' idnumber '{$item['idnumber']}' max {$item['grademax']}"
        . " grade to pass {$item['gradepass']}");
}
cli_writeln('  Role rumbosresult                : ' . ($state['role']['exists']
    ? "exists, markcomplete: {$state['role']['markcomplete']}, assigned to {$state['role']['assignments']} user(s)"
    : 'does not exist'));
if ($state['role']['assignments'] > 0) {
    cli_writeln('    WARNING: the role must be assigned to nobody; whoever holds it can mark the course complete.');
}
cli_writeln('  Result grade item / criterion    : ' . $yesno($state['hasresultitem']) . ' / ' . $yesno($state['hascriterion']));
cli_writeln("  Tracked students / completed     : {$state['tracked']} / {$state['completed']}");
cli_writeln('');

if (!$planned) {
    cli_writeln('Nothing to change: the course is already in the desired state.');
    exit(0);
}
cli_writeln(($apply ? 'Changes made:' : 'Would change (dry run, nothing was changed; add --apply):'));
foreach ($planned as $change) {
    cli_writeln("  - {$change['text']}");
}
if ($apply) {
    cli_writeln('');
    cli_writeln('Done. Run again without --apply to confirm that nothing is left to change.');
}
