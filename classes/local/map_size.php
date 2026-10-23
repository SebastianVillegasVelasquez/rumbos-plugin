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
 * How tall the Rumbos area is: the options of a course, the defaults of the site and the limits nobody can exceed.
 *
 * Three modes: "auto" (the app says which proportions its level has and the page follows), "viewport" (a
 * percentage of the viewport height) and "fixed" (pixels). The value means a percentage in "viewport" and
 * pixels in "fixed". The precedence is the course, then the site default, then the built-in default, and every
 * stored value is checked again when it is read, because the options can also be written through the API.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class map_size {
    /** @var string The app's own proportions decide. */
    public const MODE_AUTO = 'auto';

    /** @var string A percentage of the viewport height. */
    public const MODE_VIEWPORT = 'viewport';

    /** @var string A number of pixels. */
    public const MODE_FIXED = 'fixed';

    /** @var string[] The modes. */
    public const MODES = [self::MODE_AUTO, self::MODE_VIEWPORT, self::MODE_FIXED];

    /** @var int[] Valid percentages of the viewport height in "viewport" mode: minimum and maximum. */
    public const VIEWPORT_RANGE = [40, 100];

    /** @var int[] Valid heights in pixels in "fixed" mode: minimum and maximum. */
    public const FIXED_RANGE = [360, 1600];

    /** @var int[] Valid percentages of the viewport height on small screens: minimum and maximum. */
    public const MOBILE_RANGE = [50, 100];

    /** @var int[] Valid site minimum height in pixels: minimum and maximum. */
    public const SITE_MIN_RANGE = [200, 1000];

    /** @var int[] Valid site maximum height in pixels: minimum and maximum. */
    public const SITE_MAX_RANGE = [400, 4000];

    /** @var int Built-in default of the percentage in "viewport" mode. */
    public const DEFAULT_VIEWPORT = 75;

    /** @var int Built-in default of the height in "fixed" mode, in pixels. */
    public const DEFAULT_FIXED = 700;

    /** @var int Built-in default of the percentage on small screens. */
    public const DEFAULT_MOBILE = 80;

    /** @var int Built-in site minimum, in pixels. */
    public const DEFAULT_MIN = 360;

    /** @var int Built-in site maximum, in pixels. */
    public const DEFAULT_MAX = 1600;

    /** @var string Prefix of the course option names, so they never collide with fields of the course table. */
    public const OPTION_PREFIX = 'rumbos_';

    /**
     * Whether a value is one of the known modes.
     *
     * @param mixed $mode
     * @return bool
     */
    public static function is_mode($mode): bool {
        return is_string($mode) && in_array($mode, self::MODES, true);
    }

    /**
     * The valid range of the value for a mode, or null when the mode takes no value.
     *
     * @param string $mode
     * @return int[]|null Minimum and maximum.
     */
    public static function value_range(string $mode): ?array {
        return [self::MODE_VIEWPORT => self::VIEWPORT_RANGE, self::MODE_FIXED => self::FIXED_RANGE][$mode] ?? null;
    }

    /**
     * Whether a value is valid for a mode.
     *
     * @param string $mode
     * @param mixed $value
     * @return bool
     */
    public static function is_valid_value(string $mode, $value): bool {
        $range = self::value_range($mode);
        return $range !== null && self::is_int_in($value, $range);
    }

    /**
     * Whether a value is an integer (or the text of one) inside a range.
     *
     * @param mixed $value
     * @param int[] $range Minimum and maximum, both included.
     * @return bool
     */
    public static function is_int_in($value, array $range): bool {
        if (is_string($value) && preg_match('/^-?\d{1,9}$/', trim($value))) {
            $value = (int) $value;
        }
        return is_int($value) && $value >= $range[0] && $value <= $range[1];
    }

    /**
     * The built-in value of a mode (0 for the mode that takes none).
     *
     * @param string $mode
     * @return int
     */
    public static function default_value(string $mode): int {
        return [self::MODE_VIEWPORT => self::DEFAULT_VIEWPORT, self::MODE_FIXED => self::DEFAULT_FIXED][$mode] ?? 0;
    }

    /**
     * The site settings, each one checked: a stored value that is not valid is replaced by the built-in one.
     *
     * @return array{mode: string, value: int, mobile: int, min: int, max: int}
     */
    public static function site_settings(): array {
        $mode = get_config('format_rumbos', 'heightmode');
        $mode = self::is_mode($mode) ? $mode : self::MODE_AUTO;

        $value = get_config('format_rumbos', 'heightvalue');
        $value = self::is_valid_value($mode, $value) ? (int) $value : self::default_value($mode);

        $mobile = get_config('format_rumbos', 'mobileheight');
        $mobile = self::is_int_in($mobile, self::MOBILE_RANGE) ? (int) $mobile : self::DEFAULT_MOBILE;

        $min = get_config('format_rumbos', 'minheight');
        $min = self::is_int_in($min, self::SITE_MIN_RANGE) ? (int) $min : self::DEFAULT_MIN;
        $max = get_config('format_rumbos', 'maxheight');
        $max = self::is_int_in($max, self::SITE_MAX_RANGE) ? (int) $max : self::DEFAULT_MAX;

        // A pair of limits that contradict each other would make clamp() ignore the maximum: the minimum wins.
        return ['mode' => $mode, 'value' => $value, 'mobile' => $mobile, 'min' => $min, 'max' => max($min, $max)];
    }

    /**
     * The height settings that apply to a course.
     *
     * The course options use "" (the mode) and 0 (the numbers) for "use the site default", and so does
     * anything missing or not valid. Each setting is resolved by itself: a course can change only the mode,
     * or only the height on small screens.
     *
     * @param array $options The format options of the course, as format_rumbos::get_format_options() returns them.
     * @return array{mode: string, value: int, mobile: int, min: int, max: int}
     */
    public static function resolve(array $options): array {
        $site = self::site_settings();

        $mode = $options[self::OPTION_PREFIX . 'heightmode'] ?? '';
        $mode = self::is_mode($mode) ? $mode : $site['mode'];

        $value = $options[self::OPTION_PREFIX . 'heightvalue'] ?? 0;
        if (self::is_valid_value($mode, $value)) {
            $value = (int) $value;
        } else if ($mode === $site['mode']) {
            $value = $site['value'];
        } else {
            // The site value is in another unit than the one this course asked for.
            $value = self::default_value($mode);
        }

        $mobile = $options[self::OPTION_PREFIX . 'mobileheight'] ?? 0;
        $mobile = self::is_int_in($mobile, self::MOBILE_RANGE) ? (int) $mobile : $site['mobile'];

        return ['mode' => $mode, 'value' => $value, 'mobile' => $mobile, 'min' => $site['min'], 'max' => $site['max']];
    }

    /**
     * Checks the height options submitted in the course form.
     *
     * An empty value (or 0) means "use the site default" and is always accepted. A value is checked against the
     * mode the course will end up with, which is the site mode when the course does not choose one.
     *
     * @param array $data The submitted form data.
     * @return string[] Error message keyed by element name; empty when everything is valid.
     */
    public static function validate_course_data(array $data): array {
        $errors = [];
        $mode = $data[self::OPTION_PREFIX . 'heightmode'] ?? '';
        if ($mode !== '' && !self::is_mode($mode)) {
            return [self::OPTION_PREFIX . 'heightmode' => get_string('errorheightmode', 'format_rumbos')];
        }
        $mode = $mode === '' ? self::site_settings()['mode'] : $mode;

        $value = $data[self::OPTION_PREFIX . 'heightvalue'] ?? 0;
        $range = self::value_range($mode);
        if ($range !== null && !self::is_empty_number($value) && !self::is_valid_value($mode, $value)) {
            $errors[self::OPTION_PREFIX . 'heightvalue'] = get_string(
                $mode === self::MODE_VIEWPORT ? 'errorheightviewport' : 'errorheightfixed',
                'format_rumbos',
                (object) ['min' => $range[0], 'max' => $range[1]]
            );
        }

        $mobile = $data[self::OPTION_PREFIX . 'mobileheight'] ?? 0;
        if (!self::is_empty_number($mobile) && !self::is_int_in($mobile, self::MOBILE_RANGE)) {
            $errors[self::OPTION_PREFIX . 'mobileheight'] = get_string(
                'errormobileheight',
                'format_rumbos',
                (object) ['min' => self::MOBILE_RANGE[0], 'max' => self::MOBILE_RANGE[1]]
            );
        }
        return $errors;
    }

    /**
     * Whether a submitted number is empty, which means "use the site default".
     *
     * @param mixed $value
     * @return bool
     */
    private static function is_empty_number($value): bool {
        return $value === null || $value === '' || $value === 0 || $value === '0';
    }

    /**
     * The inline style that carries the settings to the stylesheet as custom properties.
     *
     * Only integers go in, so nothing here can carry markup or a stray declaration.
     *
     * @param array $size What resolve() returns.
     * @return string
     */
    public static function css_variables(array $size): string {
        return sprintf(
            '--format-rumbos-value: %d; --format-rumbos-mobile: %d; --format-rumbos-min: %dpx; --format-rumbos-max: %dpx;',
            $size['value'],
            $size['mobile'],
            $size['min'],
            $size['max']
        );
    }
}
