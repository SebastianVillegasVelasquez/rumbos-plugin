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

use core\context\course as context_course;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\writer;
use format_rumbos\privacy\provider;

/**
 * The closing flow keeps its per-user state in the same table as the welcome flow, one row per step key.
 *
 * Nothing here is new storage: these tests pin how the closing flow uses it (what "seen", "completed" and a
 * recorded escape are, that a repeat changes nothing, that a recorded escape is not overwritten, and that the
 * privacy export and delete cover it), so a change to the table cannot silently break the closing flow.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\local\flow_progress::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\privacy\provider::class)]
final class closing_flow_test extends \core_privacy\tests\provider_testcase {
    /** @var string A map id. */
    private const MAP = '3f0c2a52-6c1e-4f6e-9a8e-0d6b1f9a7c11';

    /**
     * A course with two students.
     *
     * @return array [course, student, other student]
     */
    private function course(): array {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'rumbos']);
        return [$course, $generator->create_and_enrol($course, 'student'), $generator->create_and_enrol($course, 'student')];
    }

    /**
     * A closing journey: content steps are done, the final exam is escaped, and each state reads back as recorded.
     */
    public function test_a_closing_journey_is_stored_step_by_step(): void {
        [$course, $student] = $this->course();

        $record = fn(string $key, string $method, int $time) =>
            flow_progress::record($course->id, $student->id, self::MAP, $key, 1, $method, $time);
        $this->assertSame('stored', $record('closing-intro', 'completed', 1700000000));
        $this->assertSame('stored', $record('closing-pdf', 'completed', 1700000060));
        $this->assertSame('stored', $record('final-exam', 'fallback', 1700000120));

        $items = flow_progress::get_items($course->id, $student->id, self::MAP);

        $this->assertSame(['closing-intro', 'closing-pdf', 'final-exam'], array_column($items, 'stepKey'));
        $this->assertSame(['completed', 'completed', 'fallback'], array_column($items, 'method'));
        $this->assertSame('2023-11-14T22:15:20Z', $items[2]['completedAt']);
    }

    /**
     * Repeating a closing step changes nothing, and a recorded escape is not turned into a completion.
     */
    public function test_a_recorded_escape_is_kept_and_a_repeat_changes_nothing(): void {
        global $DB;
        [$course, $student] = $this->course();
        flow_progress::record($course->id, $student->id, self::MAP, 'final-exam', 1, 'fallback', 1700000000);

        $repeat = flow_progress::record($course->id, $student->id, self::MAP, 'final-exam', 1, 'fallback', 1700009999);
        $completed = flow_progress::record($course->id, $student->id, self::MAP, 'final-exam', 1, 'completed', 1700009999);

        $this->assertSame(['unchanged', 'unchanged'], [$repeat, $completed]);
        $row = $DB->get_record(flow_progress::TABLE, ['userid' => $student->id]);
        $this->assertSame(['fallback', 1700000000], [$row->method, (int) $row->timecompleted]);
        $this->assertSame(1, $DB->count_records(flow_progress::TABLE));
    }

    /**
     * The welcome flow and the closing flow of one map are told apart only by the step key.
     *
     * The service refuses a key that both flows use, because they would share this row.
     */
    public function test_the_two_flows_of_a_map_do_not_mix_when_their_keys_differ(): void {
        [$course, $student, $other] = $this->course();
        flow_progress::record($course->id, $student->id, self::MAP, 'welcome', 1, 'completed');
        flow_progress::record($course->id, $student->id, self::MAP, 'final-exam', 1, 'completed');
        flow_progress::record($course->id, $other->id, self::MAP, 'final-exam', 1, 'fallback');

        $mine = flow_progress::get_items($course->id, $student->id, self::MAP);
        $theirs = flow_progress::get_items($course->id, $other->id, self::MAP);

        $this->assertEqualsCanonicalizing(['welcome', 'final-exam'], array_column($mine, 'stepKey'));
        $this->assertSame(['fallback'], array_column($theirs, 'method'));
        // A shared key is one row: the second flow's write is the first one's repeat.
        $this->assertSame('unchanged', flow_progress::record($course->id, $student->id, self::MAP, 'welcome', 1, 'completed'));
    }

    /**
     * The privacy export lists the closing steps with their method, and erasing the user removes them.
     */
    public function test_the_closing_state_is_exported_and_erased_with_the_user(): void {
        global $DB;
        [$course, $student, $other] = $this->course();
        $context = context_course::instance($course->id);
        flow_progress::record($course->id, $student->id, self::MAP, 'closing-intro', 1, 'completed', 1700000000);
        flow_progress::record($course->id, $student->id, self::MAP, 'final-exam', 1, 'fallback', 1700000120);
        flow_progress::record($course->id, $other->id, self::MAP, 'final-exam', 1, 'completed', 1700000200);

        provider::export_user_data(new approved_contextlist($student, 'format_rumbos', [$context->id]));
        $data = writer::with_context($context)->get_data([get_string('privacy:path:flow', 'format_rumbos')]);

        $this->assertEqualsCanonicalizing(['closing-intro', 'final-exam'], array_column($data->steps, 'step'));
        $this->assertEqualsCanonicalizing(['completed', 'fallback'], array_column($data->steps, 'method'));

        provider::delete_data_for_user(new approved_contextlist($student, 'format_rumbos', [$context->id]));

        $this->assertSame(0, $DB->count_records(flow_progress::TABLE, ['userid' => $student->id]));
        $this->assertSame(1, $DB->count_records(flow_progress::TABLE, ['userid' => $other->id]));
    }
}
