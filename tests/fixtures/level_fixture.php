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

namespace format_rumbos;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->dirroot . '/completion/criteria/completion_criteria_self.php');

/**
 * Shared set-up for the level prerequisite tests: courses that are levels, completion, and a quiet error log.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait level_fixture {
    /** @var string File the error log is redirected to, so a closed check does not print into the test output. */
    private string $logfile = '';

    /**
     * Starts a test: no stored keys, the plugin enabled, and the error log redirected.
     */
    protected function start_levels(): void {
        $this->resetAfterTest();
        foreach (['privatekey', 'publickey', 'kid', 'hmacsecret'] as $name) {
            unset_config($name, 'format_rumbos');
        }
        set_config('enabled', 1, 'format_rumbos');
        set_config('appurl', 'https://rumbos.example.com', 'format_rumbos');
        $this->logfile = make_request_directory() . '/errors.log';
        ini_set('error_log', $this->logfile);
    }

    /**
     * What the gate wrote to the error log.
     *
     * @return string
     */
    protected function error_log_text(): string {
        return is_file($this->logfile) ? (string) file_get_contents($this->logfile) : '';
    }

    /**
     * Creates a level: a Rumbos course with an ID number and, by default, a course completion criterion.
     *
     * @param string $idnumber
     * @param bool $criteria Whether the course can be completed at all.
     * @return \stdClass
     */
    protected function level(string $idnumber, bool $criteria = true): \stdClass {
        $course = $this->getDataGenerator()->create_course([
            'format' => 'rumbos',
            'idnumber' => $idnumber,
            'shortname' => 'L' . $idnumber,
            'fullname' => 'Level ' . $idnumber,
            'enablecompletion' => 1,
        ]);
        if ($criteria) {
            // Self completion is the simplest criterion; any criterion makes the course completable.
            $data = (object) ['id' => $course->id, 'criteria_self' => 1];
            (new \completion_criteria_self())->update_config($data);
        }
        return $course;
    }

    /**
     * Creates a user enrolled as a student in the given courses.
     *
     * @param \stdClass ...$courses
     * @return \stdClass
     */
    protected function student(\stdClass ...$courses): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        foreach ($courses as $course) {
            $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        }
        return $user;
    }

    /**
     * Marks a course complete for a user, the way an administrator does by hand.
     *
     * @param \stdClass $course
     * @param \stdClass $user
     */
    protected function complete(\stdClass $course, \stdClass $user): void {
        $completion = new \completion_completion(['course' => $course->id, 'userid' => $user->id]);
        $completion->mark_complete();
    }

    /**
     * Turns the gate on for a chain of ID numbers.
     *
     * @param string[] $chain
     */
    protected function gate_on(array $chain): void {
        set_config('levelgate', 1, 'format_rumbos');
        set_config('levelchain', implode(',', $chain), 'format_rumbos');
    }
}
