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

use format_rumbos\event\content_autocomplete_failed;
use format_rumbos\event\content_autocompleted;
use format_rumbos\task\autocomplete_content;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/../fixtures/result_testcase.php');
require_once($CFG->libdir . '/completionlib.php');

/**
 * Tests for marking the content complete when a verified result says the student passed.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\local\content_autocomplete::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\task\autocomplete_content::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\event\content_autocompleted::class)]
final class content_autocomplete_test extends \format_rumbos\result_testcase {
    /**
     * A configured course with a student and two tracked SCORM activities, one automatic and one manual.
     *
     * @return array [course, student, cm A, cm B]
     */
    private function setup_content(): array {
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $course = $this->make_course();
        $student = $this->make_user($course);
        $a = $gen->create_module('scorm', [
            'course' => $course->id, 'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionview' => 1,
        ]);
        $b = $gen->create_module('scorm', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $this->setUser(null);
        return [$course, $student, (int) $a->cmid, (int) $b->cmid];
    }

    /**
     * The completion state of an activity for the student, read from the database.
     *
     * @param \stdClass $course
     * @param int $cmid
     * @param \stdClass $student
     * @return int
     */
    private function state(\stdClass $course, int $cmid, \stdClass $student): int {
        global $DB;
        $row = $DB->get_record('course_modules_completion', ['coursemoduleid' => $cmid, 'userid' => $student->id]);
        return $row ? (int) $row->completionstate : COMPLETION_INCOMPLETE;
    }

    /**
     * The outcome of running the auto-completion for a statement id.
     *
     * @param \stdClass $course
     * @param \stdClass $student
     * @param string $jti
     * @return string
     */
    private function run_for(\stdClass $course, \stdClass $student, string $jti): string {
        return content_autocomplete::run((int) $course->id, (int) $student->id, $jti)['outcome'];
    }

    /**
     * When each completion row of the student was last changed, by activity.
     *
     * @param \stdClass $student
     * @return array
     */
    private function completion_times(\stdClass $student): array {
        global $DB;
        return $DB->get_records_menu('course_modules_completion', ['userid' => $student->id], '', 'coursemoduleid, timemodified');
    }

    /**
     * The setting is off by default and then a pass changes nothing but the result.
     */
    public function test_it_is_off_by_default(): void {
        [$course, $student, $a, $b] = $this->setup_content();
        $this->assertFalse(config::is_autocomplete_enabled());
        $sink = $this->redirectEvents();

        $this->assert_reply($this->send($this->claims($course, $student)), 200, 'completed');

        $this->assertSame(COMPLETION_INCOMPLETE, $this->state($course, $a, $student));
        $this->assertSame(COMPLETION_INCOMPLETE, $this->state($course, $b, $student));
        $this->assertCount(0, array_filter($sink->get_events(), fn($e) => $e instanceof content_autocompleted));
    }

    /**
     * A pass completes the set, with the administrator as the override, one event per activity, and nothing deleted.
     */
    public function test_a_pass_completes_the_content(): void {
        global $DB, $USER;
        [$course, $student, $a, $b] = $this->setup_content();
        set_config('autocompletecontent', 1, 'format_rumbos');
        $this->setUser($student);
        $before = (int) $USER->id;
        $sink = $this->redirectEvents();
        $statement = $this->claims($course, $student);

        $this->assert_reply($this->send($statement), 200, 'completed');

        $this->assertSame($before, (int) $USER->id, 'the current user is back as it was');
        foreach ([$a, $b] as $cmid) {
            $this->assertSame(COMPLETION_COMPLETE, $this->state($course, $cmid, $student));
            $row = $DB->get_record('course_modules_completion', ['coursemoduleid' => $cmid, 'userid' => $student->id]);
            $this->assertSame((string) get_admin()->id, (string) $row->overrideby, 'who: recorded as an override');
        }
        $events = array_values(array_filter($sink->get_events(), fn($e) => $e instanceof content_autocompleted));
        $this->assertCount(2, $events);
        foreach ($events as $event) {
            $this->assertSame((int) $student->id, (int) $event->relateduserid);
            $this->assertSame($statement['jti'], $event->other['jti'], 'why: the verified statement');
            $this->assertSame('check_in', $event->other['purpose']);
        }
        $this->assertTrue($this->is_complete($course, $student));
    }

    /**
     * A pass in the final exam does the same as one in the check-in.
     */
    public function test_a_final_pass_completes_the_content_too(): void {
        [$course, $student, $a, $b] = $this->setup_content();
        set_config('autocompletecontent', 1, 'format_rumbos');

        $this->assert_reply($this->send($this->claims($course, $student, ['purpose' => 'final'])), 200, 'completed');

        $this->assertSame(COMPLETION_COMPLETE, $this->state($course, $a, $student));
        $this->assertSame(COMPLETION_COMPLETE, $this->state($course, $b, $student));
    }

    /**
     * A result that is not a pass changes no completion.
     */
    public function test_a_non_pass_does_nothing(): void {
        [$course, $student, $a, $b] = $this->setup_content();
        set_config('autocompletecontent', 1, 'format_rumbos');
        $sink = $this->redirectEvents();

        $nopass = $this->claims($course, $student, ['passed' => false, 'percent' => '40.00']);
        $this->assert_reply($this->send($nopass), 200, 'recorded');

        $this->assertSame(COMPLETION_INCOMPLETE, $this->state($course, $a, $student));
        $this->assertSame(COMPLETION_INCOMPLETE, $this->state($course, $b, $student));
        $this->assertCount(0, array_filter($sink->get_events(), fn($e) => $e instanceof content_autocompleted));
    }

    /**
     * A statement that does not verify never reaches it, and a result row of another user or a failing one is not a reason.
     */
    public function test_an_unverified_statement_does_nothing(): void {
        global $DB;
        [$course, $student, $a] = $this->setup_content();
        set_config('autocompletecontent', 1, 'format_rumbos');

        // A statement signed with another key is refused by the receiver before anything else.
        $forged = $this->claims($course, $student);
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($other, $pem);
        $jws = \Firebase\JWT\JWT::encode($forged, $pem, 'RS256', result_config::key_id(self::$publicpem));
        $reply = $this->post($jws);
        $this->assertSame(401, $reply['status']);
        $this->assertSame(COMPLETION_INCOMPLETE, $this->state($course, $a, $student));

        // Called directly, with nothing behind it, with a failing result and with somebody else's passing one.
        $this->assertSame(content_autocomplete::UNVERIFIED, $this->run_for($course, $student, 'nope'));
        $failing = $this->claims($course, $student, ['passed' => false, 'percent' => '10.00']);
        $this->assert_reply($this->send($failing), 200, 'recorded');
        $this->assertSame(content_autocomplete::UNVERIFIED, $this->run_for($course, $student, $failing['jti']));

        $other = $this->make_user($course);
        $this->assert_reply($this->send($this->claims($course, $other)), 200, 'completed');
        $theirs = $DB->get_record('format_rumbos_result', ['userid' => $other->id], '*', MUST_EXIST);
        $this->assertSame(COMPLETION_INCOMPLETE, $this->state($course, $a, $student));
        $this->assertSame(content_autocomplete::UNVERIFIED, $this->run_for($course, $student, $theirs->jti));
        $this->assertSame(COMPLETION_INCOMPLETE, $this->state($course, $a, $student));
    }

    /**
     * Nothing in the plugin lets a browser reach it: no web service function mentions it.
     */
    public function test_the_browser_has_no_way_to_trigger_it(): void {
        global $CFG;
        $functions = [];
        require($CFG->dirroot . '/course/format/rumbos/db/services.php');
        $this->assertNotEmpty($functions);
        foreach ($functions as $name => $definition) {
            $text = strtolower($name . ' ' . $definition['classname'] . ' ' . $definition['description']);
            $this->assertStringNotContainsString('autocomplet', $text, $name);
            $this->assertStringNotContainsString('override', $text, $name);
        }
        $this->assertFalse(class_exists('format_rumbos\external\autocomplete_content'));
    }

    /**
     * A delivery that arrives again changes nothing: no new event, the same state.
     */
    public function test_redelivery_is_idempotent(): void {
        global $DB;
        [$course, $student, $a, $b] = $this->setup_content();
        set_config('autocompletecontent', 1, 'format_rumbos');
        $statement = $this->claims($course, $student);
        $this->assert_reply($this->send($statement), 200, 'completed');
        $times = $this->completion_times($student);
        $sink = $this->redirectEvents();

        $this->assert_reply($this->send($statement), 200, 'already_applied');

        $this->assertCount(0, array_filter($sink->get_events(), fn($e) => $e instanceof content_autocompleted));
        $this->assertEquals($times, $this->completion_times($student));
        $this->assertSame(COMPLETION_COMPLETE, $this->state($course, $a, $student));
    }

    /**
     * What the student cannot see is left alone, and what was already complete is not touched.
     */
    public function test_hidden_deleted_untracked_and_already_complete_activities(): void {
        global $DB;
        [$course, $student, $a, $b] = $this->setup_content();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $hidden = $gen->create_module('scorm', [
            'course' => $course->id, 'visible' => 0, 'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $deleting = $gen->create_module('scorm', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $untracked = $gen->create_module('scorm', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_NONE]);
        $page = $gen->create_module('page', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $DB->set_field('course_modules', 'deletioninprogress', 1, ['id' => $deleting->cmid]);
        rebuild_course_cache((int) $course->id, true);
        // The student completed B by themselves before: it is already complete and must stay as it is.
        $info = new \completion_info(get_course($course->id));
        $this->setUser($student);
        $info->update_state(get_fast_modinfo($course->id, $student->id)->get_cm($b), COMPLETION_COMPLETE, (int) $student->id);
        $this->setUser(null);
        set_config('autocompletecontent', 1, 'format_rumbos');
        $sink = $this->redirectEvents();

        $this->assert_reply($this->send($this->claims($course, $student)), 200, 'completed');

        $this->assertSame(COMPLETION_COMPLETE, $this->state($course, $a, $student));
        $this->assertSame(COMPLETION_INCOMPLETE, $this->state($course, (int) $hidden->cmid, $student), 'hidden');
        $this->assertSame(COMPLETION_INCOMPLETE, $this->state($course, (int) $deleting->cmid, $student), 'being deleted');
        $this->assertSame(COMPLETION_INCOMPLETE, $this->state($course, (int) $untracked->cmid, $student), 'tracks nothing');
        $this->assertSame(COMPLETION_INCOMPLETE, $this->state($course, (int) $page->cmid, $student), 'not a content module');
        $row = $DB->get_record('course_modules_completion', ['coursemoduleid' => $b, 'userid' => $student->id]);
        $this->assertNull($row->overrideby, 'completed by the student, left alone');
        $events = array_filter($sink->get_events(), fn($e) => $e instanceof content_autocompleted);
        $this->assertSame([$a], array_values(array_map(fn($e) => (int) $e->objectid, $events)));
    }

    /**
     * An activity restricted by the completion of another becomes visible when that one completes, and is then completed too.
     */
    public function test_an_activity_unlocked_by_another_is_completed_as_well(): void {
        global $DB;
        [$course, $student, $a] = $this->setup_content();
        $this->setAdminUser();
        $locked = $this->getDataGenerator()->create_module('scorm', [
            'course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL,
            'availability' => json_encode(['op' => '&', 'showc' => [false], 'c' => [
                ['type' => 'completion', 'cm' => $a, 'e' => COMPLETION_COMPLETE],
            ]]),
        ]);
        $this->setUser(null);
        rebuild_course_cache((int) $course->id, true);
        set_config('autocompletecontent', 1, 'format_rumbos');

        $this->assert_reply($this->send($this->claims($course, $student)), 200, 'completed');

        $this->assertSame(COMPLETION_COMPLETE, $this->state($course, (int) $locked->cmid, $student));
    }

    /**
     * A failed activity is first overridden to incomplete, as a teacher does by hand, then to complete.
     */
    public function test_a_failed_state_is_completed(): void {
        [$course, $student, $a] = $this->setup_content();
        $info = new \completion_info(get_course($course->id));
        $cm = get_fast_modinfo($course->id, $student->id)->get_cm($a);
        $data = $info->get_data($cm, false, (int) $student->id);
        $data->completionstate = COMPLETION_COMPLETE_FAIL;
        $info->internal_set_data($cm, $data);
        $this->assertSame(COMPLETION_COMPLETE_FAIL, $this->state($course, $a, $student));
        set_config('autocompletecontent', 1, 'format_rumbos');

        $this->assert_reply($this->send($this->claims($course, $student)), 200, 'completed');

        $this->assertSame(COMPLETION_COMPLETE, $this->state($course, $a, $student));
    }

    /**
     * Makes one activity fail when it is completed, by a hook callback that throws.
     *
     * @param int $cmid
     */
    private function fail_on(int $cmid): void {
        $callback = function (\core_completion\hook\after_cm_completion_updated $hook) use ($cmid): void {
            if ((int) $hook->cm->id === $cmid) {
                throw new \RuntimeException('simulated failure');
            }
        };
        \core\di::get(\core\hook\manager::class)
            ->phpunit_redirect_hook(\core_completion\hook\after_cm_completion_updated::class, $callback);
    }

    /**
     * Stops making the activity fail.
     */
    private function stop_failing(): void {
        \core\di::get(\core\hook\manager::class)->phpunit_stop_redirections();
    }

    /**
     * One activity failing does not block the result, is logged, and queues a retry that finishes the job.
     */
    public function test_partial_failure_never_blocks_the_result(): void {
        global $DB;
        [$course, $student, $a, $b] = $this->setup_content();
        set_config('autocompletecontent', 1, 'format_rumbos');
        $this->fail_on($b);
        $sink = $this->redirectEvents();
        $statement = $this->claims($course, $student);

        $reply = $this->send($statement);

        // The result is applied whatever happened to the content.
        $this->assert_reply($reply, 200, 'completed');
        $this->assertSame(9000, $this->grade_cents($course, $student));
        $this->assertTrue($this->is_complete($course, $student));
        $this->assertSame(COMPLETION_COMPLETE, $this->state($course, $a, $student));
        // The failure is an event the administrator can read.
        $failed = array_values(array_filter($sink->get_events(), fn($e) => $e instanceof content_autocomplete_failed));
        $this->assertCount(1, $failed);
        $this->assertSame([$b], $failed[0]->other['cmids']);
        $this->assertSame($statement['jti'], $failed[0]->other['jti']);
        // A retry is queued, and once.
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(autocomplete_content::class));
        content_autocomplete::after_result((int) $course->id, (int) $student->id, $statement['jti']);
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(autocomplete_content::class), 'not queued twice');
        $this->assertCount(1, $DB->get_records('format_rumbos_result', ['userid' => $student->id]));
    }

    /**
     * The retry task throws while something still fails (so Moodle repeats it) and succeeds once it can.
     */
    public function test_the_retry_task_repeats_until_it_works(): void {
        [$course, $student, $a, $b] = $this->setup_content();
        set_config('autocompletecontent', 1, 'format_rumbos');
        $statement = $this->claims($course, $student);
        // Apply the result with the content off, then turn it on: the pass exists, the content is not done.
        set_config('autocompletecontent', 0, 'format_rumbos');
        $this->assert_reply($this->send($statement), 200, 'completed');
        set_config('autocompletecontent', 1, 'format_rumbos');
        $this->fail_on($b);

        $task = new autocomplete_content();
        $task->set_custom_data(['courseid' => (int) $course->id, 'userid' => (int) $student->id, 'jti' => $statement['jti']]);
        try {
            $task->execute();
            $this->fail('A task that did not finish must throw so that Moodle retries it.');
        } catch (\moodle_exception $e) {
            $this->assertSame('autocompleteretry', $e->errorcode);
        }

        $this->stop_failing();
        $task->execute();
        $this->assertSame(COMPLETION_COMPLETE, $this->state($course, $b, $student));
    }

    /**
     * A re-delivery of the statement is also a retry: it finishes what failed the first time.
     */
    public function test_a_redelivery_retries_what_failed(): void {
        [$course, $student, $a, $b] = $this->setup_content();
        set_config('autocompletecontent', 1, 'format_rumbos');
        $statement = $this->claims($course, $student);
        $this->fail_on($b);
        $this->assert_reply($this->send($statement), 200, 'completed');
        // Break the state the failure left behind: the activity is not complete.
        $this->stop_failing();
        $info = new \completion_info(get_course($course->id));
        $cm = get_fast_modinfo($course->id, $student->id)->get_cm($b);
        $data = $info->get_data($cm, false, (int) $student->id);
        $data->completionstate = COMPLETION_INCOMPLETE;
        $info->internal_set_data($cm, $data);

        $this->assert_reply($this->send($statement), 200, 'already_applied');

        $this->assertSame(COMPLETION_COMPLETE, $this->state($course, $b, $student));
    }

    /**
     * Nothing is deleted: the grade and the result stay, and the student keeps the activities.
     */
    public function test_nothing_is_deleted(): void {
        global $DB;
        [$course, $student, $a] = $this->setup_content();
        set_config('autocompletecontent', 1, 'format_rumbos');
        $this->assert_reply($this->send($this->claims($course, $student)), 200, 'completed');

        $this->assertSame(1, $DB->count_records('format_rumbos_result', ['userid' => $student->id]));
        $this->assertSame(9000, $this->grade_cents($course, $student));
        $this->setUser($student);
        $this->assertTrue(get_fast_modinfo($course->id, $student->id)->get_cm($a)->uservisible);
    }
}
