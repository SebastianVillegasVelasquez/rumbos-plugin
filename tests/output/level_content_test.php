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

use format_rumbos\level_fixture;
use format_rumbos\local\level_message;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/level_fixture.php');

/**
 * Tests that the course page shows a message instead of the iframe while the previous level is not complete.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\output\courseformat\content::class)]
final class level_content_test extends \advanced_testcase {
    use level_fixture;

    /**
     * Renders the course page content as a user.
     *
     * @param \stdClass $course
     * @param \stdClass $user
     * @return \DOMXPath
     */
    private function render(\stdClass $course, \stdClass $user): \DOMXPath {
        global $PAGE;

        $this->setUser($user);
        $PAGE = new \moodle_page();
        $PAGE->set_context(\core\context\course::instance($course->id));
        $PAGE->set_course($course);
        $PAGE->set_url('/course/view.php', ['id' => $course->id]);
        $PAGE->set_pagelayout('course');
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
     * Counts the elements matching a region.
     *
     * @param \DOMXPath $xpath
     * @param string $region
     * @return int
     */
    private function count_region(\DOMXPath $xpath, string $region): int {
        return $xpath->query('//*[@data-region="' . $region . '"]')->length;
    }

    /**
     * A locked student gets the message and the link, and no iframe and no container.
     */
    public function test_a_locked_student_gets_a_message_and_no_iframe(): void {
        $this->start_levels();
        $a1 = $this->level('1000');
        $a2 = $this->level('1001');
        $this->gate_on(['1000', '1001']);
        $user = $this->student($a1, $a2);

        $xpath = $this->render($a2, $user);

        $this->assertSame(0, $xpath->query('//iframe')->length);
        $this->assertSame(0, $this->count_region($xpath, 'rumbos-container'));
        $this->assertSame(1, $this->count_region($xpath, 'rumbos-level-locked'));
        $text = $xpath->query('//*[@data-region="rumbos-level-locked"]')->item(0)->textContent;
        $this->assertStringContainsString('Level 1000', $text);
        $this->assertStringNotContainsString('1000 ', str_replace('Level 1000', '', $text));
        $link = $xpath->query('//*[@data-region="rumbos-level-locked"]//a');
        $this->assertSame(1, $link->length);
        $this->assertStringContainsString('/course/view.php?id=' . $a1->id, $link->item(0)->getAttribute('href'));
    }

    /**
     * A student who cannot open the previous course gets no link to it.
     */
    public function test_no_link_when_the_previous_course_is_not_reachable(): void {
        $this->start_levels();
        $a1 = $this->level('1000');
        $a2 = $this->level('1001');
        $this->gate_on(['1000', '1001']);
        $user = $this->student($a2);

        $xpath = $this->render($a2, $user);

        $this->assertSame(1, $this->count_region($xpath, 'rumbos-level-locked'));
        $this->assertSame(0, $xpath->query('//*[@data-region="rumbos-level-locked"]//a')->length);
        $this->assertSame(0, $xpath->query('//iframe')->length);
        $this->assertNotNull($a1);
    }

    /**
     * A check that cannot run shows the retry message, with a way to retry and no reason.
     */
    public function test_a_failed_check_shows_the_retry_message(): void {
        $this->start_levels();
        $a2 = $this->level('1001');
        $this->gate_on(['1000', '1001']);
        $user = $this->student($a2);

        $xpath = $this->render($a2, $user);

        $this->assertSame(0, $xpath->query('//iframe')->length);
        $this->assertSame(1, $this->count_region($xpath, 'rumbos-level-error'));
        $text = $xpath->query('//*[@data-region="rumbos-level-error"]')->item(0)->textContent;
        $this->assertStringContainsString('We could not check your progress', $text);
        $this->assertStringNotContainsString('1000', $text);
        $retry = $xpath->query('//*[@data-region="rumbos-level-error"]//a');
        $this->assertSame(1, $retry->length);
        $this->assertStringContainsString('/course/view.php?id=' . $a2->id, $retry->item(0)->getAttribute('href'));
    }

    /**
     * A student who completed the previous level, and a teacher, get the iframe as always.
     */
    public function test_open_users_get_the_iframe(): void {
        $this->start_levels();
        $a1 = $this->level('1000');
        $a2 = $this->level('1001');
        $this->gate_on(['1000', '1001']);
        $user = $this->student($a1, $a2);
        $this->complete($a1, $user);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $a2->id, 'editingteacher');

        foreach ([$user, $teacher] as $who) {
            $xpath = $this->render($a2, $who);
            $this->assertSame(1, $xpath->query('//iframe[@data-region="rumbos-frame"]')->length);
            $this->assertSame(0, $this->count_region($xpath, 'rumbos-level-locked'));
            $this->assertSame(0, $this->count_region($xpath, 'rumbos-level-error'));
        }
    }

    /**
     * With the switch off the page is what it always was.
     */
    public function test_switch_off_changes_nothing(): void {
        $this->start_levels();
        $a1 = $this->level('1000');
        $a2 = $this->level('1001');
        $this->gate_on(['1000', '1001']);
        set_config('levelgate', 0, 'format_rumbos');
        $user = $this->student($a1, $a2);

        $xpath = $this->render($a2, $user);

        $this->assertSame(1, $xpath->query('//iframe[@data-region="rumbos-frame"]')->length);
        $this->assertSame(0, $this->count_region($xpath, 'rumbos-level-locked'));
    }

    /**
     * The default text is Variant 1: complete the previous level, and it names that level.
     */
    public function test_default_text_names_the_previous_level(): void {
        $this->start_levels();
        $a1 = $this->level('1000');
        $a2 = $this->level('1001');
        $this->gate_on(['1000', '1001']);
        $user = $this->student($a1, $a2);

        $xpath = $this->render($a2, $user);

        $text = $xpath->query('//*[@data-region="rumbos-level-locked"]//p')->item(0)->textContent;
        $this->assertSame('You need to complete the previous level (Level 1000) to open this level.', trim($text));
    }

    /**
     * An administrator's text replaces the default, with the level names filled in.
     */
    public function test_the_override_replaces_the_default(): void {
        $this->start_levels();
        $a1 = $this->level('1000');
        $a2 = $this->level('1001');
        $this->gate_on(['1000', '1001']);
        set_config(level_message::SETTING, 'Primero termina {previous}, luego abres {level}.', 'format_rumbos');
        $user = $this->student($a1, $a2);

        $xpath = $this->render($a2, $user);

        $text = $xpath->query('//*[@data-region="rumbos-level-locked"]//p')->item(0)->textContent;
        $this->assertSame('Primero termina Level 1000, luego abres Level 1001.', trim($text));
        $this->assertSame(1, $xpath->query('//*[@data-region="rumbos-level-locked"]//a')->length);
    }

    /**
     * Markup in the saved text or in a course name never becomes markup on the page.
     */
    public function test_markup_is_escaped(): void {
        global $DB;

        $this->start_levels();
        $a1 = $this->level('1000');
        $a2 = $this->level('1001');
        $this->gate_on(['1000', '1001']);
        $DB->set_field('course', 'fullname', 'Nivel <b>A1</b> & Co', ['id' => $a1->id]);
        // The setting refuses markup, but the value could have been written some other way.
        set_config(level_message::SETTING, '<script>alert(1)</script> Completa {previous}', 'format_rumbos');
        $user = $this->student($a1, $a2);

        $xpath = $this->render($a2, $user);

        $box = '//*[@data-region="rumbos-level-locked"]';
        $this->assertSame(0, $xpath->query('//script')->length);
        $this->assertSame(0, $xpath->query($box . '//b')->length);
        $text = $xpath->query($box . '//p')->item(0)->textContent;
        $this->assertStringContainsString('<script>alert(1)</script>', $text);
        // Moodle strips the tags of a course name; what is left is shown as text.
        $this->assertStringContainsString('Completa Nivel A1 & Co', $text);
    }

    /**
     * Setting an override changes nothing for an open student, a teacher or a switched-off gate.
     */
    public function test_the_override_does_not_change_who_is_blocked(): void {
        $this->start_levels();
        $a1 = $this->level('1000');
        $a2 = $this->level('1001');
        $this->gate_on(['1000', '1001']);
        set_config(level_message::SETTING, 'Custom text', 'format_rumbos');
        $user = $this->student($a1, $a2);
        $this->complete($a1, $user);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $a2->id, 'editingteacher');

        foreach ([$user, $teacher] as $who) {
            $xpath = $this->render($a2, $who);
            $this->assertSame(1, $xpath->query('//iframe[@data-region="rumbos-frame"]')->length);
            $this->assertSame(0, $this->count_region($xpath, 'rumbos-level-locked'));
        }

        set_config('levelgate', 0, 'format_rumbos');
        $locked = $this->student($a1, $a2);
        $xpath = $this->render($a2, $locked);
        $this->assertSame(1, $xpath->query('//iframe[@data-region="rumbos-frame"]')->length);
        $this->assertSame(0, $this->count_region($xpath, 'rumbos-level-locked'));
    }

    /**
     * The check that could not run keeps its own message, whatever the override says.
     */
    public function test_the_override_does_not_replace_the_retry_message(): void {
        $this->start_levels();
        $a2 = $this->level('1001');
        $this->gate_on(['1000', '1001']);
        set_config(level_message::SETTING, 'Custom text', 'format_rumbos');
        $user = $this->student($a2);

        $xpath = $this->render($a2, $user);

        $this->assertSame(1, $this->count_region($xpath, 'rumbos-level-error'));
        $text = $xpath->query('//*[@data-region="rumbos-level-error"]')->item(0)->textContent;
        $this->assertStringNotContainsString('Custom text', $text);
    }
}
