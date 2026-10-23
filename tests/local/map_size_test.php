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
 * Tests for the height settings: validation, precedence and reading what is stored.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\local\map_size::class)]
final class map_size_test extends \advanced_testcase {
    /**
     * Starts every test from the built-in defaults.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        foreach (['heightmode', 'heightvalue', 'mobileheight', 'minheight', 'maxheight'] as $name) {
            unset_config($name, 'format_rumbos');
        }
    }

    /**
     * Sets site settings.
     *
     * @param array $settings Name => value.
     */
    private function site(array $settings): void {
        foreach ($settings as $name => $value) {
            set_config($name, $value, 'format_rumbos');
        }
    }

    /**
     * With nothing stored anywhere the built-in defaults apply.
     */
    public function test_built_in_defaults(): void {
        $this->assertSame(
            ['mode' => 'auto', 'value' => 0, 'mobile' => 80, 'min' => 360, 'max' => 1600],
            map_size::resolve([])
        );
    }

    /**
     * The site default beats the built-in one, and the course beats the site.
     */
    public function test_precedence_is_course_then_site_then_built_in(): void {
        $this->site(['heightmode' => 'viewport', 'heightvalue' => 60, 'mobileheight' => 90]);

        $this->assertSame(
            ['mode' => 'viewport', 'value' => 60, 'mobile' => 90, 'min' => 360, 'max' => 1600],
            map_size::resolve([]),
            'the site default'
        );
        $this->assertSame(
            ['mode' => 'viewport', 'value' => 85, 'mobile' => 70, 'min' => 360, 'max' => 1600],
            map_size::resolve(['rumbos_heightmode' => 'viewport', 'rumbos_heightvalue' => 85, 'rumbos_mobileheight' => 70]),
            'the course'
        );
        $this->assertSame(
            ['mode' => 'viewport', 'value' => 60, 'mobile' => 90, 'min' => 360, 'max' => 1600],
            map_size::resolve(['rumbos_heightmode' => '', 'rumbos_heightvalue' => 0, 'rumbos_mobileheight' => 0]),
            'a course that leaves everything on "site default"'
        );
    }

    /**
     * Each setting of a course is resolved by itself.
     */
    public function test_a_course_can_change_one_setting_only(): void {
        $this->site(['heightmode' => 'fixed', 'heightvalue' => 900]);

        $resolved = map_size::resolve(['rumbos_mobileheight' => 55]);

        $this->assertSame('fixed', $resolved['mode']);
        $this->assertSame(900, $resolved['value']);
        $this->assertSame(55, $resolved['mobile']);
    }

    /**
     * A course that picks another mode than the site's does not inherit the site's number, which has other units.
     */
    public function test_the_site_value_is_not_reused_across_units(): void {
        $this->site(['heightmode' => 'fixed', 'heightvalue' => 900]);

        $resolved = map_size::resolve(['rumbos_heightmode' => 'viewport']);

        $this->assertSame(75, $resolved['value'], 'not 900, which would be 900 percent');
    }

    /**
     * What is stored is checked again when it is read.
     */
    public function test_invalid_stored_values_are_replaced_on_read(): void {
        $this->site([
            'heightmode' => 'sideways', 'heightvalue' => 'tall', 'mobileheight' => 5, 'minheight' => 1, 'maxheight' => 'x',
        ]);
        $this->assertSame(
            ['mode' => 'auto', 'value' => 0, 'mobile' => 80, 'min' => 360, 'max' => 1600],
            map_size::resolve([]),
            'site settings'
        );

        $this->assertSame(
            ['mode' => 'auto', 'value' => 0, 'mobile' => 80, 'min' => 360, 'max' => 1600],
            map_size::resolve(['rumbos_heightmode' => 'sideways', 'rumbos_heightvalue' => 99999, 'rumbos_mobileheight' => 1000]),
            'course options written through the API'
        );
        $this->assertSame(
            700,
            map_size::resolve(['rumbos_heightmode' => 'fixed', 'rumbos_heightvalue' => 99999])['value'],
            'a value outside the range of its mode'
        );
        $this->assertSame(
            75,
            map_size::resolve(['rumbos_heightmode' => 'viewport', 'rumbos_heightvalue' => '<script>'])['value'],
            'text'
        );
    }

