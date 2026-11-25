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

use core_external\external_api;
use format_rumbos\external\list_flow_activities;
use format_rumbos\external\resolve_flow_activity;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/resourcelib.php');

/**
 * Tests for the Moodle activities a flow step may show: who may list them, who may open them, and what is framed.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\local\flow_activities::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\local\resource_inline::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\external\list_flow_activities::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\external\resolve_flow_activity::class)]
final class flow_activities_test extends \advanced_testcase {
    /**
     * Creates a course with a student, an editing teacher, and one activity of each kind that matters.
     *
     * @return array [course, ids, student, teacher]
     */
    private function setup_course(): array {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['format' => 'rumbos', 'enablecompletion' => 1]);
        $student = $gen->create_user();
        $teacher = $gen->create_user();
        $gen->enrol_user($student->id, $course->id, 'student');
        $gen->enrol_user($teacher->id, $course->id, 'editingteacher');

        $this->setAdminUser();
        $ids = [
            'page' => (int) $gen->create_module('page', ['course' => $course->id, 'name' => 'A page'])->cmid,
            'url' => (int) $gen->create_module('url', ['course' => $course->id, 'externalurl' => 'https://example.com/x'])->cmid,
            'pdf' => (int) $gen->create_module('resource', [
                'course' => $course->id, 'defaultfilename' => 'guide.pdf', 'display' => RESOURCELIB_DISPLAY_OPEN,
            ])->cmid,
            'hidden' => (int) $gen->create_module('page', ['course' => $course->id, 'visible' => 0])->cmid,
            'quiz' => (int) $gen->create_module('quiz', ['course' => $course->id])->cmid,
        ];
        return [$course, $ids, $student, $teacher];
    }

    /**
     * The editor sees the allowed modules of this course, with the hidden one marked, and nothing else.
     */
    public function test_list_as_editor(): void {
        [$course, $ids, , $teacher] = $this->setup_course();
        $other = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('page', ['course' => $other->id]);

        $this->setUser($teacher);
        $items = external_api::clean_returnvalue(
            list_flow_activities::execute_returns(),
            list_flow_activities::execute((int) $course->id)
        )['items'];

        $byid = array_column($items, null, 'cmid');
        $this->assertEqualsCanonicalizing([$ids['page'], $ids['url'], $ids['pdf'], $ids['hidden']], array_keys($byid));
        $this->assertTrue($byid[$ids['page']]['visible']);
        $this->assertFalse($byid[$ids['hidden']]['visible']);
        $this->assertSame('A page', $byid[$ids['page']]['name']);
        $this->assertSame('resource', $byid[$ids['pdf']]['modname']);
        foreach ($items as $item) {
            $this->assertSame(['cmid', 'name', 'modname', 'visible'], array_keys($item), 'no address in the list');
        }
    }

    /**
     * A student never gets a list, and neither does a teacher who may not edit the map.
     */
    public function test_list_is_refused_to_everyone_but_editors(): void {
        [$course, , $student] = $this->setup_course();
        $assistant = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($assistant->id, $course->id, 'teacher');

        $refused = 0;
        foreach ([$student, $assistant] as $user) {
            $this->setUser($user);
            try {
                list_flow_activities::execute((int) $course->id);
            } catch (\required_capability_exception $e) {
                $refused++;
            }
        }
        $this->assertSame(2, $refused, 'Neither a student nor a teacher who may not edit the map gets the list.');
    }

    /**
     * A course module being deleted is not offered.
     */
    public function test_list_skips_an_activity_being_deleted(): void {
        global $DB;
        [$course, $ids, , $teacher] = $this->setup_course();
        $DB->set_field('course_modules', 'deletioninprogress', 1, ['id' => $ids['url']]);
        rebuild_course_cache((int) $course->id, true);

        $this->setUser($teacher);
        $cmids = array_column(flow_activities::list((int) $course->id), 'cmid');
        $this->assertNotContains($ids['url'], $cmids);
        $this->assertContains($ids['page'], $cmids);
    }

    /**
     * A student opens a visible page: the answer is the page's own view address and its title.
     */
    public function test_resolve_for_a_student(): void {
        global $CFG;
        [$course, $ids, $student] = $this->setup_course();

        $this->setUser($student);
        $result = external_api::clean_returnvalue(
            resolve_flow_activity::execute_returns(),
            resolve_flow_activity::execute((int) $course->id, $ids['page'])
        );

        $this->assertSame('', $result['error']);
        $this->assertSame("{$CFG->wwwroot}/mod/page/view.php?id={$ids['page']}", $result['url']);
        $this->assertSame('A page', $result['title']);
        $this->assertSame('page', $result['modname']);
    }

    /**
     * An id from another course is "not found", exactly as an id that does not exist is.
     */
    public function test_resolve_a_cmid_of_another_course(): void {
        [$course, , $student] = $this->setup_course();
        $other = $this->getDataGenerator()->create_course(['format' => 'rumbos']);
        $foreign = $this->getDataGenerator()->create_module('page', ['course' => $other->id]);
        $this->getDataGenerator()->enrol_user($student->id, $other->id, 'student');

        $this->setUser($student);
        $notfound = ['error' => flow_activities::NOT_FOUND];
        $this->assertSame($notfound, flow_activities::resolve((int) $course->id, (int) $foreign->cmid));
        $this->assertSame($notfound, flow_activities::resolve((int) $course->id, 987654));
        $this->assertSame($notfound, flow_activities::resolve((int) $course->id, 0));
    }

    /**
     * A hidden activity is refused to a student and opens for the editor.
     */
    public function test_resolve_a_hidden_activity(): void {
        [$course, $ids, $student, $teacher] = $this->setup_course();

        $this->setUser($student);
        $this->assertSame(['error' => flow_activities::FORBIDDEN], flow_activities::resolve((int) $course->id, $ids['hidden']));

        $this->setUser($teacher);
        $this->assertArrayHasKey('url', flow_activities::resolve((int) $course->id, $ids['hidden']));
    }

    /**
     * An activity restricted by availability is refused to the student it restricts.
     */
    public function test_resolve_a_restricted_activity(): void {
        global $DB;
        [$course, $ids, $student] = $this->setup_course();
        $DB->set_field('course_modules', 'availability', json_encode([
            'op' => '&', 'showc' => [false], 'c' => [['type' => 'date', 'd' => '>=', 't' => time() + DAYSECS]],
        ]), ['id' => $ids['page']]);
        rebuild_course_cache((int) $course->id, true);

        $this->setUser($student);
        $this->assertSame(['error' => flow_activities::FORBIDDEN], flow_activities::resolve((int) $course->id, $ids['page']));
    }

    /**
     * A module outside the allow-list is "unsupported", and that is the whole list.
     */
    public function test_resolve_an_unsupported_module(): void {
        [$course, $ids, $student] = $this->setup_course();

        $this->setUser($student);
        $this->assertSame(['error' => flow_activities::UNSUPPORTED], flow_activities::resolve((int) $course->id, $ids['quiz']));
        $this->assertSame(['resource', 'url', 'page'], flow_activities::ALLOWED_MODULES);
    }

    /**
     * Someone who is not in the course gets nothing, and the function takes no address.
     */
    public function test_resolve_for_someone_outside_the_course(): void {
        [$course, $ids] = $this->setup_course();
        $outsider = $this->getDataGenerator()->create_user();
        $this->setUser($outsider);

        $this->assertSame(['courseid', 'cmid'], array_keys(resolve_flow_activity::execute_parameters()->keys));
        // Moodle's own course access check refuses first; the answer never reaches the activity.
        $this->expectException(\core\exception\require_login_exception::class);
        resolve_flow_activity::execute((int) $course->id, $ids['page']);
    }

    /**
     * A PDF in a mode its own page shows in a frame keeps the resource's own view page.
     */
    public function test_a_pdf_that_frames_well_uses_its_view_page(): void {
        [$course, $ids, $student] = $this->setup_course();
        $this->setUser($student);

        $result = flow_activities::resolve((int) $course->id, $ids['pdf']);
        $this->assertStringContainsString("/mod/resource/view.php?id={$ids['pdf']}", $result['url']);
    }

    /**
     * A PDF whose display mode does not show inside a frame goes through the plugin's inline page, in every such mode.
     *
     * @param int $display The RESOURCELIB_DISPLAY_* mode.
     * @param bool $inline Whether the overlay is sent to the plugin's inline page.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('display_modes')]
    public function test_a_pdf_in_each_display_mode(int $display, bool $inline): void {
        [$course, , $student] = $this->setup_course();
        $this->setAdminUser();
        $cm = $this->getDataGenerator()->create_module('resource', [
            'course' => $course->id, 'defaultfilename' => 'guide.pdf', 'display' => $display,
            'popupwidth' => 620, 'popupheight' => 450,
        ]);

        $this->setUser($student);
        $url = flow_activities::resolve((int) $course->id, (int) $cm->cmid)['url'];
        $expected = $inline ? "/course/format/rumbos/resource.php?id={$cm->cmid}" : "/mod/resource/view.php?id={$cm->cmid}";
        $this->assertStringEndsWith($expected, $url);
    }

    /**
     * The display modes and whether the overlay needs the inline page.
     *
     * @return array
     */
    public static function display_modes(): array {
        return [
            'automatic (a PDF opens)' => [RESOURCELIB_DISPLAY_AUTO, false],
            'embed' => [RESOURCELIB_DISPLAY_EMBED, false],
            'open' => [RESOURCELIB_DISPLAY_OPEN, false],
            'in frame' => [RESOURCELIB_DISPLAY_FRAME, true],
            'new window' => [RESOURCELIB_DISPLAY_NEW, true],
            'force download' => [RESOURCELIB_DISPLAY_DOWNLOAD, true],
            'in pop-up' => [RESOURCELIB_DISPLAY_POPUP, true],
        ];
    }

    /**
     * A file the browser cannot show in the download mode keeps the resource's own page.
     */
    public function test_a_file_the_browser_cannot_show_keeps_its_view_page(): void {
        [$course, , $student] = $this->setup_course();
        $this->setAdminUser();
        $cm = $this->getDataGenerator()->create_module('resource', [
            'course' => $course->id, 'defaultfilename' => 'notes.docx', 'display' => RESOURCELIB_DISPLAY_DOWNLOAD,
        ]);

        $this->setUser($student);
        $url = flow_activities::resolve((int) $course->id, (int) $cm->cmid)['url'];
        $this->assertStringContainsString('/mod/resource/view.php', $url);
    }

    /**
     * The file address the inline page redirects to is Moodle's own, and never forces a download.
     */
    public function test_inline_file_url_is_not_a_download(): void {
        [, $ids] = $this->setup_course();
        $context = \core\context\module::instance($ids['pdf']);
        $file = resource_inline::main_file($context);

        $url = resource_inline::file_url((object) ['revision' => 1], $file);
        $this->assertStringContainsString("/pluginfile.php/{$context->id}/mod_resource/content/1/guide.pdf", $url->out(false));
        $this->assertNull($url->get_param('forcedownload'));
    }
}
