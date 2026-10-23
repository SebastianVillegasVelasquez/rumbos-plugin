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

/**
 * Rumbos course format: a thin format that embeds the external Rumbos app.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/format/lib.php');

use format_rumbos\local\map_size;

/**
 * Main class for the Rumbos course format.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class format_rumbos extends core_courseformat\base {
    /**
     * The format uses sections.
     *
     * @return bool
     */
    public function uses_sections() {
        return true;
    }

    /**
     * The format uses the course index drawer.
     *
     * @return bool
     */
    public function uses_course_index() {
        return true;
    }

    /**
     * The format is built on the reactive course editor components.
     *
     * @return bool
     */
    public function supports_components() {
        return true;
    }

    /**
     * Whether this format supports AJAX editing.
     *
     * @return stdClass
     */
    public function supports_ajax() {
        $ajaxsupport = new stdClass();
        $ajaxsupport->capable = true;
        return $ajaxsupport;
    }

    /**
     * The options of a course: how tall the Rumbos area is.
     *
     * The names start with "rumbos_" so that they never collide with a field of the course table, and they
     * hold no ids, so a backup carries them to the restored course without any remapping. An empty mode and a 0
     * mean "use the site default" (see {@see map_size} for the precedence).
     *
     * @param bool $foreditform Whether the definitions are for the course edit form, which needs labels.
     * @return array
     */
    public function course_format_options($foreditform = false) {
        $options = [
            map_size::OPTION_PREFIX . 'heightmode' => ['default' => '', 'type' => PARAM_ALPHA],
            map_size::OPTION_PREFIX . 'heightvalue' => ['default' => 0, 'type' => PARAM_INT],
            map_size::OPTION_PREFIX . 'mobileheight' => ['default' => 0, 'type' => PARAM_INT],
        ];
        if ($foreditform) {
            $options[map_size::OPTION_PREFIX . 'heightmode'] += [
                'label' => new lang_string('rumbos_heightmode', 'format_rumbos'),
                'help' => 'rumbos_heightmode',
                'help_component' => 'format_rumbos',
                'element_type' => 'select',
                'element_attributes' => [[
                    '' => get_string('heightmode_inherit', 'format_rumbos'),
                    map_size::MODE_AUTO => get_string('heightmode_auto', 'format_rumbos'),
                    map_size::MODE_VIEWPORT => get_string('heightmode_viewport', 'format_rumbos'),
                    map_size::MODE_FIXED => get_string('heightmode_fixed', 'format_rumbos'),
                ]],
            ];
            $options[map_size::OPTION_PREFIX . 'heightvalue'] += [
                'label' => new lang_string('rumbos_heightvalue', 'format_rumbos'),
                'help' => 'rumbos_heightvalue',
                'help_component' => 'format_rumbos',
                'element_type' => 'text',
                'element_attributes' => [['size' => 6, 'maxlength' => 4]],
            ];
            $options[map_size::OPTION_PREFIX . 'mobileheight'] += [
                'label' => new lang_string('rumbos_mobileheight', 'format_rumbos'),
                'help' => 'rumbos_mobileheight',
                'help_component' => 'format_rumbos',
                'element_type' => 'text',
                'element_attributes' => [['size' => 6, 'maxlength' => 3]],
            ];
        }
        return $options;
    }

    /**
     * Adds the options to the course form; the height only matters for the two modes that take one.
     *
     * @param MoodleQuickForm $mform
     * @param bool $forsection
     * @return array
     */
    public function create_edit_form_elements(&$mform, $forsection = false) {
        $elements = parent::create_edit_form_elements($mform, $forsection);
        if (!$forsection) {
            $prefix = map_size::OPTION_PREFIX;
            $mform->hideIf($prefix . 'heightvalue', $prefix . 'heightmode', 'eq', map_size::MODE_AUTO);
        }
        return $elements;
    }

    /**
     * Checks the height options of the course form.
     *
     * @param array $data
     * @param array $files
     * @param array $errors Errors already found.
     * @return array Error message keyed by element name.
     */
    public function edit_form_validation($data, $files, $errors) {
        return map_size::validate_course_data($data) + parent::edit_form_validation($data, $files, $errors);
    }

    /**
     * How tall the Rumbos area is in this course: its own options, then the site's, then the built-in values.
     *
     * @return array{mode: string, value: int, mobile: int, min: int, max: int}
     */
    public function get_map_size(): array {
        return map_size::resolve($this->get_format_options());
    }

    /**
     * Returns the display name of a section: its custom name, or the default.
     *
     * @param int|stdClass|section_info $section Section object or number.
     * @return string
     */
    public function get_section_name($section) {
        $section = $this->get_section($section);
        if ((string)$section->name !== '') {
            return format_string(
                $section->name,
                true,
                ['context' => context_course::instance($this->courseid)]
            );
        }
        return $this->get_default_section_name($section);
    }

    /**
     * Returns the default name of a section.
     *
     * @param int|stdClass|section_info $section Section object or number.
     * @return string
     */
    public function get_default_section_name($section) {
        $section = $this->get_section($section);
        if ($section->sectionnum == 0) {
            return get_string('section0name', 'format_rumbos');
        }
        return get_string('newsection', 'format_rumbos');
    }

    /**
     * Title of the course page.
     *
     * @return string
     */
    public function page_title(): string {
        return get_string('sectionoutline');
    }
}
