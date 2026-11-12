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
 * Tests for the storage of welcome-flow progress.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\local\flow_progress::class)]
final class flow_progress_test extends \advanced_testcase {
    /** @var string A map id. */
    private const MAP = '3f0c2a52-6c1e-4f6e-9a8e-0d6b1f9a7c11';

    /** @var string Another map id. */
    private const OTHERMAP = '11111111-2222-4333-8444-555555555555';

    /**
     * The checks accept what the contract allows and nothing else.
     */
    public function test_the_value_checks(): void {
        $this->assertTrue(flow_progress::is_mapid(self::MAP));
        $this->assertTrue(flow_progress::is_mapid(strtoupper(self::MAP)));
        foreach (['', 'not-a-uuid', self::MAP . 'x', ' ' . self::MAP, self::MAP . "\n", 12, null] as $bad) {
            $this->assertFalse(flow_progress::is_mapid($bad));
        }

        $this->assertTrue(flow_progress::is_stepkey('welcome-video_1'));
        $this->assertTrue(flow_progress::is_stepkey(str_repeat('a', 16)));
        foreach (['', str_repeat('a', 17), 'a b', 'a/b', "a\n", 'ñ', 5] as $bad) {
            $this->assertFalse(flow_progress::is_stepkey($bad));
        }

        $this->assertTrue(flow_progress::is_revision(0));
        $this->assertTrue(flow_progress::is_revision(flow_progress::MAX_REVISION));
        foreach ([-1, flow_progress::MAX_REVISION + 1, '1', 1.5, null] as $bad) {
            $this->assertFalse(flow_progress::is_revision($bad));
        }

        foreach (['completed', 'fallback', 'unavailable'] as $good) {
            $this->assertTrue(flow_progress::is_method($good));
        }
        foreach (['', 'done', 'COMPLETED', null] as $bad) {
            $this->assertFalse(flow_progress::is_method($bad));
        }
    }

    /**
     * A step is stored once and read back with an ISO time in UTC.
     */
    public function test_a_step_is_stored_and_read_back(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();

        $result = flow_progress::record($course->id, $user->id, self::MAP, 'video', 2, 'completed', 1700000000);

        $this->assertSame(flow_progress::RESULT_STORED, $result);
        $this->assertSame(
            [['stepKey' => 'video', 'revision' => 2, 'method' => 'completed', 'completedAt' => '2023-11-14T22:13:20Z']],
            flow_progress::get_items($course->id, $user->id, self::MAP)
        );
    }

    /**
     * Repeating a step keeps the earliest completion of the same revision.
     */
    public function test_a_repeat_is_idempotent(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();

        flow_progress::record($course->id, $user->id, self::MAP, 'video', 1, 'completed', 1000);
        $again = flow_progress::record($course->id, $user->id, self::MAP, 'video', 1, 'fallback', 2000);

        $this->assertSame(flow_progress::RESULT_UNCHANGED, $again);
        $this->assertSame(1, $DB->count_records(flow_progress::TABLE));
        $row = $DB->get_record(flow_progress::TABLE, []);
        $this->assertSame('completed', $row->method);
        $this->assertSame(1000, (int) $row->timecompleted);
    }

    /**
     * A lower revision is ignored and a higher one replaces the row.
     */
    public function test_a_higher_revision_replaces_and_a_lower_one_is_ignored(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();

        flow_progress::record($course->id, $user->id, self::MAP, 'video', 3, 'completed', 1000);
        $lower = flow_progress::record($course->id, $user->id, self::MAP, 'video', 2, 'fallback', 2000);
        $higher = flow_progress::record($course->id, $user->id, self::MAP, 'video', 4, 'unavailable', 3000);

        $this->assertSame(flow_progress::RESULT_UNCHANGED, $lower);
        $this->assertSame(flow_progress::RESULT_REPLACED, $higher);
        $this->assertSame(1, $DB->count_records(flow_progress::TABLE));
        $row = $DB->get_record(flow_progress::TABLE, []);
        $this->assertSame(4, (int) $row->revision);
        $this->assertSame('unavailable', $row->method);
        $this->assertSame(3000, (int) $row->timecompleted);
    }

