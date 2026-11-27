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
 * Status of the native quiz mirror for the administrator: what it holds and what went wrong.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('format_rumbos_mirrorstatus');

use format_rumbos\local\mirror\mirror_config;
use format_rumbos\local\mirror\mirror_store;

$counts = mirror_store::counts(time() - 7 * DAYSECS);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('mirrorstatus', 'format_rumbos'));
if (!mirror_config::is_enabled()) {
    echo $OUTPUT->notification(get_string('mirrorstatus_disabled', 'format_rumbos'), \core\output\notification::NOTIFY_INFO);
}

$summary = new html_table();
$summary->data = [
    [get_string('mirrorstatus_definitions', 'format_rumbos'), $counts['definitions']],
    [get_string('mirrorstatus_quizzes', 'format_rumbos'), $counts['quizzes']],
    [get_string('mirrorstatus_attempts', 'format_rumbos'), $counts['attempts']],
    [get_string('mirrorstatus_diverged', 'format_rumbos'), $counts['diverged']],
    [get_string('mirrorstatus_problems', 'format_rumbos'), $counts['problems']],
];
echo html_writer::table($summary);

echo $OUTPUT->heading(get_string('mirrorstatus_log', 'format_rumbos'), 3);
$lines = mirror_store::recent_log();
if (!$lines) {
    echo $OUTPUT->notification(get_string('mirrorstatus_empty', 'format_rumbos'), \core\output\notification::NOTIFY_INFO);
} else {
    $table = new html_table();
    $table->head = [get_string('mirrorstatus_when', 'format_rumbos'), get_string('mirrorstatus_what', 'format_rumbos')];
    foreach ($lines as $line) {
        $what = s(implode(' · ', array_filter([
            $line->kind, $line->outcome, $line->status, $line->reason, $line->formid, $line->version,
            $line->attemptid, $line->courseid ? 'course ' . $line->courseid : '', $line->detail,
        ])));
        $table->data[] = [userdate((int) $line->timecreated), $what];
    }
    echo html_writer::table($table);
}
echo $OUTPUT->footer();
