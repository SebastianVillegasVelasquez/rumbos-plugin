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

use Firebase\JWT\JWT;
use format_rumbos\event\content_autocompleted;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/../fixtures/result_testcase.php');
require_once($CFG->libdir . '/completionlib.php');

/**
 * Tests for the statements of the Rumbos service: its claims, from the golden vectors to the course.
 *
 * The claims start from the golden vectors. A vector cannot complete a course here (its `sub` is nobody), so the end to
 * end cases take its claims, put in a student and a course of the test, and sign them with an ES256 key of the tests,
 * posting the bare token as the service does.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\local\result_statement::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\local\result_receiver::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\local\result_applier::class)]
final class result_service_test extends \format_rumbos\result_testcase {
    /** @var int The iat of the vectors. */
    private const IAT = 1795000000;

    /** @var string The kid the service uses by default. */
    private const KID = 'passback-1';

    /** @var string The private key that plays the service's. */
    private string $servicekey = '';

    /**
     * Configures the receiver for the service: its issuer, the tenant of the vectors and an EC key.
     */
    protected function setUp(): void {
        parent::setUp();
        [$this->servicekey, $jwk] = $this->ec_key(self::KID);
        set_config('resultkeys', json_encode(['keys' => [$jwk]]), 'format_rumbos');
        set_config('resultissuer', 'rumbos-backend', 'format_rumbos');
        set_config('resulttenant', $this->vector('result')->claims->tenant, 'format_rumbos');
        $this->now = max($this->now, self::IAT + 60);
    }

    /**
     * The claims of a golden vector, for a student and a course of the test, closed just now.
     *
     * @param \stdClass $course
     * @param \stdClass $user
     * @param array $override Claims to change; null removes one.
     * @param string $vector result or attempt.
     * @return array
     */
    private function service_claims(\stdClass $course, \stdClass $user, array $override = [], string $vector = 'result'): array {
        $claims = json_decode($this->vector_file($vector . '.json'), true)['claims'];
        $claims['sub'] = pseudonym::for_user((int) $user->id);
        $claims['course_id'] = (int) $course->id;
        $claims['jti'] = \core\uuid::generate();
        $claims['iat'] = $this->now - 5;
        $claims['finished_at'] = gmdate('Y-m-d\TH:i:s', $this->now - 5) . '.123456+00:00';
        foreach ($override as $name => $value) {
            if ($value === null) {
                unset($claims[$name]);
            } else {
                $claims[$name] = $value;
            }
        }
        return $claims;
    }

    /**
     * Signs claims as the service does.
     *
     * @param array $claims
     * @return string
     */
    private function service_sign(array $claims): string {
        return JWT::encode($claims, $this->servicekey, 'ES256', self::KID);
    }

    /**
     * Posts claims as the service does: the compact token is the whole body.
     *
     * @param array $claims
     * @param int|null $now
     * @return array The reply.
     */
    private function deliver(array $claims, ?int $now = null): array {
        return result_receiver::handle('POST', $this->service_sign($claims), $now ?? $this->now);
    }

    /**
     * A Rumbos course set up for results, with a student.
     *
     * @return array [course, student]
     */
    private function course_and_student(): array {
        $course = $this->make_course();
        return [$course, $this->make_user($course)];
    }

    /**
     * The result vectors, exactly as the service signed them, get past the signature and every check but one: their
     * `sub` is not a user reference a launch can carry. With only that claim changed, the claims map completely.
     *
     * @param string $name
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('result_vector_provider')]
    public function test_the_claims_of_a_golden_vector(string $name): void {
        set_config('resultkeys', $this->vector_file('jwks.json'), 'format_rumbos');
        $vector = $this->vector($name);
        $claims = result_statement::decode($vector->jws, self::IAT + 1);

        try {
            result_statement::verify($vector->jws, self::IAT + 1);
            $this->fail('the sub of the vector is not a pseudonym');
        } catch (result_rejection $rejection) {
            $this->assertSame([400, 'malformed'], [$rejection->status, $rejection->reason]);
        }
        $claims->sub = str_repeat('a', 32);

        $this->assertSame([
            'iss' => 'rumbos-backend',
            'aud' => 'moodle',
            'tenant' => '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b',
            'jti' => '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c',
            'iat' => self::IAT,
            'sub' => str_repeat('a', 32),
            'course_id' => 7,
            'purpose' => 'final',
            'attempt_id' => '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c',
            'percent' => '80.20',
            'passed' => true,
            'completes_course' => true,
            'pass_percent' => '80',
            'pass_operator' => 'gt',
            // The vector's finished_at, 2026-11-18T10:13:20+00:00: not the same moment as its iat.
            'finished_at' => 1794996800,
        ], result_statement::result_claims($claims, 'ES256', self::IAT + 1));
    }

    /**
     * The vectors that are results.
     *
     * @return array
     */
    public static function result_vector_provider(): array {
        return ['schema 1 result' => ['result'], 'schema 2 attempt' => ['attempt']];
    }

    /**
     * A pass of the service completes the course from the bare token, and so does the same pass as an attempt record.
     *
     * @param string $vector
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('result_vector_provider')]
    public function test_a_pass_of_the_service_completes_the_course(string $vector): void {
        global $DB;
        [$course, $student] = $this->course_and_student();
        $claims = $this->service_claims($course, $student, [], $vector);

        $this->assert_reply($this->deliver($claims), 200, 'completed', true);

        $this->assertTrue($this->is_complete($course, $student));
        $this->assertSame(8020, $this->grade_cents($course, $student));
        $row = $DB->get_record('format_rumbos_result', ['userid' => $student->id], '*', MUST_EXIST);
        $this->assertSame($claims['jti'], $row->jti);
        $this->assertSame($claims['jti'], $row->attemptid, 'the attempt id is the jti');
        $this->assertSame((string) ($this->now - 5), (string) $row->timefinished);
        $audit = $this->assert_audited(200, 'completed');
        $this->assertSame(self::KID, $audit->kid);
        $this->assertSame('gt:80', $audit->policy);
    }

    /**
     * A fail of the service records the grade and completes nothing.
     */
    public function test_a_fail_of_the_service_only_records(): void {
        [$course, $student] = $this->course_and_student();
        $claims = $this->service_claims($course, $student, [
            'passed' => false, 'completes_course' => false, 'percent' => '80.00',
        ]);

        $this->assert_reply($this->deliver($claims), 200, 'recorded', true);

        $this->assertSame(8000, $this->grade_cents($course, $student));
        $this->assertFalse($this->is_complete($course, $student));
    }

    /**
     * The JSON form of the body still takes a statement of the service, and the bare form one of the development signer.
     */
    public function test_both_body_forms_work_with_both_keys(): void {
        [$course, $student] = $this->course_and_student();
        $this->assert_reply($this->post($this->service_sign($this->service_claims($course, $student))), 200, 'completed');

        set_config('resultkeys', self::$publicpem, 'format_rumbos');
        set_config('resultissuer', 'rumbos-test', 'format_rumbos');
        set_config('resulttenant', 'tenant-1', 'format_rumbos');
        $other = $this->make_user($course);
        $bare = $this->sign($this->claims($course, $other));
        $this->assert_reply(result_receiver::handle('POST', $bare . "\n", $this->now), 200, 'completed');
    }

    /**
     * A body that is neither a token nor the JSON form is malformed.
     */
    public function test_other_bodies_are_malformed(): void {
        foreach (['', 'a.b', 'a.b.c.d', 'a.b.c d', '"a.b.c"', '{"jws":"a.b.c"}'] as $body) {
            $this->assert_reply(result_receiver::handle('POST', $body, $this->now), 400, 'malformed');
        }
    }

    /**
     * An attempt record is far longer than a result (here with 60 KB of written text) and is read; a body over the
     * limit is not.
     */
    public function test_a_long_attempt_record_is_accepted(): void {
        [$course, $student] = $this->course_and_student();
        $claims = $this->service_claims($course, $student, ['written' => [['text' => str_repeat('ñ', 30000)]]], 'attempt');
        $this->assertGreaterThan(80000, strlen($this->service_sign($claims)));

        $this->assert_reply($this->deliver($claims), 200, 'completed');

        $this->assert_reply(
            result_receiver::handle('POST', str_repeat('a', result_receiver::MAX_BODY + 1), $this->now),
            400,
            'malformed'
        );
    }

    /**
     * The outbox resends the same claims with a new signature, also days later: it applies once.
     */
    public function test_a_resend_is_idempotent_by_jti(): void {
        global $DB;
        [$course, $student] = $this->course_and_student();
        $claims = $this->service_claims($course, $student);
        $first = $this->service_sign($claims);
        $again = $this->service_sign($claims);
        $this->assertNotSame($first, $again, 'ECDSA signatures differ every time');

        $this->assert_reply(result_receiver::handle('POST', $first, $this->now), 200, 'completed');
        $this->assert_reply(result_receiver::handle('POST', $again, $this->now), 200, 'already_applied');
        $this->assert_reply($this->deliver($claims, $this->now + 30 * DAYSECS), 200, 'already_applied');
        // The same attempt, now as the schema 2 record of the same claims.
        $record = $claims + ['schema' => 2, 'kind' => 'attempt', 'status' => 'submitted'];
        $this->assert_reply($this->deliver($record), 200, 'already_applied');

        $this->assertSame(1, $DB->count_records('format_rumbos_result'));
        $this->assertSame(8020, $this->grade_cents($course, $student));
    }

    /**
     * A jti used again for another result is refused and changes nothing.
     */
    public function test_a_jti_with_other_claims_is_a_conflict(): void {
        [$course, $student] = $this->course_and_student();
        $claims = $this->service_claims($course, $student, ['passed' => false, 'completes_course' => false]);
        $this->assert_reply($this->deliver($claims), 200, 'recorded');

        $this->assert_reply($this->deliver(['passed' => true, 'completes_course' => true] + $claims), 409, 'jti_conflict');

        $this->assertFalse($this->is_complete($course, $student));
    }

    /**
     * Each claim the plugin needs: without it the statement is refused and nothing is applied.
     *
     * @param string $claim
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('required_claim_provider')]
    public function test_a_missing_claim_is_refused(string $claim): void {
        global $DB;
        [$course, $student] = $this->course_and_student();

        $this->assert_reply($this->deliver($this->service_claims($course, $student, [$claim => null])), 400, 'malformed');

        $this->assertSame(0, $DB->count_records('format_rumbos_result'));
    }

    /**
     * The claims that are required.
     *
     * @return array
     */
    public static function required_claim_provider(): array {
        $claims = ['iss', 'aud', 'tenant', 'jti', 'sub', 'course_id', 'purpose', 'percent', 'passed', 'completes_course'];
        return array_combine($claims, array_map(fn($claim) => [$claim], $claims));
    }

    /**
     * One of iat and finished_at is enough, not none. Without finished_at the attempt finished at iat.
     */
    public function test_one_time_is_required(): void {
        global $DB;
        [$course, $student] = $this->course_and_student();
        $none = $this->service_claims($course, $student, ['iat' => null, 'finished_at' => null]);
        $this->assert_reply($this->deliver($none), 400, 'malformed');

        $noiat = $this->service_claims($course, $student, ['iat' => null]);
        $this->assert_reply($this->deliver($noiat), 200, 'completed');

        $nofinish = $this->service_claims($course, $student, ['finished_at' => null, 'iat' => $this->now - 90]);
        $this->assert_reply($this->deliver($nofinish), 200, 'recorded');
        $this->assertSame((string) ($this->now - 90), $DB->get_field('format_rumbos_result', 'timefinished', [
            'jti' => $nofinish['jti'],
        ]));
    }

    /**
     * What is refused, with what the sender is told. Nothing is applied.
     *
     * @param array $override
     * @param int $status
     * @param string $reason
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refused_provider')]
    public function test_refused_claims(array $override, int $status, string $reason): void {
        global $DB;
        [$course, $student] = $this->course_and_student();
        foreach (['iat', 'exp'] as $time) {
            if (isset($override[$time])) {
                $override[$time] += $this->now;
            }
        }
        if (($override['finished_at'] ?? null) === 'future') {
            $override['finished_at'] = gmdate('Y-m-d\TH:i:s\Z', $this->now + 3600);
        }

        $this->assert_reply($this->deliver($this->service_claims($course, $student, $override)), $status, $reason);

        $this->assertSame(0, $DB->count_records('format_rumbos_result'));
        $this->assertFalse($this->is_complete($course, $student));
    }

    /**
     * The refusals.
     *
     * @return array
     */
    public static function refused_provider(): array {
        return [
            'another issuer' => [['iss' => 'someone-else'], 401, 'bad_issuer'],
            'another audience' => [['aud' => 'moodle-2'], 401, 'bad_audience'],
            'another tenant' => [['tenant' => '0190a1b2-0000-0000-0000-000000000000'], 401, 'bad_tenant'],
            'iat in the future' => [['iat' => 3600], 401, 'not_yet_valid'],
            'finished in the future' => [['finished_at' => 'future'], 401, 'not_yet_valid'],
            'exp present and expired' => [['exp' => -3600], 401, 'expired'],
            'the sub of the vector' => [['sub' => 's-4f9d2c'], 400, 'malformed'],
            'a sub that is nobody here' => [['sub' => str_repeat('b', 32)], 422, 'unknown_user'],
            'a course that is not there' => [['course_id' => 999999], 422, 'unknown_course'],
            'jti too long' => [['jti' => str_repeat('a', 65)], 400, 'malformed'],
            'passed as a number' => [['passed' => 1], 400, 'malformed'],
            'completes_course as text' => [['completes_course' => 'true'], 400, 'malformed'],
            'percent as a number' => [['percent' => 80.2], 400, 'malformed'],
            'half a pass rule' => [['pass_operator' => null], 400, 'malformed'],
            'an unknown operator' => [['pass_operator' => 'lt'], 400, 'malformed'],
            'a pass percent over 100' => [['pass_percent' => 101], 400, 'malformed'],
            'an unknown kind' => [['kind' => 'certificate'], 422, 'unknown_kind'],
            'an empty kind' => [['kind' => ''], 422, 'unknown_kind'],
            'a newer result schema' => [['schema' => 3], 422, 'unknown_kind'],
            'a newer attempt schema' => [['kind' => 'attempt', 'schema' => 3], 422, 'unknown_kind'],
        ];
    }

    /**
     * Claims the plugin does not need may be there or not: an exp still to come, and no pass rule at all.
     */
    public function test_optional_claims(): void {
        [$course, $student] = $this->course_and_student();
        $claims = $this->service_claims($course, $student, [
            'exp' => $this->now + DAYSECS, 'pass_percent' => null, 'pass_operator' => null, 'ordinal' => null,
        ]);

        $this->assert_reply($this->deliver($claims), 200, 'completed');

        $this->assertNull($this->assert_audited(200, 'completed')->policy);
    }

    /**
     * Neither a teacher nor a student who is no longer enrolled is completed by the service.
     */
    public function test_the_role_check_stands(): void {
        $course = $this->make_course();
        $teacher = $this->make_user($course, 'editingteacher');

        $this->assert_reply($this->deliver($this->service_claims($course, $teacher)), 422, 'role_not_allowed');

        $this->assertFalse($this->is_complete($course, $teacher));
    }

    /**
     * With RS256 the audience stays the site's own: "moodle" is the service's, and only with its algorithm. The service's
     * audience is likewise the only one for ES256.
     */
    public function test_each_algorithm_has_its_audience(): void {
        [$course, $student] = $this->course_and_student();
        $this->assert_reply(
            $this->deliver($this->service_claims($course, $student, ['aud' => launch_assertion::issuer()])),
            401,
            'bad_audience'
        );

        set_config('resultkeys', self::$publicpem, 'format_rumbos');
        $rsa = $this->claims($course, $student, ['iss' => 'rumbos-backend', 'tenant' => result_config::tenant()]);
        $this->assert_reply($this->send(['aud' => 'moodle'] + $rsa), 401, 'bad_audience');
        // Nor do the service's claims pass under an RSA key: that form has its own required claims.
        $this->assert_reply($this->send($this->service_claims($course, $student)), 400, 'malformed');
    }

    /**
     * A course with a tracked SCORM activity and a file, and auto-complete on.
     *
     * @return array [course, student, cmids]
     */
    private function content(): array {
        $this->setAdminUser();
        [$course, $student] = $this->course_and_student();
        $cmids = [];
        foreach (['scorm', 'resource'] as $module) {
            $cmids[] = (int) $this->getDataGenerator()->create_module($module, [
                'course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL,
            ])->cmid;
        }
        $this->setUser(null);
        set_config('autocompletecontent', 1, 'format_rumbos');
        return [$course, $student, $cmids];
    }

    /**
     * How many of the activities are complete for the student.
     *
     * @param \stdClass $student
     * @param array $cmids
     * @return int
     */
    private function completed(\stdClass $student, array $cmids): int {
        global $DB;
        [$in, $params] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED);
        return $DB->count_records_select(
            'course_modules_completion',
            "userid = :userid AND completionstate = :state AND coursemoduleid $in",
            ['userid' => $student->id, 'state' => COMPLETION_COMPLETE] + $params
        );
    }

    /**
     * A fail of the service completes no content; the pass that follows completes it, and a resend changes nothing.
     */
    public function test_auto_complete_follows_a_fail_and_a_pass(): void {
        [$course, $student, $cmids] = $this->content();
        $sink = $this->redirectEvents();
        $fail = $this->service_claims($course, $student, [
            'passed' => false, 'completes_course' => false, 'percent' => '40.00', 'purpose' => 'check_in',
        ]);

        $this->assert_reply($this->deliver($fail), 200, 'recorded');
        $this->assertSame(0, $this->completed($student, $cmids));

        $pass = $this->service_claims($course, $student);
        $this->assert_reply($this->deliver($pass), 200, 'completed');
        $this->assertSame(2, $this->completed($student, $cmids));
        $this->assert_reply($this->deliver($pass), 200, 'already_applied');

        $events = array_filter($sink->get_events(), fn($e) => $e instanceof content_autocompleted);
        $this->assertCount(2, $events);
    }

    /**
     * A pass that does not exempt (completes_course false) records the grade and completes neither the course nor the
     * content, also when it arrives again. One that says it completes but did not pass completes nothing either.
     */
    public function test_a_pass_without_the_exemption_completes_nothing(): void {
        [$course, $student, $cmids] = $this->content();
        $claims = $this->service_claims($course, $student, ['completes_course' => false]);

        $this->assert_reply($this->deliver($claims), 200, 'recorded', true);
        $this->assert_reply($this->deliver($claims), 200, 'already_applied');
        $odd = $this->service_claims($course, $student, ['passed' => false, 'completes_course' => true]);
        $this->assert_reply($this->deliver($odd), 200, 'recorded', true);

        $this->assertSame(8020, $this->grade_cents($course, $student));
        $this->assertFalse($this->is_complete($course, $student));
        $this->assertSame(0, $this->completed($student, $cmids));
    }

    /**
     * An exam definition is answered with a retry while the plugin cannot store it: nothing is lost on either side.
     */
    public function test_a_definition_is_asked_for_again_by_default(): void {
        set_config('resultkeys', $this->vector_file('jwks.json'), 'format_rumbos');
        $vector = $this->vector('definition');

        $reply = result_receiver::handle('POST', $vector->jws, self::IAT + 60);

        $this->assert_reply($reply, 503, 'definition_not_supported');
        $this->assertSame($vector->claims->jti, $this->assert_audited(503, 'definition_not_supported')->jti);
    }

    /**
     * With the setting on, the golden definition is acknowledged and nothing of it is stored; one for another tenant, or
     * tampered with, is still refused.
     */
    public function test_a_definition_can_be_acknowledged(): void {
        global $DB;
        set_config('resultkeys', $this->vector_file('jwks.json'), 'format_rumbos');
        set_config(result_receiver::ACK_DEFINITIONS, 1, 'format_rumbos');
        $vector = $this->vector('definition');

        $reply = result_receiver::handle('POST', $vector->jws, self::IAT + 60);

        $this->assert_reply($reply, 200, 'definition_not_stored', false);
        $this->assertSame(0, $DB->count_records('format_rumbos_result'));

        $tampered = result_receiver::handle('POST', $this->tamper($vector->jws), self::IAT + 60);
        $this->assert_reply($tampered, 401, 'bad_signature');
        set_config('resulttenant', 'another-tenant', 'format_rumbos');
        $this->assert_reply(result_receiver::handle('POST', $vector->jws, self::IAT + 60), 401, 'bad_tenant');
    }

    /**
     * The checks a receiver of other messages shares: signature, parties and the claims every message carries.
     */
    public function test_the_shared_envelope_check(): void {
        set_config('resultkeys', $this->vector_file('jwks.json'), 'format_rumbos');
        foreach (['result', 'attempt', 'definition'] as $name) {
            $vector = $this->vector($name);
            $this->assertEquals($vector->claims, result_statement::verify_envelope($vector->jws, self::IAT + 1));
        }

        set_config('resultissuer', 'someone-else', 'format_rumbos');
        $this->expectException(result_rejection::class);
        $this->expectExceptionMessage('bad_issuer');
        result_statement::verify_envelope($this->vector('definition')->jws, self::IAT + 1);
    }
}
