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
 * Tests for the activity popup settings and request checks.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\local\popup::class)]
final class popup_test extends \advanced_testcase {
    /**
     * Lists of module names and the clean list they become.
     *
     * @return array
     */
    public static function modules_provider(): array {
        return [
            'empty' => ['', []],
            'blank' => ["  \n ", []],
            'one' => ['lti', ['lti']],
            'commas' => ['bigbluebuttonbn,lti', ['bigbluebuttonbn', 'lti']],
            'mixed separators and case' => ["BigBlueButtonBN, LTI\nzoom", ['bigbluebuttonbn', 'lti', 'zoom']],
            'duplicates' => ['lti,lti, LTI', ['lti']],
        ];
    }

    /**
     * Valid lists are normalised.
     *
     * @param string $value
     * @param array $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('modules_provider')]
    public function test_parse_modules(string $value, array $expected): void {
        $this->assertSame($expected, popup::parse_modules($value));
    }

    /**
     * Anything that is not a module name makes the whole list invalid.
     */
    public function test_parse_modules_rejects_names_that_are_not_modules(): void {
        foreach (['lti,../admin', 'mod_lti', 'lti;drop', '<b>lti</b>', '1lti', 'lti!'] as $value) {
            $this->assertNull(popup::parse_modules($value), $value);
        }
    }

    /**
     * Without a stored value the default list applies; an empty value means "none".
     */
    public function test_new_tab_modules_default_and_empty(): void {
        $this->resetAfterTest();
        unset_config('newtabmodules', 'format_rumbos');
        $this->assertSame(['bigbluebuttonbn', 'lti'], popup::get_newtab_modules());

        set_config('newtabmodules', '', 'format_rumbos');
        $this->assertSame([], popup::get_newtab_modules());

        set_config('newtabmodules', 'url, scorm', 'format_rumbos');
        $this->assertSame(['url', 'scorm'], popup::get_newtab_modules());
    }

    /**
     * The polled modules default to the ones that report completion inside their own frame, and can be emptied.
     */
    public function test_poll_modules_default_and_empty(): void {
        $this->resetAfterTest();
        unset_config('pollmodules', 'format_rumbos');
        $this->assertSame(['scorm', 'h5pactivity'], popup::get_poll_modules());

        set_config('pollmodules', '', 'format_rumbos');
        $this->assertSame([], popup::get_poll_modules());

        set_config('pollmodules', 'scorm, ../x', 'format_rumbos');
        $this->assertSame([], popup::get_poll_modules(), 'a corrupt value turns polling off');
    }

    /**
     * Only same-origin iframe requests are popup requests; absent headers are not.
     */
    public function test_is_popup_request_needs_a_same_origin_iframe(): void {
        $this->assertTrue(popup::is_popup_request(['HTTP_SEC_FETCH_DEST' => 'iframe', 'HTTP_SEC_FETCH_SITE' => 'same-origin']));

        $this->assertFalse(popup::is_popup_request([]));
        $this->assertFalse(popup::is_popup_request(['HTTP_SEC_FETCH_DEST' => 'document', 'HTTP_SEC_FETCH_SITE' => 'same-origin']));
        $this->assertFalse(popup::is_popup_request(['HTTP_SEC_FETCH_DEST' => 'iframe', 'HTTP_SEC_FETCH_SITE' => 'cross-site']));
        $this->assertFalse(popup::is_popup_request(['HTTP_SEC_FETCH_DEST' => 'iframe', 'HTTP_SEC_FETCH_SITE' => 'same-site']));
        $this->assertFalse(popup::is_popup_request(['HTTP_SEC_FETCH_DEST' => 'iframe']));
    }
}
