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

/**
 * Tests for the popup gating, the session marker and the selector list.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\local\popup::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\local\popup_session::class)]
final class popup_gating_test extends \advanced_testcase {
    /**
     * Lists of selectors that are accepted, and the clean list they become.
     *
     * @return array
     */
    public static function selectors_provider(): array {
        return [
            'empty' => ['', []],
            'blank lines' => ["\n  \n", []],
            'one' => ['#site-header', ['#site-header']],
            'trimmed and deduplicated' => ["  .govco-bar \n#a11y > button\n.govco-bar", ['.govco-bar', '#a11y > button']],
            'attribute and pseudo' => ['div[data-region="x"]:not(.keep)', ['div[data-region="x"]:not(.keep)']],
        ];
    }

    /**
     * Valid lists are normalised.
     *
     * @param string $value
     * @param array $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('selectors_provider')]
    public function test_parse_selectors(string $value, array $expected): void {
        $this->assertSame($expected, popup::parse_selectors($value));
    }

    /**
     * Anything that could close a rule, load something or break the stylesheet makes the list invalid.
     */
    public function test_parse_selectors_rejects_unsafe_input(): void {
        $bad = [
            'a{color:red}', 'a}', '@import "x"', 'a:not(url(x))', 'a, b', '/* c */ a', '</style><script>',
            'a;b', 'a\\62 b', 'div[x="1]', "a'b", '(a', 'a)', 'a[b', str_repeat('a', popup::MAX_SELECTOR_LENGTH + 1),
        ];
        foreach ($bad as $value) {
            $this->assertNull(popup::parse_selectors($value), $value);
        }
        $lines = fn(int $count) => implode("\n", array_map(fn($n) => ".s{$n}", range(1, $count)));
        $this->assertNull(popup::parse_selectors($lines(popup::MAX_SELECTORS + 1)));
        $this->assertCount(popup::MAX_SELECTORS, popup::parse_selectors($lines(popup::MAX_SELECTORS)));
    }

    /**
     * Each selector becomes its own rule, scoped to the popup body class.
     */
    public function test_hide_css_scopes_every_selector(): void {
        $this->resetAfterTest();
        $this->assertSame('', popup::hide_css());

        set_config('popuphideselectors', "#a\n.b > i", 'format_rumbos');
        $this->assertSame(
            "body.format-rumbos-popup-page #a{display:none!important}\n"
                . "body.format-rumbos-popup-page .b > i{display:none!important}",
            popup::hide_css()
        );

        // A value that bypassed the admin form still cannot produce CSS.
        set_config('popuphideselectors', 'a{}', 'format_rumbos');
        $this->assertSame('', popup::hide_css());
    }

    /**
     * Settings fall back to their defaults when the stored value is not one of the choices.
     */
    public function test_mode_position_and_header_selector(): void {
        $this->resetAfterTest();
        $this->assertSame(popup::MODE_AUTO, popup::get_mode());
        $this->assertSame(popup::POSITION_FULLSCREEN, popup::get_position());
        $this->assertSame('', popup::get_header_selector());

        set_config('popupmode', 'off', 'format_rumbos');
        set_config('popupposition', 'below-site-header', 'format_rumbos');
        set_config('popupheaderselector', '#h', 'format_rumbos');
        $this->assertSame(popup::MODE_OFF, popup::get_mode());
        $this->assertSame(popup::POSITION_BELOW_HEADER, popup::get_position());
        $this->assertSame('#h', popup::get_header_selector());

        set_config('popupmode', 'bogus', 'format_rumbos');
        set_config('popupheaderselector', "#a\n#b", 'format_rumbos');
        $this->assertSame(popup::MODE_AUTO, popup::get_mode());
        $this->assertSame('', popup::get_header_selector());
    }

    /**
     * The gating truth table: marker, course, headers and mode.
     *
     * @return array
     */
    public static function decide_provider(): array {
        $valid = ['courseid' => 7, 'expires' => 1060];
        $expired = ['courseid' => 7, 'expires' => 1000];
        $iframe = ['HTTP_SEC_FETCH_DEST' => 'iframe', 'HTTP_SEC_FETCH_SITE' => 'same-origin'];
        $full = popup::APPLY_FULL;
        $none = popup::APPLY_NONE;
        return [
            'valid marker, same-origin iframe' => [$iframe, $valid, 7, 'auto', $full],
            'css mode is also full (the hook decides the layout)' => [$iframe, $valid, 7, 'css', $full],
            'mode off' => [$iframe, $valid, 7, 'off', $none],
            'no marker' => [$iframe, null, 7, 'auto', $none],
            'expired marker' => [$iframe, $expired, 7, 'auto', $none],
            'marker of another course' => [$iframe, $valid, 8, 'auto', $none],
            'top-level navigation in another tab' => [
                ['HTTP_SEC_FETCH_DEST' => 'document', 'HTTP_SEC_FETCH_SITE' => 'same-origin'], $valid, 7, 'auto', $none,
            ],
            'iframe of another site' => [
                ['HTTP_SEC_FETCH_DEST' => 'iframe', 'HTTP_SEC_FETCH_SITE' => 'cross-site'], $valid, 7, 'auto', $none,
            ],
            'iframe on the same site but another origin' => [
                ['HTTP_SEC_FETCH_DEST' => 'iframe', 'HTTP_SEC_FETCH_SITE' => 'same-site'], $valid, 7, 'auto', $none,
            ],
            'one header only, not an iframe' => [['HTTP_SEC_FETCH_DEST' => 'document'], $valid, 7, 'auto', $none],
            'headers absent, valid marker' => [[], $valid, 7, 'auto', popup::APPLY_FALLBACK],
            'headers absent, no marker' => [[], null, 7, 'auto', $none],
            'headers absent, expired marker' => [[], $expired, 7, 'auto', $none],
            'headers absent, other course' => [[], $valid, 8, 'auto', $none],
        ];
    }

    /**
     * Decisions for each combination (the time is 1000).
     *
     * @param array $server
     * @param array|null $marker
     * @param int $courseid
     * @param string $mode
     * @param string $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('decide_provider')]
    public function test_decide(array $server, ?array $marker, int $courseid, string $mode, string $expected): void {
        $this->assertSame($expected, popup::decide($server, $marker, $courseid, 1000, $mode));
    }

    /**
     * The marker is bound to a course, expires, is extended by matching requests and can be ended.
     */
    public function test_session_marker_lifecycle(): void {
        $this->resetAfterTest();
        $this->assertNull(popup_session::get());

        popup_session::start(5, 1000);
        $this->assertSame(['courseid' => 5, 'expires' => 1000 + popup_session::TTL], popup_session::get());
        $this->assertTrue(popup_session::is_valid(popup_session::get(), 5, 1001));
        $this->assertFalse(popup_session::is_valid(popup_session::get(), 6, 1001));
        $this->assertFalse(popup_session::is_valid(popup_session::get(), 5, 1000 + popup_session::TTL));

        popup_session::extend(2000);
        $this->assertSame(2000 + popup_session::TTL, popup_session::get()['expires']);

        popup_session::end();
        $this->assertNull(popup_session::get());
        $this->assertFalse(popup_session::is_valid(null, 5, 1));
    }
}
