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

namespace format_rumbos\output;

use format_rumbos\local\app_url;
use format_rumbos\local\config;

/**
 * Tests for the course page rendering.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\output\courseformat\content::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\local\config::class)]
final class content_test extends \advanced_testcase {
    /**
     * Creates a Rumbos course with a student and a teacher and configures the app.
     *
     * @return array [course, student, teacher]
     */
    private function setup_course(): array {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['format' => 'rumbos']);
        $student = $gen->create_user();
        $teacher = $gen->create_user();
        $gen->enrol_user($student->id, $course->id, 'student');
        $gen->enrol_user($teacher->id, $course->id, 'editingteacher');
        set_config('enabled', 1, 'format_rumbos');
        set_config('appurl', 'https://rumbos.example.com/app', 'format_rumbos');
        return [$course, $student, $teacher];
    }

    /**
     * Renders the course page content as a user.
     *
     * @param \stdClass $course
     * @param \stdClass $user
     * @param bool $editing
     * @return \DOMXPath
     */
    private function render(\stdClass $course, \stdClass $user, bool $editing = false): \DOMXPath {
        global $PAGE, $USER;

        $this->setUser($user);
        $PAGE = new \moodle_page();
        $PAGE->set_context(\core\context\course::instance($course->id));
        $PAGE->set_course($course);
        $PAGE->set_url('/course/view.php', ['id' => $course->id]);
        $PAGE->set_pagelayout('course');
        if ($editing) {
            $USER->editing = 1;
        }
        $format = course_get_format($course);
        $class = $format->get_output_classname('content');
        $html = $PAGE->get_renderer('format_rumbos')->render(new $class($format));

        $document = new \DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>');
        libxml_clear_errors();
        return new \DOMXPath($document);
    }

    /**
     * Fetches the single iframe of the page, if any.
     *
     * @param \DOMXPath $xpath
     * @return \DOMElement|null
     */
    private function iframe(\DOMXPath $xpath): ?\DOMElement {
        $nodes = $xpath->query('//iframe[@data-region="rumbos-frame"]');
        return $nodes->length ? $nodes->item(0) : null;
    }

    /**
     * The iframe carries every attribute required by the brief.
     */
    public function test_iframe_attributes(): void {
        global $CFG;
        [$course, $student] = $this->setup_course();

        $iframe = $this->iframe($this->render($course, $student));

        $this->assertNotNull($iframe);
        $this->assertSame(
            'https://rumbos.example.com/app/embed?host=' . rawurlencode(app_url::origin($CFG->wwwroot)),
            $iframe->getAttribute('src')
        );
        $this->assertNotSame('', trim($iframe->getAttribute('title')));
        $this->assertSame('no-referrer', $iframe->getAttribute('referrerpolicy'));
        $this->assertSame('fullscreen', $iframe->getAttribute('allow'));
        $this->assertSame('eager', $iframe->getAttribute('loading'));
        $this->assertSame('allow-scripts allow-same-origin allow-forms', $iframe->getAttribute('sandbox'));
    }

    /**
     * The iframe is never given more sandbox permissions than needed.
     *
     * Forms are allowed because the app's editor is built on them; popups, modals,
     * downloads and top navigation stay blocked.
     */
    public function test_sandbox_does_not_allow_dangerous_tokens(): void {
        [$course, $student] = $this->setup_course();

        $sandbox = $this->iframe($this->render($course, $student))->getAttribute('sandbox');

        foreach (['allow-top-navigation', 'allow-popups', 'allow-modals', 'allow-downloads', 'allow-pointer-lock'] as $token) {
            $this->assertStringNotContainsString($token, $sandbox);
        }
    }

    /**
     * No URL contains secrets: the src has only the host parameter.
     */
    public function test_src_has_only_the_host_parameter(): void {
        [$course, $student] = $this->setup_course();

        $src = $this->iframe($this->render($course, $student))->getAttribute('src');

        parse_str(parse_url($src, PHP_URL_QUERY), $query);
        $this->assertSame(['host'], array_keys($query));
    }

    /**
     * Students get the standard content behind the accessible disclosure control.
     */
    public function test_students_get_the_activity_list_behind_a_disclosure(): void {
        [$course, $student] = $this->setup_course();

        $xpath = $this->render($course, $student);

        $details = $xpath->query('//details[@id="format-rumbos-list"]');
        $this->assertSame(1, $details->length);
        $summary = $xpath->query('.//summary', $details->item(0))->item(0);
        $this->assertSame(get_string('showactivitylist', 'format_rumbos'), trim($summary->textContent));
        $this->assertSame(1, $xpath->query('.//*[@data-for="course_sectionlist"]', $details->item(0))->length);
        $this->assertSame(1, $xpath->query('//a[@href="#format-rumbos-list"]')->length, 'skip link');
    }

    /**
     * Teachers in editing mode keep the core editor, expanded, below the map.
     */
    public function test_editing_teachers_get_the_core_editor_below_the_map(): void {
        [$course, , $teacher] = $this->setup_course();

        $xpath = $this->render($course, $teacher, true);

        $this->assertNotNull($this->iframe($xpath));
        $this->assertSame(0, $xpath->query('//details')->length);
        $this->assertSame(1, $xpath->query('//*[@data-for="course_sectionlist"]')->length);
        $this->assertSame(
            1,
            $xpath->query('//*[@data-region="rumbos-container"]/following::*[@data-for="course_sectionlist"]')->length
        );
    }

    /**
     * Without the master switch only the standard content renders.
     */
    public function test_disabled_renders_only_the_standard_content(): void {
        [$course, $student] = $this->setup_course();
        set_config('enabled', 0, 'format_rumbos');

        $xpath = $this->render($course, $student);

        $this->assertNull($this->iframe($xpath));
        $this->assertSame(0, $xpath->query('//details')->length);
        $this->assertSame(1, $xpath->query('//*[@data-for="course_sectionlist"]')->length);
    }

    /**
     * Without an app URL there is nothing to embed.
     */
    public function test_missing_app_url_renders_only_the_standard_content(): void {
        [$course, $student] = $this->setup_course();
        unset_config('appurl', 'format_rumbos');

        $this->assertNull($this->iframe($this->render($course, $student)));
    }

    /**
     * An app on the same origin as Moodle is refused: it would void the sandbox.
     */
    public function test_app_on_the_moodle_origin_is_refused(): void {
        global $CFG;
        [$course, $student] = $this->setup_course();
        $CFG->wwwroot = 'https://lms.example.com/moodle';
        set_config('appurl', 'https://lms.example.com/rumbos', 'format_rumbos');

        $this->assertFalse(config::is_usable());
        $this->assertNull($this->iframe($this->render($course, $student)));
    }

    /**
     * Users who are not in the course do not get the container.
     */
    public function test_non_participants_do_not_get_the_container(): void {
        [$course] = $this->setup_course();
        $outsider = $this->getDataGenerator()->create_user();

        $this->assertNull($this->iframe($this->render($course, $outsider)));
    }
}
