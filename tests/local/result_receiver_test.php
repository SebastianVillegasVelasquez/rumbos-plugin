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

require_once(__DIR__ . '/../fixtures/result_testcase.php');

/**
 * Tests for the result receiver, end to end except for HTTP: statements in, answers, grades, completions and audit out.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\local\result_receiver::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\local\result_applier::class)]
final class result_receiver_test extends \format_rumbos\result_testcase {
    /**
     * A passing statement records the grade, completes the course once, and is audited.
     */
    public function test_a_valid_passing_statement_completes_the_course(): void {
        global $DB;

        $course = $this->make_course();
        $student = $this->make_user($course);
        $sink = $this->redirectEvents();

        $reply = $this->send($this->claims($course, $student, ['percent' => '85.50', 'purpose' => 'final']));

        $this->assert_reply($reply, 200, 'completed', true);
        $this->assertSame(8550, $this->grade_cents($course, $student));
        $this->assertTrue($this->is_complete($course, $student));
        $completed = array_filter($sink->get_events(), fn($event) => $event instanceof \core\event\course_completed);
        $this->assertCount(1, $completed);
        $this->assertSame(1, $DB->count_records('format_rumbos_result', ['userid' => $student->id, 'passed' => 1]));

        $row = $this->assert_audited(200, 'completed');
        $this->assertSame((int) $student->id, (int) $row->userid);
        $this->assertSame((int) $course->id, (int) $row->courseid);
        $this->assertSame('final', $row->purpose);
        $this->assertSame('85.50', $row->percent);
        $this->assertSame('gt:80', $row->policy);
        $this->assertSame(result_config::key_id(self::$publicpem), $row->kid);
    }

    /**
     * A result that is not a pass records the grade and completes nothing.
     */
    public function test_a_non_passing_statement_only_records_the_grade(): void {
        $course = $this->make_course();
        $student = $this->make_user($course);

        $reply = $this->send($this->claims($course, $student, ['percent' => '79.99', 'passed' => false]));

        $this->assert_reply($reply, 200, 'recorded', true);
        $this->assertSame(7999, $this->grade_cents($course, $student));
        $this->assertFalse($this->is_complete($course, $student));
        $this->assert_audited(200, 'recorded');
    }

    /**
     * The backend decides "passed": Moodle completes on `passed` whatever the percent and the policy.
     */
    public function test_passed_is_the_backends_decision(): void {
        $course = $this->make_course();
        $exactly = $this->make_user($course);
        $above = $this->make_user($course);

        // Exactly 80 under "gt": the backend says no, Moodle does not compare.
        $this->send($this->claims($course, $exactly, ['percent' => '80.00', 'passed' => false]));
        // A policy that was changed to "gte": the same 80 now passes, and Moodle follows.
        $this->send($this->claims($course, $above, [
            'percent' => '80.00', 'passed' => true, 'policy' => ['pass_percent' => 80, 'pass_operator' => 'gte'],
        ]));

        $this->assertFalse($this->is_complete($course, $exactly));
        $this->assertTrue($this->is_complete($course, $above));
    }

    /**
     * The grade is the best of the check-in and the final: a later worse attempt never lowers it.
     */
    public function test_the_grade_is_the_best_and_never_goes_down(): void {
        $course = $this->make_course();
        $student = $this->make_user($course);

        $this->send($this->claims($course, $student, ['percent' => '70.00', 'passed' => false, 'purpose' => 'check_in']));
        $this->assertSame(7000, $this->grade_cents($course, $student));

        $this->send($this->claims($course, $student, ['percent' => '92.25', 'purpose' => 'final']));
        $this->assertSame(9225, $this->grade_cents($course, $student));

        $this->send($this->claims($course, $student, ['percent' => '40.00', 'passed' => false, 'purpose' => 'final']));
        $this->assertSame(9225, $this->grade_cents($course, $student));

        $this->send($this->claims($course, $student, ['percent' => '92.25', 'purpose' => 'final']));
        $this->assertSame(9225, $this->grade_cents($course, $student));
    }

    /**
     * A student who fails the check-in and the final twice and passes the third attempt completes then.
     */
    public function test_failing_then_passing_on_the_third_final_attempt(): void {
        $course = $this->make_course();
        $student = $this->make_user($course);

        $this->send($this->claims($course, $student, ['percent' => '55.00', 'passed' => false, 'purpose' => 'check_in']));
        $this->send($this->claims($course, $student, ['percent' => '62.00', 'passed' => false, 'purpose' => 'final']));
        $this->send($this->claims($course, $student, ['percent' => '71.00', 'passed' => false, 'purpose' => 'final']));
        $this->assertFalse($this->is_complete($course, $student));

        $reply = $this->send($this->claims($course, $student, ['percent' => '88.00', 'purpose' => 'final']));

        $this->assert_reply($reply, 200, 'completed', true);
        $this->assertTrue($this->is_complete($course, $student));
        $this->assertSame(8800, $this->grade_cents($course, $student));
    }

    /**
     * "Check-in or final": either purpose completes, and the course completes exactly once.
     */
    public function test_the_course_completes_exactly_once(): void {
        global $DB;

        $course = $this->make_course();
        $student = $this->make_user($course);
        $sink = $this->redirectEvents();

        $first = $this->send($this->claims($course, $student, ['purpose' => 'check_in', 'percent' => '90.00']));
        $time = $DB->get_field('course_completions', 'timecompleted', ['course' => $course->id, 'userid' => $student->id]);
        $this->waitForSecond();
        $second = $this->send($this->claims($course, $student, ['purpose' => 'final', 'percent' => '95.00']));

        $this->assert_reply($first, 200, 'completed', true);
        $this->assert_reply($second, 200, 'recorded', true);
        $key = ['course' => $course->id, 'userid' => $student->id];
        $this->assertEquals($time, $DB->get_field('course_completions', 'timecompleted', $key));
        $completed = array_filter($sink->get_events(), fn($event) => $event instanceof \core\event\course_completed);
        $this->assertCount(1, $completed);
        $this->assertSame(1, $DB->count_records('course_completion_crit_compl', $key));
    }

    /**
     * Moodle's own completion machinery still works on the result: the report reads it, other activities are untouched.
     */
    public function test_moodles_own_completion_still_evaluates(): void {
        $course = $this->make_course();
        $student = $this->make_user($course);
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL,
        ]);

        $this->send($this->claims($course, $student));

        $info = new \completion_info($course);
        $this->assertTrue($info->is_course_complete($student->id));
        $this->assertTrue($info->is_tracked_user($student->id));
        $completions = $info->get_completions($student->id, COMPLETION_CRITERIA_TYPE_ROLE);
        $this->assertCount(1, $completions);
        $this->assertTrue(reset($completions)->is_complete());
        // The activity progress report, which reads module completions, has nothing forced in it.
        $progress = $info->get_progress_all('u.id = :id', ['id' => $student->id]);
        $this->assertSame([], $progress[$student->id]->progress);
        // The completion of the page, which nobody did, was not forced.
        $cm = get_coursemodule_from_instance('page', $page->id);
        $this->assertEquals(COMPLETION_INCOMPLETE, $info->get_data($cm, false, $student->id)->completionstate);
        // The result grade item is an ordinary item of the gradebook.
        $item = result_course::grade_item((int) $course->id);
        $this->assertNotNull(\grade_grade::fetch(['itemid' => $item->id, 'userid' => $student->id]));
    }

    /**
     * The same statement twice, and the same attempt with a new statement id, change nothing.
     */
    public function test_replay_and_idempotency(): void {
        global $DB;

        $course = $this->make_course();
        $student = $this->make_user($course);
        $claims = $this->claims($course, $student, ['percent' => '88.00']);

        $first = $this->send($claims);
        $replay = $this->send($claims);
        $retry = $this->send(['jti' => 'a-new-statement-id'] + $claims);

        $this->assert_reply($first, 200, 'completed', true);
        $this->assert_reply($replay, 200, 'already_applied', false);
        $this->assert_reply($retry, 200, 'already_applied', false);
        $this->assertSame(1, $DB->count_records('format_rumbos_result', ['userid' => $student->id]));
        $this->assertSame(3, $DB->count_records('format_rumbos_audit', ['userid' => $student->id]));
        $this->assert_audited(200, 'already_applied');
    }

    /**
     * A statement id reused for a different statement is a conflict.
     */
    public function test_the_same_jti_with_a_different_payload_is_a_conflict(): void {
        global $DB;

        $course = $this->make_course();
        $student = $this->make_user($course);
        $this->send($this->claims($course, $student, [
            'jti' => 'shared-jti', 'attempt_id' => 'attempt-one', 'percent' => '60.00', 'passed' => false,
        ]));

        $reply = $this->send($this->claims($course, $student, [
            'jti' => 'shared-jti', 'attempt_id' => 'attempt-two', 'percent' => '99.00',
        ]));

        $this->assert_reply($reply, 409, 'jti_conflict', false);
        $this->assertSame(1, $DB->count_records('format_rumbos_result', ['userid' => $student->id]));
        $this->assertFalse($this->is_complete($course, $student));
        $this->assert_audited(409, 'jti_conflict');
    }

    /**
     * Two requests for one attempt that arrive together: the loser is not applied and the winner is untouched.
     */
    public function test_applying_the_same_attempt_twice_rolls_back_the_second(): void {
        $course = $this->make_course();
        $student = $this->make_user($course);
        $claims = result_statement::verify($this->sign($this->claims($course, $student, ['percent' => '85.00'])), $this->now);
        result_applier::apply($course, (int) $student->id, $claims, $this->now);

        $again = ['jti' => 'another'] + ['percent' => '99.00'] + $claims;
        try {
            result_applier::apply($course, (int) $student->id, $again, $this->now);
            $this->fail('the same attempt was applied twice');
        } catch (\dml_write_exception $e) {
            $this->assertSame(8500, $this->grade_cents($course, $student));
        }
    }

    /**
     * Refusals of the statement itself, each audited.
     */
    public function test_refused_statements_are_audited(): void {
        $course = $this->make_course();
        $student = $this->make_user($course);

        $expired = $this->send($this->claims($course, $student, ['iat' => $this->now - 900, 'exp' => $this->now - 600]));
        $this->assert_reply($expired, 401, 'expired', false);
        $this->assert_audited(401, 'expired');

        $jws = $this->sign($this->claims($course, $student));
        $tampered = $this->post($this->tamper($jws));
        $this->assert_reply($tampered, 401, 'bad_signature', false);
        $this->assert_audited(401, 'bad_signature');

        $unknownkey = $this->post($this->sign($this->claims($course, $student), 'ffffffffffffffff'));
        $this->assert_reply($unknownkey, 401, 'unknown_key', false);
        $this->assert_audited(401, 'unknown_key');

        $malformed = result_receiver::handle('POST', '{"statement": 3}', $this->now);
        $this->assert_reply($malformed, 400, 'malformed', false);
        $this->assert_audited(400, 'malformed');

        $notjson = result_receiver::handle('POST', 'not json', $this->now);
        $this->assert_reply($notjson, 400, 'malformed', false);

        $toolarge = result_receiver::handle('POST', str_repeat('a', result_receiver::MAX_BODY + 1), $this->now);
        $this->assert_reply($toolarge, 400, 'malformed', false);

        $this->assertFalse($this->is_complete($course, $student));
        $this->assertNull($this->grade_cents($course, $student));
    }

    /**
     * Only POST is a statement, and neither a wrong method nor a rate refusal is one to audit.
     */
    public function test_other_methods_are_refused(): void {
        global $DB;

        $reply = result_receiver::handle('GET', '', $this->now);

        $this->assertSame(405, $reply['status']);
        $this->assertSame('POST', $reply['headers']['Allow']);
        $this->assertSame(0, $DB->count_records('format_rumbos_audit'));
    }

    /**
     * A user the course does not have, an unknown course and a course that is not Rumbos are 422.
     */
    public function test_unknown_user_and_course_are_422(): void {
        $course = $this->make_course();
        $outsider = $this->getDataGenerator()->create_user();
        $student = $this->make_user($course);
        $plain = $this->getDataGenerator()->create_course(['format' => 'topics']);
        $this->getDataGenerator()->enrol_user($student->id, $plain->id, 'student');

        $this->assert_reply($this->send($this->claims($course, $outsider)), 422, 'unknown_user', false);
        $this->assert_audited(422, 'unknown_user');

        $this->assert_reply($this->send($this->claims($plain, $student)), 422, 'unknown_course', false);
        $this->assert_audited(422, 'unknown_course');

        $this->assert_reply($this->send($this->claims($course, $student, ['course_id' => 987654])), 422, 'unknown_course', false);
        $site = $this->send($this->claims($course, $student, ['course_id' => (int) SITEID]));
        $this->assert_reply($site, 422, 'unknown_course', false);

        $this->assertNull($this->grade_cents($course, $student));
    }

    /**
     * A student whose enrolment is suspended or ended is not enrolled for this purpose.
     */
    public function test_an_inactive_enrolment_is_refused(): void {
        global $DB;

        $course = $this->make_course();
        $suspended = $this->make_user($course);
        $DB->set_field('user_enrolments', 'status', ENROL_USER_SUSPENDED, ['userid' => $suspended->id]);
        $ended = $this->make_user($course);
        $DB->set_field('user_enrolments', 'timeend', $this->now - 3600, ['userid' => $ended->id]);

        foreach ([$suspended, $ended] as $user) {
            $this->assert_reply($this->send($this->claims($course, $user)), 422, 'not_enrolled', false);
            $this->assert_audited(422, 'not_enrolled');
            $this->assertFalse($this->is_complete($course, $user));
        }
    }

    /**
     * A student of one course is not found in another.
     */
    public function test_a_student_of_another_course_is_unknown(): void {
        $course = $this->make_course();
        $other = $this->make_course();
        $student = $this->make_user($other);

        $this->assert_reply($this->send($this->claims($course, $student)), 422, 'unknown_user', false);
    }

    /**
     * Nobody who is not a student completes the course: a teacher, a manager, an administrator, or a student who also
     * teaches.
     */
    public function test_roles_that_must_not_complete_are_refused(): void {
        $course = $this->make_course();
        $users = [
            'editing teacher' => $this->make_user($course, 'editingteacher'),
            'non editing teacher' => $this->make_user($course, 'teacher'),
            'manager' => $this->make_user($course, 'manager'),
        ];
        $both = $this->make_user($course, 'student');
        $this->getDataGenerator()->enrol_user($both->id, $course->id, 'editingteacher');
        $users['student and teacher'] = $both;

        $admin = get_admin();
        $this->getDataGenerator()->enrol_user($admin->id, $course->id, 'student');
        $users['administrator enrolled as student'] = $admin;

        foreach ($users as $label => $user) {
            $reply = $this->send($this->claims($course, $user));
            $this->assert_reply($reply, 422, 'role_not_allowed', false);
            $this->assert_audited(422, 'role_not_allowed');
            $this->assertFalse($this->is_complete($course, $user), $label);
            $this->assertNull($this->grade_cents($course, $user), $label);
        }
    }

    /**
     * A role switched to Student in a browser is invisible to the receiver: a teacher is still a teacher, and a manager
     * who is not enrolled is still unknown. This is how evaluators previewing the course never complete it.
     *
     * The switch lives in the browser's session, and the receiver is a script without one, so the test drops the
     * session after switching, as the real call has none.
     */
    public function test_a_role_switched_to_student_does_not_make_a_student(): void {
        global $DB;

        $course = $this->make_course();
        $context = \core\context\course::instance($course->id);
        $studentrole = (int) $DB->get_field('role', 'id', ['shortname' => 'student']);

        $teacher = $this->make_user($course, 'editingteacher');
        $this->setUser($teacher);
        role_switch($studentrole, $context);
        $this->assertTrue(is_role_switched($course->id));
        $this->setUser(0);
        $this->assert_reply($this->send($this->claims($course, $teacher)), 422, 'role_not_allowed', false);

        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('manager', $manager->id, \core\context\system::instance()->id);
        $this->setUser($manager);
        role_switch($studentrole, $context);
        $this->assertTrue(is_role_switched($course->id));
        $this->setUser(0);
        $this->assert_reply($this->send($this->claims($course, $manager)), 422, 'unknown_user', false);

        $this->assertFalse($this->is_complete($course, $teacher));
        $this->assertFalse($this->is_complete($course, $manager));
    }

    /**
     * A course that is not set up answers 503 and keeps nothing, so the sender can retry.
     */
    public function test_a_course_that_is_not_set_up_is_503(): void {
        global $DB;

        $course = $this->make_course(false);
        $student = $this->make_user($course);

        $reply = $this->send($this->claims($course, $student));
        $this->assert_reply($reply, 503, 'course_not_configured', false);
        $this->assertSame('course_not_configured:no_grade_item', $this->assert_audited(503, 'course_not_configured')->reason);
        $this->assertSame(0, $DB->count_records('format_rumbos_result'));

        // Only the grade item: the criterion is missing.
        $steps = array_filter(result_course::plan((int) $course->id, '80', 'gt', false), fn($step) => $step['key'] === 'gradeitem'
            || $step['key'] === 'createrole');
        foreach ($steps as $step) {
            $step['run']();
        }
        $this->send($this->claims($course, $student));
        $this->assertSame('course_not_configured:no_criterion', $this->assert_audited(503, 'course_not_configured')->reason);

        // Everything: now it is applied, and the same statement works.
        foreach (result_course::plan((int) $course->id, '80', 'gt', false) as $step) {
            $step['run']();
        }
        $this->assert_reply($this->send($this->claims($course, $student)), 200, 'completed', true);
    }

    /**
     * With completion switched off in the course nothing is applied.
     */
    public function test_completion_switched_off_is_503(): void {
        global $DB;

        $course = $this->make_course();
        $student = $this->make_user($course);
        $DB->set_field('course', 'enablecompletion', 0, ['id' => $course->id]);
        rebuild_course_cache($course->id, true);

        $reply = $this->send($this->claims($course, $student));

        $this->assert_reply($reply, 503, 'course_not_configured', false);
        $this->assertSame('course_not_configured:completion_disabled', $this->assert_audited(503, 'course_not_configured')->reason);
    }

    /**
     * The receiver is off until it is turned on and has an issuer, a tenant and a key.
     */
    public function test_the_receiver_is_off_until_configured(): void {
        $course = $this->make_course();
        $student = $this->make_user($course);
        $body = json_encode(['statement' => $this->sign($this->claims($course, $student))]);

        foreach (['resultsenabled' => 0, 'resultissuer' => '', 'resulttenant' => '', 'resultkeys' => ''] as $name => $value) {
            $old = get_config('format_rumbos', $name);
            set_config($name, $value, 'format_rumbos');
            $reply = result_receiver::handle('POST', $body, $this->now);
            $this->assert_reply($reply, 503, 'not_configured', false);
            $this->assert_audited(503, 'not_configured');
            set_config($name, $old, 'format_rumbos');
        }
        $this->assert_reply(result_receiver::handle('POST', $body, $this->now), 200, 'completed', true);
    }

    /**
     * A key that was rotated in works, and the old one works until it is removed.
     */
    public function test_two_keys_during_a_rotation(): void {
        $course = $this->make_course();
        $student = $this->make_user($course);
        $second = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $secondpublic = openssl_pkey_get_details($second)['key'];
        openssl_pkey_export($second, $secondprivate);
        set_config('resultkeys', self::$publicpem . "\n" . $secondpublic, 'format_rumbos');

        $claims = $this->claims($course, $student, ['percent' => '60.00', 'passed' => false]);
        $withnew = \Firebase\JWT\JWT::encode($claims, $secondprivate, 'RS256', result_config::key_id($secondpublic));
        $this->assert_reply($this->post($withnew), 200, 'recorded', true);
        $again = $this->send($this->claims($course, $student, ['percent' => '61.00', 'passed' => false]));
        $this->assert_reply($again, 200, 'recorded', true);

        set_config('resultkeys', $secondpublic, 'format_rumbos');
        $this->assert_reply($this->send($this->claims($course, $student)), 401, 'unknown_key', false);
    }

    /**
     * The site wide limit answers 429 and does not write the refused request.
     */
    public function test_the_rate_limit(): void {
        global $DB;

        $course = $this->make_course();
        $student = $this->make_user($course);
        for ($i = 0; $i < result_rate_limit::LIMIT; $i++) {
            $this->assertTrue(result_rate_limit::allow($this->now));
        }

        $reply = $this->send($this->claims($course, $student));

        $this->assertSame(429, $reply['status']);
        $this->assertSame((string) result_rate_limit::WINDOW, $reply['headers']['Retry-After']);
        $this->assertSame(0, $DB->count_records('format_rumbos_audit'));
        // The next window starts again.
        $this->assertTrue(result_rate_limit::allow($this->now + result_rate_limit::WINDOW));
    }

    /**
     * Garbage that fails before the signature is checked cannot fill the audit table.
     */
    public function test_unauthenticated_requests_are_capped_in_the_audit(): void {
        global $DB;

        for ($i = 0; $i < result_rate_limit::UNAUTHENTICATED_LIMIT + 15; $i++) {
            $reply = result_receiver::handle('POST', 'garbage', $this->now);
            $this->assertSame(400, $reply['status']);
        }

        $this->assertSame(result_rate_limit::UNAUTHENTICATED_LIMIT, $DB->count_records('format_rumbos_audit'));
    }

    /**
     * Nothing personal beyond the pseudonym and the score is kept in the audit.
     */
    public function test_the_audit_holds_no_personal_data(): void {
        global $DB;

        $course = $this->make_course();
        $student = $this->make_user($course);
        $this->send($this->claims($course, $student));

        $row = $DB->get_record('format_rumbos_audit', ['userid' => $student->id], '*', MUST_EXIST);
        $text = json_encode($row);
        $this->assertStringNotContainsString($student->email, $text);
        $this->assertStringNotContainsString($student->username, $text);
        $this->assertStringNotContainsString($student->firstname, $text);
        $this->assertSame(pseudonym::for_user((int) $student->id), $row->subject);
        $this->assertSame([], array_intersect(['ip', 'ipaddress', 'email', 'name'], array_keys((array) $row)));
    }
}
