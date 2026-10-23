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

/**
 * Tests for the course options of the format: names, defaults, storage, form validation and precedence.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos::class)]
final class format_options_test extends \advanced_testcase {
    /**
     * Creates a Rumbos course and returns its format.
     *
     * @param array $options Course options to store.
     * @return \format_rumbos
     */
    private function course_format(array $options = []): \format_rumbos {
        $this->resetAfterTest();
        foreach (['heightmode', 'heightvalue', 'mobileheight', 'minheight', 'maxheight'] as $name) {
            unset_config($name, 'format_rumbos');
        }
        $course = $this->getDataGenerator()->create_course(['format' => 'rumbos'] + $options);
        return course_get_format($course);
    }

    /**
     * The names start with the prefix, so they cannot collide with the fields of the course table.
     */
    public function test_option_names_never_collide_with_course_fields(): void {
        global $DB;
        $format = $this->course_format();

        $columns = array_keys($DB->get_columns('course'));
        foreach (array_keys($format->course_format_options()) as $name) {
            $this->assertStringStartsWith('rumbos_', $name);
            $this->assertNotContains($name, $columns);
        }
        $this->assertEqualsCanonicalizing(
            ['rumbos_heightmode', 'rumbos_heightvalue', 'rumbos_mobileheight'],
            array_keys($format->course_format_options())
        );
    }

    /**
     * A new course leaves everything on "site default".
     */
    public function test_a_new_course_uses_the_site_defaults(): void {
        $format = $this->course_format();

        $this->assertSame(
            ['rumbos_heightmode' => '', 'rumbos_heightvalue' => 0, 'rumbos_mobileheight' => 0],
            $format->get_format_options()
        );
        $this->assertSame(
            ['mode' => 'auto', 'value' => 0, 'mobile' => 80, 'min' => 360, 'max' => 1600],
            $format->get_map_size()
        );
    }

    /**
     * The edit form offers the three options with labels and help.
     */
    public function test_the_edit_form_definitions(): void {
        $format = $this->course_format();

        $options = $format->course_format_options(true);

        foreach ($options as $name => $option) {
            $this->assertNotEmpty($option['label'], $name);
            $this->assertSame($name, $option['help']);
            $this->assertTrue(get_string_manager()->string_exists($name . '_help', 'format_rumbos'), $name);
        }
        $this->assertSame('select', $options['rumbos_heightmode']['element_type']);
        $this->assertSame(
            ['', 'auto', 'viewport', 'fixed'],
            array_keys($options['rumbos_heightmode']['element_attributes'][0])
        );
    }

    /**
     * What the course form stores is read back, and wins over the site default.
     */
    public function test_course_options_are_stored_and_win_over_the_site(): void {
        $format = $this->course_format();
        set_config('heightmode', 'fixed', 'format_rumbos');
        set_config('heightvalue', 900, 'format_rumbos');

        $changed = $format->update_course_format_options([
            'rumbos_heightmode' => 'viewport',
            'rumbos_heightvalue' => 60,
            'rumbos_mobileheight' => 65,
        ]);

        $this->assertTrue($changed);
        $size = course_get_format($format->get_courseid())->get_map_size();
        $this->assertSame('viewport', $size['mode']);
        $this->assertSame(60, $size['value']);
        $this->assertSame(65, $size['mobile']);
    }

    /**
     * Going back to "site default" is storing the empty values.
     */
    public function test_a_course_can_go_back_to_the_site_default(): void {
        $format = $this->course_format();
        $format->update_course_format_options(['rumbos_heightmode' => 'fixed', 'rumbos_heightvalue' => 500]);
        set_config('heightmode', 'viewport', 'format_rumbos');
        set_config('heightvalue', 50, 'format_rumbos');

        $format->update_course_format_options(['rumbos_heightmode' => '', 'rumbos_heightvalue' => 0]);

        $size = course_get_format($format->get_courseid())->get_map_size();
        $this->assertSame('viewport', $size['mode']);
        $this->assertSame(50, $size['value']);
    }

    /**
     * A mode that is not in the list is not stored, and a bad number written through the API is ignored on read.
     */
    public function test_values_written_through_the_api_are_checked_on_read(): void {
        $format = $this->course_format();

        $format->update_course_format_options(['rumbos_heightmode' => 'sideways']);
        $this->assertSame('', $format->get_format_options()['rumbos_heightmode'], 'an unknown mode is not stored');

        $format->update_course_format_options([
            'rumbos_heightmode' => 'fixed', 'rumbos_heightvalue' => 100000, 'rumbos_mobileheight' => 5,
        ]);
        $size = course_get_format($format->get_courseid())->get_map_size();
        $this->assertSame('fixed', $size['mode']);
        $this->assertSame(700, $size['value'], 'out of range: the built-in value');
        $this->assertSame(80, $size['mobile'], 'out of range: the site default');
    }

    /**
     * The course form reports what is wrong with each field.
     */
    public function test_form_validation(): void {
        $format = $this->course_format();

        $valid = ['rumbos_heightmode' => 'fixed', 'rumbos_heightvalue' => 800];
        $this->assertSame([], $format->edit_form_validation($valid, [], []));

        $errors = $format->edit_form_validation(
            ['rumbos_heightmode' => 'fixed', 'rumbos_heightvalue' => 100, 'rumbos_mobileheight' => 20],
            [],
            []
        );
        $this->assertEqualsCanonicalizing(['rumbos_heightvalue', 'rumbos_mobileheight'], array_keys($errors));
    }

    /**
     * The options hold no ids: nothing but a mode and plain numbers.
     */
    public function test_the_options_are_not_identifiers(): void {
        $format = $this->course_format();
        $format->update_course_format_options([
            'rumbos_heightmode' => 'viewport', 'rumbos_heightvalue' => 90, 'rumbos_mobileheight' => 70,
        ]);

        foreach (course_get_format($format->get_courseid())->get_format_options() as $name => $value) {
            $this->assertNotSame((int) $format->get_courseid(), $value, $name);
        }
        $this->assertSame(
            ['rumbos_heightmode' => 'viewport', 'rumbos_heightvalue' => 90, 'rumbos_mobileheight' => 70],
            course_get_format($format->get_courseid())->get_format_options()
        );
    }
}