    /**
     * The limits of the range are valid and the values next to them are not.
     */
    public function test_range_bounds(): void {
        foreach (
            [
                ['viewport', 40, true], ['viewport', 39, false], ['viewport', 100, true], ['viewport', 101, false],
                ['fixed', 360, true], ['fixed', 359, false], ['fixed', 1600, true], ['fixed', 1601, false],
                ['viewport', '75', true], ['viewport', '7.5', false], ['viewport', '', false], ['auto', 75, false],
            ] as [$mode, $value, $valid]
        ) {
            $this->assertSame($valid, map_size::is_valid_value($mode, $value), "$mode $value");
        }
        $this->assertSame(50, map_size::resolve(['rumbos_mobileheight' => 50])['mobile']);
        $this->assertSame(100, map_size::resolve(['rumbos_mobileheight' => 100])['mobile']);
        $this->assertSame(80, map_size::resolve(['rumbos_mobileheight' => 49])['mobile']);
        $this->assertSame(80, map_size::resolve(['rumbos_mobileheight' => 101])['mobile']);
    }

    /**
     * The site minimum and maximum are what clamp() will use, and a course has no way to change them.
     */
    public function test_site_limits_apply_to_every_course(): void {
        $this->site(['minheight' => 500, 'maxheight' => 900]);

        $resolved = map_size::resolve(['rumbos_heightmode' => 'fixed', 'rumbos_heightvalue' => 1600, 'rumbos_minheight' => 1]);

        $this->assertSame(1600, $resolved['value'], 'the value stays what the course asked for');
        $this->assertSame([500, 900], [$resolved['min'], $resolved['max']], 'and the limits clamp it when it is drawn');
    }

    /**
     * Limits that contradict each other cannot produce a maximum below the minimum.
     */
    public function test_a_maximum_below_the_minimum_is_raised_to_it(): void {
        $this->site(['minheight' => 900, 'maxheight' => 500]);

        $resolved = map_size::resolve([]);

        $this->assertSame([900, 900], [$resolved['min'], $resolved['max']]);
    }

    /**
     * The form accepts "site default" (empty or 0) and values in range, and says what is wrong otherwise.
     */
    public function test_form_validation(): void {
        $this->assertSame([], map_size::validate_course_data([]));
        $this->assertSame([], map_size::validate_course_data([
            'rumbos_heightmode' => '', 'rumbos_heightvalue' => '0', 'rumbos_mobileheight' => '',
        ]));
        $this->assertSame([], map_size::validate_course_data([
            'rumbos_heightmode' => 'viewport', 'rumbos_heightvalue' => 40, 'rumbos_mobileheight' => 100,
        ]));
        $this->assertSame([], map_size::validate_course_data(['rumbos_heightmode' => 'fixed', 'rumbos_heightvalue' => 1600]));

        $errors = map_size::validate_course_data(['rumbos_heightmode' => 'viewport', 'rumbos_heightvalue' => 30]);
        $this->assertSame(['rumbos_heightvalue'], array_keys($errors));
        $this->assertStringContainsString('40', $errors['rumbos_heightvalue']);
        $this->assertStringContainsString('100', $errors['rumbos_heightvalue']);

        $errors = map_size::validate_course_data(['rumbos_heightmode' => 'fixed', 'rumbos_heightvalue' => 200]);
        $this->assertStringContainsString('360', $errors['rumbos_heightvalue']);

        $errors = map_size::validate_course_data(['rumbos_mobileheight' => 49]);
        $this->assertSame(['rumbos_mobileheight'], array_keys($errors));

        $errors = map_size::validate_course_data(['rumbos_heightmode' => 'tall']);
        $this->assertSame(['rumbos_heightmode'], array_keys($errors));
    }

    /**
     * Without a mode of its own a course is checked against the site mode.
     */
    public function test_a_number_without_a_course_mode_is_checked_against_the_site_mode(): void {
        $this->site(['heightmode' => 'fixed']);
        $this->assertSame([], map_size::validate_course_data(['rumbos_heightmode' => '', 'rumbos_heightvalue' => 800]));
        $this->assertSame(
            ['rumbos_heightvalue'],
            array_keys(map_size::validate_course_data(['rumbos_heightmode' => '', 'rumbos_heightvalue' => 75]))
        );
    }

    /**
     * In automatic mode the number is not used, so it is not an error.
     */
    public function test_a_number_in_automatic_mode_is_ignored(): void {
        $this->assertSame([], map_size::validate_course_data(['rumbos_heightmode' => 'auto', 'rumbos_heightvalue' => 7]));
    }

    /**
     * The inline style holds integers only.
     */
    public function test_css_variables(): void {
        $this->assertSame(
            '--format-rumbos-value: 75; --format-rumbos-mobile: 80; --format-rumbos-min: 360px; --format-rumbos-max: 1600px;',
            map_size::css_variables(['value' => 75, 'mobile' => 80, 'min' => 360, 'max' => 1600])
        );
    }
}
