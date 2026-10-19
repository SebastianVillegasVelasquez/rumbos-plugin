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
 * Tests for the app URL validation.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\local\app_url::class)]
final class app_url_test extends \advanced_testcase {
    /**
     * Accepted inputs and their normal form.
     *
     * @return array
     */
    public static function valid_provider(): array {
        return [
            'plain origin' => ['https://rumbos.example.com', 'https://rumbos.example.com'],
            'trailing slash' => ['https://rumbos.example.com/', 'https://rumbos.example.com'],
            'uppercase host and scheme' => ['HTTPS://Rumbos.Example.COM', 'https://rumbos.example.com'],
            'surrounding whitespace' => ["  https://rumbos.example.com/app/  ", 'https://rumbos.example.com/app'],
            'default port dropped' => ['https://rumbos.example.com:443/app', 'https://rumbos.example.com/app'],
            'custom port kept' => ['https://rumbos.example.com:8443', 'https://rumbos.example.com:8443'],
            'nested path' => ['https://example.com/a/b', 'https://example.com/a/b'],
        ];
    }

    /**
     * Rejected inputs.
     *
     * @return array
     */
    public static function invalid_provider(): array {
        return [
            'http' => ['http://rumbos.example.com'],
            'no scheme' => ['rumbos.example.com'],
            'empty' => [''],
            'javascript scheme' => ['javascript:alert(1)'],
            'ftp' => ['ftp://rumbos.example.com'],
            'userinfo' => ['https://user:pass@rumbos.example.com'],
            'query' => ['https://rumbos.example.com/?a=1'],
            'fragment' => ['https://rumbos.example.com/#x'],
            'inner whitespace' => ['https://rumbos.exa mple.com'],
            'backslash' => ['https://rumbos.example.com\\evil'],
            'bad port' => ['https://rumbos.example.com:99999'],
            'double slash path' => ['https://rumbos.example.com//x'],
        ];
    }

    /**
     * Valid URLs are normalised.
     *
     * @param string $input
     * @param string $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('valid_provider')]
    public function test_normalize_accepts(string $input, string $expected): void {
        $this->assertSame($expected, app_url::normalize($input));
    }

    /**
     * Anything that is not a clean https URL is refused.
     *
     * @param string $input
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_provider')]
    public function test_normalize_rejects(string $input): void {
        $this->assertNull(app_url::normalize($input));
    }

    /**
     * The origin drops the path.
     */
    public function test_origin(): void {
        $this->assertSame('https://a.example.com', app_url::origin('https://a.example.com/app'));
        $this->assertSame('https://a.example.com:8443', app_url::origin('https://a.example.com:8443/app'));
    }

    /**
     * The embed URL follows the contract exactly and encodes the host.
     */
    public function test_embed_url(): void {
        $this->assertSame(
            'https://rumbos.example.com/app/embed?host=https%3A%2F%2Flms.example.com',
            app_url::embed_url('https://rumbos.example.com/app', 'https://lms.example.com')
        );
    }
}
