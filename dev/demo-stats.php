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
 * DEVELOPMENT ONLY. Fills a course of a local Moodle with demo students and completion states, to try the
 * teacher heat map.
 *
 * It creates N students (default 40) who cannot log in, puts most of them in two groups ("Demo A" and "Demo B"),
 * enrols them in the course and gives every activity with a completion condition a varied mix of states. It refuses
 * to run unless $CFG->wwwroot is a localhost address, and running it again changes nothing: users, groups,
 * enrolments and states are found by name and are the same every time. The folder is left out of the release ZIP.
 *
 *   mdc exec -T -u www-data webserver php public/course/format/rumbos/dev/demo-stats.php --courseid=4 --students=40
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/group/lib.php');

/**
 * Whether a site address points at the machine running the script.
 *
 * @param string $wwwroot
 * @return bool
 */
function format_rumbos_demo_is_local(string $wwwroot): bool {
    $host = strtolower((string) parse_url($wwwroot, PHP_URL_HOST));
    return in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true) || str_ends_with($host, '.localhost');
}

[$options, $unrecognised] = cli_get_params(
    ['courseid' => 0, 'students' => 40, 'help' => false],
    ['h' => 'help']
);
if ($unrecognised) {
    cli_error('Unknown option: ' . implode(' ', $unrecognised));
}
if ($options['help'] || !$options['courseid']) {
    cli_writeln("Usage: php demo-stats.php --courseid=ID [--students=40]\n"
        . "Development only: creates demo students, two groups and varied completion states in a LOCAL Moodle.");
    exit($options['help'] ? 0 : 1);
}
if (!format_rumbos_demo_is_local($CFG->wwwroot)) {
    cli_error('Refusing to run: wwwroot is ' . $CFG->wwwroot . ', which is not a localhost address. '
        . 'This script is for the local dev site only.');
}

$students = max(1, min(500, (int) $options['students']));
$course = get_course((int) $options['courseid']);
$context = context_course::instance($course->id);
$studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
$manual = enrol_get_plugin('manual');
$instance = null;
foreach (enrol_get_instances($course->id, true) as $candidate) {
    if ($candidate->enrol === 'manual') {
        $instance = $candidate;
    }
}
if (!$manual || !$instance) {
    cli_error('The course has no enabled manual enrolment method.');
}

$groups = [];
foreach (['Demo A', 'Demo B'] as $name) {
    $id = groups_get_group_by_name($course->id, $name);
    $groups[] = $id ?: groups_create_group((object) ['courseid' => $course->id, 'name' => $name]);
}

$created = 0;
$userids = [];
for ($i = 1; $i <= $students; $i++) {
    $username = sprintf('rumbosdemo%03d', $i);
    $user = $DB->get_record('user', ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id]);
    if (!$user) {
        $userid = user_create_user([
            'username' => $username,
            'firstname' => 'Demo',
            'lastname' => sprintf('Student %03d', $i),
            'email' => $username . '@example.invalid',
            'auth' => 'nologin',
            'confirmed' => 1,
            'mnethostid' => $CFG->mnet_localhost_id,
            'password' => random_string(24),
        ], false, false);
        $created++;
    } else {
        $userid = (int) $user->id;
    }
    $userids[$i] = $userid;
    $manual->enrol_user($instance, $userid, $studentrole->id, 0, 0, ENROL_USER_ACTIVE);
    // Nine in ten are in a group, alternating; the rest are in none.
    if ($i % 10 !== 0) {
        groups_add_member($groups[$i % 2], $userid);
    }
}

$modinfo = get_fast_modinfo($course);
$activities = 0;
$skipped = 0;
foreach ($modinfo->get_cms() as $cm) {
    if ($cm->deletioninprogress) {
        continue;
    }
    if ($cm->completion == COMPLETION_TRACKING_NONE) {
        $skipped++;
        continue;
    }
    $activities++;
    foreach ($userids as $userid) {
        // The same state for the same student and activity on every run: 35% complete, 10% passed, 7% failed,
        // 28% started and not finished, 20% without a row at all.
        $roll = crc32($userid . ':' . $cm->id) % 100;
        $state = match (true) {
            $roll < 35 => COMPLETION_COMPLETE,
            $roll < 45 => COMPLETION_COMPLETE_PASS,
            $roll < 52 => COMPLETION_COMPLETE_FAIL,
            $roll < 80 => COMPLETION_INCOMPLETE,
            default => null,
        };
        if ($state === null) {
            continue;
        }
        $existing = $DB->get_record('course_modules_completion', ['coursemoduleid' => $cm->id, 'userid' => $userid]);
        if (!$existing) {
            $DB->insert_record('course_modules_completion', (object) [
                'coursemoduleid' => $cm->id,
                'userid' => $userid,
                'completionstate' => $state,
                'viewed' => 0,
                'timemodified' => time(),
            ]);
        } else if ((int) $existing->completionstate !== $state) {
            $DB->set_field('course_modules_completion', 'completionstate', $state, ['id' => $existing->id]);
        }
    }
}

// Show the new numbers at once instead of after the cache expires.
cache_helper::purge_by_definition('format_rumbos', 'coursestats');

cli_writeln(sprintf(
    '%s: %d demo students (%d new) in "%s", groups Demo A / Demo B, '
        . 'states set on %d activities (%d without a completion condition skipped).',
    $CFG->wwwroot,
    $students,
    $created,
    $course->shortname,
    $activities,
    $skipped
));
