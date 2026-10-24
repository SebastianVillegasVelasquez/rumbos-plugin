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

namespace format_rumbos\local;

use core\context\course as context_course;

/**
 * Tests for the course name the host sends the app.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\local\course_name::class)]
final class course_name_test extends \advanced_testcase {
    /**
     * Creates a course and returns its context.
     *
     * @return context_course
     */
    private function course_context(): context_course {
        $this->resetAfterTest();
        return context_course::instance($this->getDataGenerator()->create_course()->id);
    }

    /**
     * Turns the multi-language filter on for the whole site.
     */
    private function enable_multilang(): void {
        global $CFG;

        $CFG->stringfilters = 'multilang';
        $CFG->filterall = true;
        filter_set_global_state('multilang', TEXTFILTER_ON);
    }

    /**
     * A plain name comes back as it is.
     */
    public function test_plain_name(): void {
        $context = $this->course_context();
        $this->assertSame('Ruta del cafe', course_name::for_host('Ruta del cafe', $context));
    }

    /**
     * The multi-language filter runs: only the text for the language of the user is left.
     */
    public function test_multilang_name_keeps_only_the_language_of_the_user(): void {
        $context = $this->course_context();
        $this->enable_multilang();
        $name = '<span lang="en" class="multilang">Coffee route</span>'
            . '<span lang="es" class="multilang">Ruta del cafe</span>';

        $result = course_name::for_host($name, $context);

        $this->assertSame('Coffee route', $result);
    }

    /**
     * Tags never reach the app; entities become the characters they stand for.
     */
    public function test_html_is_stripped(): void {
        $context = $this->course_context();
        $name = '<b>Ruta</b> <script>alert(1)</script>del <a href="https://example.com">cafe</a> &amp; mas';

        $result = course_name::for_host($name, $context);

        $this->assertSame('Ruta alert(1)del cafe & mas', $result);
        $this->assertStringNotContainsString('<', $result);
    }

    /**
     * Runs of whitespace, new lines included, collapse to one space.
     */
    public function test_whitespace_is_collapsed(): void {
        $context = $this->course_context();
        $this->assertSame('Ruta del cafe', course_name::for_host("  Ruta \n\t del   cafe ", $context));
    }

    /**
     * A name over the limit is cut at the limit, counting characters rather than bytes.
     */
    public function test_length_is_bounded(): void {
        $context = $this->course_context();

        $result = course_name::for_host(str_repeat('ñ', 500), $context);

        $this->assertSame(course_name::MAX_LENGTH, \core_text::strlen($result));
        $this->assertSame(str_repeat('ñ', course_name::MAX_LENGTH), $result);
    }

    /**
     * The limit applies to what is left after the filter and the tags are gone.
     */
    public function test_length_is_measured_after_the_markup_is_removed(): void {
        $context = $this->course_context();
        $this->enable_multilang();
        $name = '<span lang="en" class="multilang">Coffee route</span>'
            . '<span lang="es" class="multilang">' . str_repeat('b', 300) . '</span>';

        $this->assertSame('Coffee route', course_name::for_host($name, $context));
    }
}
