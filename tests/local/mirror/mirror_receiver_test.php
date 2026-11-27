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

namespace format_rumbos\local\mirror;

use format_rumbos\mirror_fixture;
use format_rumbos\local\pseudonym;
use format_rumbos\local\result_receiver;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../fixtures/result_testcase.php');
require_once(__DIR__ . '/../../fixtures/mirror_fixture.php');

/**
 * Tests for the mirror receiver: signed messages in, quizzes and attempts out, and never a grade or a completion.
 *
 * The wire format is provisional (fixtures shaped like the service's forms); these tests pin what the plugin does with
 * it, not what the service sends.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(mirror_receiver::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(mirror_rate_limit::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(mirror_config::class)]
final class mirror_receiver_test extends \format_rumbos\result_testcase {
    /** @var \stdClass The course. */
    private \stdClass $course;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->course = $this->make_course();
        set_config('mirrorenabled', 1, 'format_rumbos');
    }

    /**
     * Posts a signed message of the mirror.
     *
     * @param array $claims
     * @return array The reply.
     */
    private function mirror(array $claims): array {
        return mirror_receiver::handle('POST', json_encode(['statement' => $this->sign($claims)]), $this->now);
    }

    /**
     * The claims of an exam definition.
     *
     * @param array $override
     * @return array
     */
    private function definition(array $override = []): array {
        return $override + $this->claims($this->course, $this->getDataGenerator()->create_user()) + [
            'kind' => 'exam_definition',
            'exam' => mirror_fixture::form(),
        ];
    }

    /**
     * The claims of an attempt of a student.
     *
     * @param \stdClass $user
     * @param string $attemptid
     * @param array $section
     * @param bool $passed
     * @return array
     */
    private function attempt(\stdClass $user, string $attemptid, array $section, bool $passed = false): array {
        $total = mirror_fixture::total105($section);
        return ['kind' => 'exam_attempt', 'sub' => pseudonym::for_user((int) $user->id), 'exam' => mirror_fixture::form(),
            'attempt' => mirror_fixture::attempt(
                mirror_fixture::form(),
                $attemptid,
                mirror_fixture::item_points($section),
                mirror_fixture::display($total),
                $passed
            )] + $this->claims($this->course, $user);
    }

    public function test_it_is_off_by_default(): void {
        unset_config('mirrorenabled', 'format_rumbos');

        $reply = $this->mirror($this->definition());

        $this->assert_reply($reply, 503, 'not_configured');
        $this->assertSame(0, \count(mirror_store::recent_log(false)));
    }

    public function test_it_needs_the_signature_settings_too(): void {
        set_config('resultkeys', '', 'format_rumbos');

        $this->assert_reply($this->mirror($this->definition()), 503, 'not_configured');
    }

    public function test_only_post_is_accepted(): void {
        $reply = mirror_receiver::handle('GET', '', $this->now);

        $this->assertSame(405, $reply['status']);
    }

    public function test_a_bad_signature_changes_nothing_and_is_not_logged(): void {
        global $DB;
        $statement = $this->sign($this->definition());
        $statement = substr($statement, 0, -6) . strrev(substr($statement, -6));

        $reply = mirror_receiver::handle('POST', json_encode(['statement' => $statement]), $this->now);

        $this->assertSame(401, $reply['status']);
        $this->assertSame(0, $DB->count_records(mirror_store::QUIZZES));
        $this->assertSame([], mirror_store::recent_log(false));
    }

    public function test_a_malformed_body_is_refused(): void {
        $this->assertSame(400, mirror_receiver::handle('POST', 'nope', $this->now)['status']);
        $this->assertSame(400, mirror_receiver::handle('POST', json_encode(['statement' => '']), $this->now)['status']);
        $huge = str_repeat('x', mirror_receiver::MAX_BODY + 1);
        $this->assertSame(400, mirror_receiver::handle('POST', $huge, $this->now)['status']);
    }

    public function test_a_definition_makes_the_quiz_once(): void {
        global $DB;

        $first = $this->mirror($this->definition());
        $second = $this->mirror($this->definition());

        $this->assert_reply($first, 200, 'created', true);
        $this->assert_reply($second, 200, 'already_mirrored', false);
        $this->assertSame(1, $DB->count_records(mirror_store::QUIZZES));
        $row = mirror_store::find_quiz((int) $this->course->id, 'b2-form-a', 'v1hash');
        $this->assertSame('0', $DB->get_field('course_modules', 'visible', ['id' => $row->cmid]));
    }

    public function test_a_new_version_makes_a_new_quiz(): void {
        global $DB;
        $this->mirror($this->definition());
        $user = $this->make_user($this->course);
        $this->mirror($this->attempt($user, 'att-1', [6, 1, 3, 5, 5]));

        $reply = $this->mirror($this->definition(['exam' => mirror_fixture::form('v2hash')]));

        $this->assert_reply($reply, 200, 'created', true);
        $this->assertSame(2, $DB->count_records(mirror_store::QUIZZES));
        // The attempt stays in the quiz of the version it was taken on.
        $old = mirror_store::find_quiz((int) $this->course->id, 'b2-form-a', 'v1hash');
        $this->assertSame((int) $old->id, (int) $DB->get_field(mirror_store::ATTEMPTS, 'mirrorid', ['attemptid' => 'att-1']));
    }

    public function test_an_attempt_is_written_once_for_the_student(): void {
        global $DB;
        $user = $this->make_user($this->course);

        $first = $this->mirror($this->attempt($user, 'att-1', [6, 1, 3, 5, 5]));
        $second = $this->mirror($this->attempt($user, 'att-1', [6, 1, 3, 5, 5]));

        $this->assert_reply($first, 200, 'written', true);
        $this->assert_reply($second, 200, 'already_written', false);
        $row = mirror_store::find_attempt('att-1');
        $this->assertEquals($user->id, $row->userid);
        $this->assertSame(1, $DB->count_records('quiz_attempts', ['userid' => $user->id, 'preview' => 0]));
    }

    public function test_an_attempt_that_arrives_before_its_definition_makes_the_quiz(): void {
        global $DB;
        $user = $this->make_user($this->course);

        $reply = $this->mirror($this->attempt($user, 'att-1', [7, 3, 3, 5, 5], true));

        $this->assert_reply($reply, 200, 'written', true);
        $this->assertSame(1, $DB->count_records(mirror_store::QUIZZES));
    }

    public function test_a_preview_is_never_accepted(): void {
        global $DB;
        $user = $this->make_user($this->course);
        $claims = $this->attempt($user, 'att-1', [6, 1, 3, 5, 5]);
        $claims['attempt']['preview'] = true;

        $reply = $this->mirror($claims);

        $this->assert_reply($reply, 422, 'preview_not_accepted');
        $this->assertSame(0, $DB->count_records(mirror_store::ATTEMPTS));
        $this->assertSame('rejected', array_values(mirror_store::recent_log(false))[0]->outcome);
    }

    public function test_an_unknown_student_or_a_teacher_is_refused(): void {
        $user = $this->make_user($this->course);
        $teacher = $this->make_user($this->course, 'editingteacher');
        $unknown = $this->attempt($user, 'att-1', [6, 1, 3, 5, 5]);
        $unknown['sub'] = str_repeat('a', 43);

        $this->assert_reply($this->mirror($unknown), 422, 'unknown_user');
        $this->assert_reply($this->mirror($this->attempt($teacher, 'att-2', [6, 1, 3, 5, 5])), 422, 'role_not_allowed');
        $this->assertNull(mirror_store::find_attempt('att-1'));
        $this->assertNull(mirror_store::find_attempt('att-2'));
    }

    public function test_unknown_kinds_and_courses_are_refused(): void {
        $this->assert_reply($this->mirror($this->definition(['kind' => 'something'])), 422, 'unknown_kind');
        $this->assert_reply($this->mirror($this->definition(['course_id' => 99999])), 422, 'unknown_course');
        $this->assert_reply($this->mirror($this->definition(['exam' => 'text'])), 422, 'invalid_definition');
    }

    public function test_an_invalid_exam_is_a_permanent_refusal_with_a_log_line(): void {
        $exam = mirror_fixture::form();
        $exam['sections'][0]['items'][0]['options'][1]['text'] = $exam['sections'][0]['items'][0]['options'][0]['text'];

        $reply = $this->mirror($this->definition(['exam' => $exam]));

        $this->assertSame(422, $reply['status']);
        $line = array_values(mirror_store::recent_log())[0];
        $this->assertSame('rejected', $line->outcome);
        $this->assertStringStartsWith('invalid_definition', $line->reason);
    }

    /**
     * What the mirror does at exactly the threshold: the service says fail, Moodle's inclusive grade to pass would have
     * said pass, and neither completes the course. The passback is the only thing that grants completion.
     */
    public function test_the_mirror_never_completes_a_course_and_the_passback_still_does(): void {
        $user = $this->make_user($this->course);
        $at = mirror_fixture::threshold_cases()['at'];

        $this->assert_reply($this->mirror($this->attempt($user, 'att-at', $at, false)), 200, 'written', true);

        $this->assertNull($this->grade_cents($this->course, $user));
        $this->assertFalse($this->is_complete($this->course, $user));
        $this->assertSame(0, \count(\format_rumbos\local\result_store::find_by_attempt('att-at') ? [1] : []));

        // Even a mirrored pass does nothing by itself: the same attempt, mirrored as a pass, completes nothing.
        $above = mirror_fixture::threshold_cases()['above'];
        $this->mirror($this->attempt($user, 'att-above', $above, true));
        $this->assertFalse($this->is_complete($this->course, $user));

        // The passback of that result, a separate request, grants the grade and the completion.
        $reply = result_receiver::handle('POST', json_encode(['statement' => $this->sign($this->claims($this->course, $user, [
            'attempt_id' => 'att-above', 'percent' => '80.10', 'passed' => true,
        ]))]), $this->now);
        $this->assertSame(200, $reply['status']);
        $this->assertSame(8010, $this->grade_cents($this->course, $user));
        $this->assertTrue($this->is_complete($this->course, $user));
    }

    /**
     * A failed mirror is answered with an error the sender retries, and neither stops the passback nor leaves half an
     * attempt; the retry then succeeds.
     */
    public function test_a_failure_is_transient_and_never_blocks_the_passback(): void {
        global $DB;
        $user = $this->make_user($this->course);
        $this->mirror($this->definition());
        $mirror = mirror_store::find_quiz((int) $this->course->id, 'b2-form-a', 'v1hash');
        $item = $DB->get_record(mirror_store::ITEMS, ['mirrorid' => $mirror->id, 'itemid' => 'u-03']);
        $DB->delete_records(mirror_store::ITEMS, ['id' => $item->id]);

        $failed = $this->mirror($this->attempt($user, 'att-1', [7, 3, 3, 5, 5], true));

        $this->assert_reply($failed, 503, 'exam_not_mirrored');
        $this->assertNull(mirror_store::find_attempt('att-1'));
        $this->assertSame(0, $DB->count_records('quiz_attempts', ['quiz' => $mirror->quizid]));
        $problem = array_values(mirror_store::recent_log())[0];
        $this->assertSame(['rejected', '503', 'att-1'], [$problem->outcome, $problem->status, $problem->attemptid]);

        // The passback of the same attempt is unaffected.
        $passback = result_receiver::handle('POST', json_encode(['statement' => $this->sign($this->claims($this->course, $user, [
            'attempt_id' => 'att-1', 'percent' => '100.00', 'passed' => true,
        ]))]), $this->now);
        $this->assertSame(200, $passback['status']);
        $this->assertTrue($this->is_complete($this->course, $user));

        // The sender retries once the mapping is whole again.
        unset($item->id);
        $DB->insert_record(mirror_store::ITEMS, $item);
        $this->assert_reply($this->mirror($this->attempt($user, 'att-1', [7, 3, 3, 5, 5], true)), 200, 'written', true);
    }

    /**
     * The mirror has its own allowance: using it up leaves the passback's untouched.
     */
    public function test_the_rate_limit_is_separate_from_the_passbacks(): void {
        $user = $this->make_user($this->course);
        for ($i = 0; $i < mirror_rate_limit::LIMIT; $i++) {
            mirror_receiver::handle('POST', '', $this->now);
        }

        $this->assertSame(429, mirror_receiver::handle('POST', '', $this->now)['status']);
        $reply = result_receiver::handle(
            'POST',
            json_encode(['statement' => $this->sign($this->claims($this->course, $user))]),
            $this->now
        );
        $this->assertSame(200, $reply['status']);
    }
}
