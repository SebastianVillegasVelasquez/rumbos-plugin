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

namespace format_rumbos\external;

use core_external\external_api;
use format_rumbos\local\popup_session;

/**
 * Tests for the functions that start and end the popup session marker.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\external\start_popup_session::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\external\end_popup_session::class)]
final class popup_session_test extends \advanced_testcase {
    /**
     * Starting binds the marker to the course of the activity, and ending clears it.
     */
    public function test_start_and_end(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['format' => 'rumbos']);
        $page = $gen->create_module('page', ['course' => $course->id]);
        $student = $gen->create_user();
        $gen->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);

        $result = external_api::clean_returnvalue(
            start_popup_session::execute_returns(),
            start_popup_session::execute((int) $page->cmid)
        );

        $this->assertSame((int) $course->id, popup_session::get()['courseid']);
        $this->assertSame(popup_session::get()['expires'], $result['expires']);

        end_popup_session::execute();
        $this->assertNull(popup_session::get());
    }

    /**
     * Activities of other formats, and activities the user cannot reach, get no marker.
     */
    public function test_start_refuses_what_the_user_cannot_open(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $topics = $gen->create_course(['format' => 'topics']);
        $other = $gen->create_module('page', ['course' => $topics->id]);
        $student = $gen->create_user();
        $gen->enrol_user($student->id, $topics->id, 'student');
        $this->setUser($student);
        try {
            start_popup_session::execute((int) $other->cmid);
            $this->fail('A course of another format must not get a marker.');
        } catch (\moodle_exception $e) {
            $this->assertNull(popup_session::get());
        }

        $rumbos = $gen->create_course(['format' => 'rumbos']);
        $page = $gen->create_module('page', ['course' => $rumbos->id]);
        try {
            start_popup_session::execute((int) $page->cmid);
            $this->fail('A user who is not enrolled must not get a marker.');
        } catch (\moodle_exception $e) {
            $this->assertNull(popup_session::get());
        }
    }
}
