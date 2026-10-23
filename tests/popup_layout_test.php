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

use core\hook\output\before_http_headers;
use core\hook\output\before_standard_head_html_generation;
use format_rumbos\local\popup_session;

/**
 * Tests for what the page hooks do to a page framed by the activity popup, and that they leave every other page alone.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\hook_callbacks::class)]
final class popup_layout_test extends \advanced_testcase {
    /** @var array Headers of a request made by the popup frame. */
    private const IFRAME = ['HTTP_SEC_FETCH_DEST' => 'iframe', 'HTTP_SEC_FETCH_SITE' => 'same-origin'];

    /** @var array Headers of a top-level navigation. */
    private const TAB = ['HTTP_SEC_FETCH_DEST' => 'document', 'HTTP_SEC_FETCH_SITE' => 'same-origin'];

    /**
     * Sets up an enabled, configured plugin.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('enabled', 1, 'format_rumbos');
        set_config('appurl', 'https://rumbos.example.com', 'format_rumbos');
    }

    /**
     * Prepares $PAGE for an activity page of a new course.
     *
     * @param string $format Course format.
     * @param string $layout Layout the activity script set.
     * @param int|null $marker Seconds the session marker lasts (negative: expired); null for no marker.
     * @param int|null $markedcourse Course the marker is bound to; null for the page's own course.
     * @return \stdClass The course.
     */
    private function prepare(string $format, string $layout, ?int $marker, ?int $markedcourse = null): \stdClass {
        global $PAGE, $SESSION;

        $course = $this->getDataGenerator()->create_course(['format' => $format]);
        $PAGE = new \moodle_page();
        $PAGE->set_context(\core\context\course::instance($course->id));
        $PAGE->set_course($course);
        $PAGE->set_url('/mod/page/view.php', ['id' => 1]);
        $PAGE->set_pagetype('mod-page-view');
        $PAGE->set_pagelayout($layout);
        unset($SESSION->{popup_session::KEY});
        if ($marker !== null) {
            $SESSION->{popup_session::KEY} = [
                'courseid' => $markedcourse ?? (int) $course->id,
                'expires' => time() + $marker,
            ];
        }
        return $course;
    }

    /**
     * Runs a hook callback with the request headers set.
     *
     * @param array $headers Values for $_SERVER.
     * @param callable $run
     * @return mixed What $run returns.
     */
    private function with_headers(array $headers, callable $run): mixed {
        $saved = $_SERVER;
        $_SERVER = array_merge($_SERVER, $headers);
        try {
            return $run();
        } finally {
            $_SERVER = $saved;
        }
    }

    /**
     * Runs the headers hook on the prepared page.
     *
     * @param array $headers
     */
    private function run_headers_hook(array $headers): void {
        global $PAGE;
        $this->with_headers($headers, fn() => hook_callbacks::before_http_headers(
            new before_http_headers($PAGE->get_renderer('core'))
        ));
    }

    /**
     * Runs the head hook on the prepared page and returns the HTML it added.
     *
     * @param array $headers
     * @return string
     */
    private function run_head_hook(array $headers): string {
        global $PAGE;
        return $this->with_headers($headers, function () use ($PAGE) {
            $hook = new before_standard_head_html_generation($PAGE->get_renderer('core'));
            hook_callbacks::before_standard_head_html_generation($hook);
            return $hook->get_output();
        });
    }

    /**
     * A same-origin iframe request with a valid marker gets the embedded layout and the body class.
     */
    public function test_marked_popup_pages_get_the_embedded_layout(): void {
        global $PAGE;
        $this->prepare('rumbos', 'incourse', 300);

        $this->run_headers_hook(self::IFRAME);

        $this->assertSame('embedded', $PAGE->pagelayout);
        $this->assertStringContainsString('format-rumbos-popup-page', $PAGE->bodyclasses);
    }

    /**
     * The css mode adds the class but keeps the activity's own layout.
     */
    public function test_css_mode_keeps_the_layout(): void {
        global $PAGE;
        set_config('popupmode', 'css', 'format_rumbos');
        $this->prepare('rumbos', 'incourse', 300);

        $this->run_headers_hook(self::IFRAME);

        $this->assertSame('incourse', $PAGE->pagelayout);
        $this->assertStringContainsString('format-rumbos-popup-page', $PAGE->bodyclasses);
    }

    /**
     * Ordinary pages are never touched: no marker, an expired or foreign marker, another tab, a browser
     * without the headers (left to the page script), another format, the mode off.
     */
    public function test_ordinary_pages_are_never_touched(): void {
        global $PAGE;
        $cases = [
            'no marker' => ['rumbos', 'incourse', null, null, self::IFRAME],
            'expired marker' => ['rumbos', 'incourse', -1, null, self::IFRAME],
            'marker of another course' => ['rumbos', 'incourse', 300, 999999, self::IFRAME],
            'another tab of the same user' => ['rumbos', 'incourse', 300, null, self::TAB],
            'no headers' => ['rumbos', 'incourse', 300, null, []],
            'other format' => ['topics', 'incourse', 300, null, self::IFRAME],
        ];
        foreach ($cases as $name => [$format, $layout, $marker, $markedcourse, $headers]) {
            $this->prepare($format, $layout, $marker, $markedcourse);
            $this->run_headers_hook($headers);
            $this->assertSame($layout, $PAGE->pagelayout, $name);
            $this->assertStringNotContainsString('format-rumbos-popup-page', $PAGE->bodyclasses, $name);
        }

        // A layout that must stay keeps it, even for a popup page.
        $this->prepare('rumbos', 'secure', 300);
        $this->run_headers_hook(self::IFRAME);
        $this->assertSame('secure', $PAGE->pagelayout);

        set_config('popupmode', 'off', 'format_rumbos');
        $this->prepare('rumbos', 'incourse', 300);
        $this->run_headers_hook(self::IFRAME);
        $this->assertSame('incourse', $PAGE->pagelayout);
    }

    /**
     * A page that matches keeps the marker alive; one that does not leaves it as it was.
     */
    public function test_only_matching_requests_extend_the_marker(): void {
        global $SESSION;
        $this->prepare('rumbos', 'incourse', 10);
        $this->run_headers_hook(self::TAB);
        $this->assertLessThan(time() + 100, $SESSION->{popup_session::KEY}['expires']);

        $this->run_headers_hook(self::IFRAME);
        $this->assertGreaterThan(time() + 100, $SESSION->{popup_session::KEY}['expires']);
    }

    /**
     * The hide styles go into the head of popup pages only, scoped to the popup body class.
     */
    public function test_hide_styles_only_for_popup_pages(): void {
        set_config('popuphideselectors', '#govco-bar', 'format_rumbos');
        $rule = 'body.format-rumbos-popup-page #govco-bar{display:none!important}';

        $this->prepare('rumbos', 'incourse', 300);
        $this->assertStringContainsString($rule, $this->run_head_hook(self::IFRAME));
        $this->assertSame('', $this->run_head_hook(self::TAB));
        // No headers: the style is sent, and only applies if the page script adds the body class.
        $this->assertStringContainsString($rule, $this->run_head_hook([]));

        $this->prepare('rumbos', 'incourse', null);
        $this->assertSame('', $this->run_head_hook(self::IFRAME));

        $this->prepare('topics', 'incourse', 300);
        $this->assertSame('', $this->run_head_hook(self::IFRAME));
    }
}
