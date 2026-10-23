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

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use format_rumbos\local\course_state;
use format_rumbos\local\key_manager;
use format_rumbos\local\launch_assertion;

/**
 * Tests that a restored or duplicated course starts clean and works.
 *
 * Maps live in the external Rumbos service and are NOT part of the Moodle
 * backup in this phase (see docs/DESIGN-backup-restore.md). A restored course
 * is therefore a new, unmapped course: the app sees a course id it has never
 * seen and starts empty. What Moodle must guarantee is that the course keeps
 * the format and that its state and assertion refer to the NEW ids.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class restore_test extends \advanced_testcase {
    /**
     * Backs a course up and restores it as a new course, the way duplication does.
     *
     * @param \stdClass $course
     * @return int Id of the new course.
     */
    private function duplicate(\stdClass $course): int {
        global $CFG, $USER;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

        $backup = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );
        $backup->execute_plan();
        $file = $backup->get_results()['backup_destination'];
        $backup->destroy();

        $backupid = 'rumbos_restore_test';
        $path = $CFG->tempdir . '/backup/' . $backupid;
        $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), $path);

        $newcourseid = \restore_dbops::create_new_course(
            $course->fullname . ' copy',
            $course->shortname . '_copy',
            $course->category
        );
        $restore = new \restore_controller(
            $backupid,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $restore->execute_precheck();
        $restore->execute_plan();
        $restore->destroy();

        return (int) $newcourseid;
    }

    /**
     * A duplicated course keeps the format and gets its own ids.
     */
    public function test_a_duplicated_course_keeps_the_format_and_gets_new_ids(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['format' => 'rumbos']);
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Original page']);

        $newid = $this->duplicate($course);

        $this->assertNotSame((int) $course->id, $newid);
        $this->assertSame('rumbos', $DB->get_field('course', 'format', ['id' => $newid]));

        $state = course_state::for_user($newid, (int) get_admin()->id);
        $this->assertSame($newid, $state['courseId']);
        $this->assertCount(1, $state['activities']);
        $this->assertSame('Original page', $state['activities'][0]['name']);
        $this->assertNotSame((int) $page->cmid, $state['activities'][0]['cmid'], 'the restored cmid is a new one');
    }

    /**
     * The assertion for the copy names the copy, so the backend sees an unmapped course.
     */
    public function test_the_assertion_of_a_duplicate_carries_the_new_course_id(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        key_manager::reset_all();
        $course = $this->getDataGenerator()->create_course(['format' => 'rumbos']);

        $newid = $this->duplicate($course);

        $token = launch_assertion::create($newid, (int) get_admin()->id)['token'];
        $claims = JWT::decode($token, new Key(key_manager::get_public_key(), 'RS256'));
        $this->assertSame($newid, $claims->course);
        $this->assertNotSame((int) $course->id, $claims->course);
    }

    /**
     * The size options of the course travel with a backup: a duplicate keeps them, and keeps using the site fallbacks.
     */
    public function test_the_size_options_survive_a_backup_and_restore(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['format' => 'rumbos']);
        course_get_format($course)->update_course_format_options([
            'rumbos_heightmode' => 'viewport',
            'rumbos_heightvalue' => 85,
            'rumbos_mobileheight' => 65,
        ]);
        $other = $this->getDataGenerator()->create_course(['format' => 'rumbos']);

        $newid = $this->duplicate($course);

        $this->assertSame(
            ['rumbos_heightmode' => 'viewport', 'rumbos_heightvalue' => 85, 'rumbos_mobileheight' => 65],
            course_get_format($newid)->get_format_options()
        );
        $size = course_get_format($newid)->get_map_size();
        $this->assertSame(['viewport', 85, 65], [$size['mode'], $size['value'], $size['mobile']]);

        // The copy is independent: changing it leaves the original and any other course alone.
        course_get_format($newid)->update_course_format_options(['rumbos_heightmode' => 'fixed', 'rumbos_heightvalue' => 500]);
        $this->assertSame('viewport', course_get_format($course)->get_format_options()['rumbos_heightmode']);
        $this->assertSame('', course_get_format($other)->get_format_options()['rumbos_heightmode']);
    }

    /**
     * A course that left everything on "site default" is restored the same way, so the site's own values apply.
     */
    public function test_a_course_on_the_site_default_is_restored_on_the_site_default(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['format' => 'rumbos']);

        $newid = $this->duplicate($course);
        set_config('heightmode', 'fixed', 'format_rumbos');
        set_config('heightvalue', 640, 'format_rumbos');

        $size = course_get_format($newid)->get_map_size();
        $this->assertSame(['fixed', 640], [$size['mode'], $size['value']]);
    }
}