    /**
     * The map id is compared without regard to case, so one map is never two.
     */
    public function test_the_map_id_is_stored_in_lower_case(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();

        flow_progress::record($course->id, $user->id, strtoupper(self::MAP), 'video', 1, 'completed');
        flow_progress::record($course->id, $user->id, self::MAP, 'video', 1, 'completed');

        $this->assertSame(1, $DB->count_records(flow_progress::TABLE));
        $this->assertSame(self::MAP, $DB->get_field(flow_progress::TABLE, 'mapid', []));
        $this->assertCount(1, flow_progress::get_items($course->id, $user->id, strtoupper(self::MAP)));
    }

    /**
     * A user has at most 500 rows in a course; a repeat of one of them still works at the limit.
     */
    public function test_the_row_cap(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $othercourse = $this->getDataGenerator()->create_course();

        $rows = [];
        for ($i = 0; $i < flow_progress::MAX_ROWS; $i++) {
            $rows[] = (object) [
                'courseid' => $course->id, 'userid' => $user->id, 'mapid' => self::MAP, 'stepkey' => 's' . $i,
                'revision' => 1, 'method' => 'completed', 'timecompleted' => 1000,
            ];
        }
        $DB->insert_records(flow_progress::TABLE, $rows);

        $this->assertSame(
            flow_progress::RESULT_CAPPED,
            flow_progress::record($course->id, $user->id, self::MAP, 'extra', 1, 'completed')
        );
        $this->assertSame(flow_progress::MAX_ROWS, $DB->count_records(flow_progress::TABLE));
        $this->assertSame(
            flow_progress::RESULT_REPLACED,
            flow_progress::record($course->id, $user->id, self::MAP, 's0', 2, 'completed')
        );
        $this->assertSame(
            flow_progress::RESULT_STORED,
            flow_progress::record($othercourse->id, $user->id, self::MAP, 'extra', 1, 'completed')
        );
        $this->assertSame(
            flow_progress::RESULT_STORED,
            flow_progress::record($course->id, $other->id, self::MAP, 'extra', 1, 'completed')
        );
    }

    /**
     * Rows are told apart by course, user and map.
     */
    public function test_rows_do_not_cross_users_courses_or_maps(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $othercourse = $this->getDataGenerator()->create_course();

        flow_progress::record($course->id, $user->id, self::MAP, 'a', 1, 'completed');
        flow_progress::record($course->id, $other->id, self::MAP, 'b', 1, 'completed');
        flow_progress::record($othercourse->id, $user->id, self::MAP, 'c', 1, 'completed');
        flow_progress::record($course->id, $user->id, self::OTHERMAP, 'd', 1, 'completed');

        $this->assertSame(['a'], array_column(flow_progress::get_items($course->id, $user->id, self::MAP), 'stepKey'));
        $this->assertSame(['d'], array_column(flow_progress::get_items($course->id, $user->id, self::OTHERMAP), 'stepKey'));
    }

    /**
     * The deletes touch exactly what they name.
     */
    public function test_the_deletes(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $othercourse = $this->getDataGenerator()->create_course();
        foreach ([$course, $othercourse] as $c) {
            foreach ([$user, $other] as $u) {
                foreach ([self::MAP, self::OTHERMAP] as $map) {
                    flow_progress::record($c->id, $u->id, $map, 'a', 1, 'completed');
                }
            }
        }

        flow_progress::reset_map($course->id, $user->id, self::MAP);
        $this->assertSame(7, $DB->count_records(flow_progress::TABLE));

        flow_progress::delete_for_user($user->id, $course->id);
        $this->assertSame(6, $DB->count_records(flow_progress::TABLE));

        flow_progress::delete_for_user($user->id);
        $this->assertSame(4, $DB->count_records(flow_progress::TABLE));
        $this->assertSame(0, $DB->count_records(flow_progress::TABLE, ['userid' => $user->id]));

        flow_progress::delete_for_course($course->id);
        $this->assertSame(0, $DB->count_records(flow_progress::TABLE, ['courseid' => $course->id]));
        $this->assertSame(2, $DB->count_records(flow_progress::TABLE));
    }
}
