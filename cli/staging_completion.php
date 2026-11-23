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
 * CLI script, STAGING ONLY: mark a test student's SCORM activities complete or incomplete.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/completionlib.php');

use format_rumbos\local\staging_completion;

$help = "STAGING ONLY. Marks the tracked SCORM activities of one test student complete or incomplete, so the final-exam
gate of the Rumbos service can be tried end to end. It uses the completion override of Moodle itself (what a teacher
does with 'Override' in the activity completion report): the change is recorded with the administrator as the one who
overrode it, and Moodle logs it.

It refuses to run unless config.php of the site contains  \$CFG->format_rumbos_staging_tools = true;  A production
site must never have that line. It changes nothing unless --apply is given: the default is a dry run.

Options:
    -h --help          Print this help.
    --course=<id>      The course id. Required.
    --user=<id>        The Moodle id of the test student, enrolled in the course and not an editor. Required.
    --state=<state>    complete or incomplete. Required.
    --module=<name>    The module to mark. Default scorm.
    --apply            Make the changes. Without it, only print what would change.

Examples:
    php public/course/format/rumbos/cli/staging_completion.php --course=10 --user=42 --state=complete
    php public/course/format/rumbos/cli/staging_completion.php --course=10 --user=42 --state=incomplete --apply
";

[$options, $unrecognised] = cli_get_params([
    'help' => false,
    'course' => 0,
    'user' => 0,
    'state' => '',
    'module' => staging_completion::DEFAULT_MODULE,
    'apply' => false,
], ['h' => 'help']);

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
if (!in_array($options['state'], ['complete', 'incomplete'], true)) {
    cli_error('Give --state=complete or --state=incomplete.', 2);
}
if (!preg_match('/^[a-z][a-z0-9_]{0,19}$/', (string) $options['module'])) {
    cli_error('--module must be a module name such as scorm.', 2);
}
$complete = $options['state'] === 'complete';
$apply = (bool) $options['apply'];

// The override is the administrator's: that is who the completion records, and whom Moodle checks.
\core\session\manager::set_user(get_admin());

try {
    $items = $apply
        ? staging_completion::apply($courseid, $userid, $complete, $options['module'])
        : staging_completion::plan($courseid, $userid, $options['module']);
} catch (moodle_exception $e) {
    cli_error($e->getMessage(), 1);
}

$names = [
    COMPLETION_INCOMPLETE => 'incomplete',
    COMPLETION_COMPLETE => 'complete',
    COMPLETION_COMPLETE_PASS => 'complete (pass)',
    COMPLETION_COMPLETE_FAIL => 'complete (fail)',
];
cli_writeln(($apply ? 'Applied' : 'Dry run') . ": mark {$options['module']} activities of user $userid in course $courseid "
    . $options['state'] . '.');
if (!$items) {
    cli_writeln("The course has no {$options['module']} activity that tracks completion: nothing to do.");
}
foreach ($items as $item) {
    cli_writeln(sprintf(
        '  cmid %d  %s%s  state %s',
        $item['cmid'],
        $item['name'],
        $item['visible'] ? '' : ' (hidden)',
        $names[$item['state']] ?? $item['state']
    ));
}
if (!$apply) {
    cli_writeln('Nothing was changed. Add --apply to make the change.');
}
