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

use core\hook\output\before_standard_head_html_generation;

/**
 * Tests for the page hook that loads the popup layout script.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\hook_callbacks::class)]
final class hook_callbacks_test extends \advanced_testcase {
    /**
     * Runs the hook for a page of a course and says whether the popup script was requested.
     *
     * @param string $format Course format.
     * @param array $headers Values for $_SERVER.
     * @return bool
     */
    private function loads_popup_script(string $format, array $headers): bool {
        global $PAGE;

        $course = $this->getDataGenerator()->create_course(['format' => $format]);
        $PAGE = new \moodle_page();
        $PAGE->set_context(\core\context\course::instance($course->id));
        $PAGE->set_course($course);
        $PAGE->set_url('/mod/page/view.php', ['id' => 1]);
        $PAGE->set_pagetype('mod-page-view');

        $saved = $_SERVER;
        $_SERVER = array_merge($_SERVER, $headers);
        try {
            $hook = new before_standard_head_html_generation($PAGE->get_renderer('core'));
            hook_callbacks::before_standard_head_html_generation($hook);
        } finally {
            $_SERVER = $saved;
        }
        return str_contains($PAGE->requires->get_end_code(), 'format_rumbos/popup_page');
    }

    /**
     * Sets up an enabled, configured plugin.
     */
    private function configure(): void {
        $this->resetAfterTest();
        set_config('enabled', 1, 'format_rumbos');
        set_config('appurl', 'https://rumbos.example.com', 'format_rumbos');
    }

    /**
     * A same-origin iframe request on a Rumbos course gets the script.
     */
    public function test_popup_requests_load_the_script(): void {
        $this->configure();
        $headers = ['HTTP_SEC_FETCH_DEST' => 'iframe', 'HTTP_SEC_FETCH_SITE' => 'same-origin'];

        $this->assertTrue($this->loads_popup_script('rumbos', $headers));
    }

    /**
     * Anything else is served as before: a normal page load, another site's frame, no headers.
     */
    public function test_other_requests_are_left_alone(): void {
        $this->configure();

        $this->assertFalse($this->loads_popup_script('rumbos', ['HTTP_SEC_FETCH_DEST' => 'document']));
        $this->assertFalse($this->loads_popup_script(
            'rumbos',
            ['HTTP_SEC_FETCH_DEST' => 'iframe', 'HTTP_SEC_FETCH_SITE' => 'cross-site']
        ));
        $this->assertFalse($this->loads_popup_script('rumbos', []));
    }

    /**
     * Pages of courses in other formats are never touched, even when framed.
     */
    public function test_other_formats_are_left_alone(): void {
        $this->configure();
        $headers = ['HTTP_SEC_FETCH_DEST' => 'iframe', 'HTTP_SEC_FETCH_SITE' => 'same-origin'];

        $this->assertFalse($this->loads_popup_script('topics', $headers));
    }

    /**
     * With Rumbos off nothing is loaded.
     */
    public function test_nothing_is_loaded_while_rumbos_is_disabled(): void {
        $this->configure();
        set_config('enabled', 0, 'format_rumbos');
        $headers = ['HTTP_SEC_FETCH_DEST' => 'iframe', 'HTTP_SEC_FETCH_SITE' => 'same-origin'];

        $this->assertFalse($this->loads_popup_script('rumbos', $headers));
    }
}
