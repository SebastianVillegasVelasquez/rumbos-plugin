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

use Firebase\JWT\JWT;
use format_rumbos\local\pseudonym;
use format_rumbos\local\result_receiver;
use format_rumbos\mirror_fixture;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../fixtures/result_testcase.php');
require_once(__DIR__ . '/../../fixtures/mirror_fixture.php');

/**
 * Tests for the one address the service posts to: results, exam definitions and attempt records, signed ES256, the
 * bare token as the body.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(result_receiver::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(mirror_intake::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(mirror_config::class)]
final class mirror_routing_test extends \format_rumbos\result_testcase {
    /** @var string The kid the service uses by default. */
    private const KID = 'passback-1';

    /** @var string The tenant. */
    private const TENANT = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

    /** @var string The private key that plays the service's. */
    private string $servicekey = '';

    /** @var \stdClass The course. */
    private \stdClass $course;

    #[\Override]
    public static function setUpBeforeClass(): void {
        global $CFG;
        parent::setUpBeforeClass();
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->libdir . '/completionlib.php');
    }

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        [$this->servicekey, $jwk] = $this->ec_key(self::KID);
        set_config('resultkeys', json_encode(['keys' => [$jwk]]), 'format_rumbos');
        set_config('resultissuer', 'rumbos-backend', 'format_rumbos');
        set_config('resulttenant', self::TENANT, 'format_rumbos');
        set_config('mirrorenabled', 1, 'format_rumbos');
        $this->course = $this->make_course();
    }

    /**
     * Posts claims as the service does.
     *
     * @param array $claims
     * @return array The reply.
     */
    private function deliver(array $claims): array {
        return result_receiver::handle('POST', JWT::encode($claims, $this->servicekey, 'ES256', self::KID), $this->now);
    }

    /**
     * The claims of a definition statement. It names no course.
     *
     * @param array|null $definition
     * @return array
     */
    private function definition(?array $definition = null): array {
        $definition ??= mirror_fixture::definition();
        return [
            'iss' => 'rumbos-backend', 'aud' => 'moodle', 'iat' => $this->now - 30,
            'jti' => \core\uuid::generate(), 'kind' => 'definition', 'schema' => 1, 'tenant' => self::TENANT,
            'form_id' => $definition['form_id'], 'content_version' => '0123456789abcdef',
            'definition_hash' => mirror_fixture::hash($definition), 'definition' => $definition,
        ];
    }

    /**
     * The claims of an attempt statement of a student.
     *
     * @param \stdClass $user
     * @param int[] $section Points in each section of the fixture.
     * @param bool|null $passed What the service decides; strictly above 80 by default.
     * @param array $override
     * @return array
     */
    private function attempt(\stdClass $user, array $section, ?bool $passed = null, array $override = []): array {
        $total = mirror_fixture::total105($section);
        $passed ??= $total > 8400;
        return $override + [
            'iss' => 'rumbos-backend', 'aud' => 'moodle', 'iat' => $this->now - 5, 'jti' => \core\uuid::generate(),
            'tenant' => self::TENANT, 'sub' => pseudonym::for_user((int) $user->id),
            'course_id' => (int) $this->course->id, 'purpose' => 'check_in', 'ordinal' => 1,
            'percent' => mirror_fixture::display($total), 'passed' => $passed, 'completes_course' => $passed,
            'pass_percent' => 80, 'pass_operator' => 'gt', 'finished_at' => gmdate('Y-m-d\TH:i:s\Z', $this->now - 5),
        ] + mirror_fixture::record(mirror_fixture::points($section));
    }

    /**
     * The number of attempts of the mirror and of Moodle's quizzes.
     *
     * @return int[]
     */
    private function mirrored(): array {
        global $DB;
        return [$DB->count_records(mirror_store::ATTEMPTS), $DB->count_records('quiz_attempts')];
    }

    /**
     * The mirror is off until an administrator turns it on. A definition is then retried, and nothing is kept.
     */
    public function test_the_mirror_is_off_by_default(): void {
        global $DB;
        unset_config('mirrorenabled', 'format_rumbos');

        $this->assert_reply($this->deliver($this->definition()), 503, 'mirror_not_enabled', false);

        $this->assertSame(0, $DB->count_records(mirror_store::DEFINITIONS));
        $this->assert_audited(503, 'mirror_not_enabled');
    }

    /**
     * While the mirror is off, an attempt record still counts as a result, and is retried so that its answers are not
     * lost. Once the mirror is on and has the definition, the retry writes them and applies nothing twice.
     */
    public function test_an_attempt_counts_and_waits_while_the_mirror_is_off(): void {
        $user = $this->make_user($this->course);
        unset_config('mirrorenabled', 'format_rumbos');
        $claims = $this->attempt($user, [7, 3, 3, 5, 5]);

        $this->assert_reply($this->deliver($claims), 503, 'mirror_not_enabled', true);

        $this->assertTrue($this->is_complete($this->course, $user));
        $this->assertSame(10000, $this->grade_cents($this->course, $user));
        $this->assertSame([0, 0], $this->mirrored());

        set_config('mirrorenabled', 1, 'format_rumbos');
        $this->deliver($this->definition());
        $reply = $this->deliver($claims);

        $this->assert_reply($reply, 200, 'already_applied', false);
        $this->assertSame('mirrored', $reply['body']['mirror']);
        $this->assertSame([1, 1], $this->mirrored());
    }

    /**
     * A definition is kept once and makes no quiz by itself: it names no course. The same claims again change nothing,
     * and another exam under the same hash is refused for a person to look at.
     */
    public function test_a_definition_is_kept_once(): void {
        global $DB;
        $claims = $this->definition();

        $this->assert_reply($this->deliver($claims), 200, 'definition_stored', true);
        $this->assert_reply($this->deliver($claims), 200, 'definition_already_stored', false);

        $this->assertSame(1, $DB->count_records(mirror_store::DEFINITIONS));
        $this->assertSame(0, $DB->count_records(mirror_store::QUIZZES));
        $this->assert_audited(200, 'definition_already_stored');

        $other = $claims;
        $other['definition']['title'] = 'Another title';
        $this->assert_reply($this->deliver($other), 409, 'definition_conflict', false);
        $this->assertStringContainsString(
            mirror_fixture::TITLE,
            $DB->get_field(mirror_store::DEFINITIONS, 'definition', [])
        );
    }

    /**
     * The golden definition vector, exactly as the service signed it, is accepted with the vectors' key.
     */
    public function test_the_definition_vector_as_signed(): void {
        set_config('resultkeys', $this->vector_file('jwks.json'), 'format_rumbos');
        $vector = $this->vector('definition');

        $reply = result_receiver::handle('POST', $vector->jws, $vector->claims->iat + 60);

        $this->assert_reply($reply, 200, 'definition_stored', true);
        $this->assertNotNull(mirror_store::find_definition('synthetic', $vector->claims->definition_hash));
    }

    /**
     * An attempt at a known exam is applied and written into the course's hidden quiz, which that first attempt makes.
     */
    public function test_an_attempt_of_a_known_definition(): void {
        global $DB;
        $user = $this->make_user($this->course);
        $this->deliver($this->definition());
        $claims = $this->attempt($user, [7, 3, 3, 5, 4]);

        $reply = $this->deliver($claims);

        $this->assert_reply($reply, 200, 'completed', true);
        $this->assertSame('mirrored', $reply['body']['mirror']);
        $this->assertArrayNotHasKey('error', $reply['body']);
        $this->assertTrue($this->is_complete($this->course, $user));
        $this->assertSame(9700, $this->grade_cents($this->course, $user));
        $row = mirror_store::find_attempt($claims['jti']);
        $this->assertEquals($user->id, $row->userid);
        $quiz = mirror_store::get_quiz((int) $row->mirrorid);
        $this->assertSame('0', $DB->get_field('course_modules', 'visible', ['id' => $quiz->cmid]));
        $this->assertEquals(97, $DB->get_field('quiz_attempts', 'sumgrades', ['id' => $row->quizattemptid]));
    }

    /**
     * An attempt that arrives before its definition is applied, and answered `unknown_definition` the way the service
     * reads it, so that it sends the definition again and then the attempt. The attempt is not lost.
     */
    public function test_an_attempt_before_its_definition(): void {
        $user = $this->make_user($this->course);
        $claims = $this->attempt($user, [7, 3, 3, 5, 5]);

        $reply = $this->deliver($claims);

        $this->assertSame(409, $reply['status']);
        $this->assertSame(
            ['applied' => true, 'reason' => 'unknown_definition', 'error' => 'unknown_definition'],
            $reply['body']
        );
        $this->assertTrue($this->is_complete($this->course, $user));
        $this->assertSame([0, 0], $this->mirrored());
        $this->assert_audited(409, 'unknown_definition');

        $this->assert_reply($this->deliver($this->definition()), 200, 'definition_stored', true);
        $retry = $this->deliver($claims);

        $this->assert_reply($retry, 200, 'already_applied', false);
        $this->assertSame('mirrored', $retry['body']['mirror']);
        $this->assertSame([1, 1], $this->mirrored());
    }

    /**
     * A resend, with the same claims and a new signature, changes nothing: ECDSA signatures differ each time.
     */
    public function test_a_resend_changes_nothing(): void {
        global $DB;
        $user = $this->make_user($this->course);
        $this->deliver($this->definition());
        $claims = $this->attempt($user, [5, 2, 2, 3, 3]);
        $this->deliver($claims);
        $counts = fn(): array => array_map(
            fn($table) => $DB->count_records($table),
            ['format_rumbos_result', mirror_store::QUIZZES, mirror_store::ATTEMPTS, 'quiz_attempts', 'question_attempt_steps']
        );
        $before = $counts();

        $reply = $this->deliver($claims);

        $this->assert_reply($reply, 200, 'already_applied', false);
        $this->assertSame('already_mirrored', $reply['body']['mirror']);
        $this->assertSame($before, $counts());
    }

    /**
     * The service never sends a preview. A statement that says it is one grants and mirrors nothing, and neither does
     * a teacher's.
     */
    public function test_a_preview_grants_and_mirrors_nothing(): void {
        global $DB;
        $user = $this->make_user($this->course);
        $teacher = $this->make_user($this->course, 'editingteacher');
        $this->deliver($this->definition());

        $reply = $this->deliver($this->attempt($user, [7, 3, 3, 5, 5], true, ['preview' => true]));
        $this->assert_reply($reply, 422, 'preview_not_accepted', false);
        $reply = $this->deliver($this->attempt($teacher, [7, 3, 3, 5, 5]));
        $this->assert_reply($reply, 422, 'role_not_allowed', false);

        $this->assertSame(0, $DB->count_records('format_rumbos_result'));
        $this->assertSame([0, 0], $this->mirrored());
        $this->assertFalse($this->is_complete($this->course, $user));
        $this->assertNull($this->grade_cents($this->course, $user));
    }

    /**
     * A failing attempt records its grade and its answers and completes nothing.
     */
    public function test_a_failing_attempt(): void {
        $user = $this->make_user($this->course);
        $this->deliver($this->definition());

        $reply = $this->deliver($this->attempt($user, [3, 1, 1, 2, 2]));

        $this->assert_reply($reply, 200, 'recorded', true);
        $this->assertSame('mirrored', $reply['body']['mirror']);
        $this->assertFalse($this->is_complete($this->course, $user));
        $this->assertSame([1, 1], $this->mirrored());
    }

    /**
     * Exactly 80 fails. Moodle's own grade to pass is inclusive and would call it a pass, so the mirror quiz has none,
     * nothing is completed, and the gradebook has the one grade.
     */
    public function test_exactly_at_the_threshold_completes_nothing(): void {
        global $DB;
        $user = $this->make_user($this->course);
        $this->deliver($this->definition());
        $claims = $this->attempt($user, mirror_fixture::threshold_cases()['at']);
        $this->assertSame(['80.00', false], [$claims['percent'], $claims['passed']]);

        $this->assert_reply($this->deliver($claims), 200, 'recorded', true);

        $this->assertFalse($this->is_complete($this->course, $user));
        $this->assertSame(8000, $this->grade_cents($this->course, $user));
        $row = mirror_store::find_attempt($claims['jti']);
        $this->assertEquals(80, $DB->get_field('quiz_attempts', 'sumgrades', ['id' => $row->quizattemptid]));
        $graded = $DB->get_fieldset_sql(
            'SELECT i.itemtype FROM {grade_grades} g JOIN {grade_items} i ON i.id = g.itemid
              WHERE i.courseid = ? AND g.userid = ? AND g.finalgrade IS NOT NULL ORDER BY i.itemtype',
            [$this->course->id, $user->id]
        );
        $this->assertSame(['course', 'manual'], $graded);
        $this->assertFalse(\grade_item::fetch(['courseid' => $this->course->id, 'itemmodule' => 'quiz']));
        $this->assertSame(0, $DB->count_records_select(
            'course_modules_completion',
            'userid = ? AND completionstate > 0',
            [$user->id]
        ));
    }

    /**
     * An exam this version cannot read is retried, not parked, so that a fix on this side lets it arrive; it is logged.
     */
    public function test_a_definition_that_cannot_be_read_is_retried(): void {
        global $DB;
        $definition = mirror_fixture::definition();
        $definition['sections'][0]['parts'][0]['questions'][0]['type'] = 'drawing';

        $this->assert_reply($this->deliver($this->definition($definition)), 503, 'invalid_definition', false);

        $this->assertSame(0, $DB->count_records(mirror_store::DEFINITIONS));
        $line = array_values(mirror_store::recent_log())[0];
        $this->assertSame(['rejected', '503', 'invalid_definition'], [$line->outcome, $line->status, $line->reason]);
        $this->assertSame('invalid_definition:l-01.type', $line->detail);
    }

    /**
     * An attempt record the mirror cannot read still counts as a result, and is retried.
     */
    public function test_a_record_that_cannot_be_read_is_retried(): void {
        $user = $this->make_user($this->course);
        $this->deliver($this->definition());

        $reply = $this->deliver($this->attempt($user, [7, 3, 3, 5, 5], true, ['status' => 'voided']));

        $this->assert_reply($reply, 503, 'invalid_attempt', true);
        $this->assertTrue($this->is_complete($this->course, $user));
        $this->assertSame([0, 0], $this->mirrored());
    }

    /**
     * A failure while writing leaves no half attempt, is retried, and does not undo the result.
     */
    public function test_a_failed_write_is_retried(): void {
        global $DB;
        $user = $this->make_user($this->course);
        $this->deliver($this->definition());
        $this->deliver($this->attempt($this->make_user($this->course), [1, 1, 1, 1, 1]));
        $item = $DB->get_record(mirror_store::ITEMS, ['itemid' => 'u-03'], '*', MUST_EXIST);
        $DB->delete_records(mirror_store::ITEMS, ['id' => $item->id]);
        $claims = $this->attempt($user, [7, 3, 3, 5, 5]);

        $this->assert_reply($this->deliver($claims), 503, 'exam_not_mirrored', true);

        $this->assertTrue($this->is_complete($this->course, $user));
        $this->assertSame([1, 1], $this->mirrored());
        unset($item->id);
        $DB->insert_record(mirror_store::ITEMS, $item);
        $retry = $this->deliver($claims);
        $this->assertSame(['mirrored', 200], [$retry['body']['mirror'], $retry['status']]);
    }

    /**
     * A plain result (schema 1) is not the mirror's business.
     */
    public function test_a_plain_result_is_not_mirrored(): void {
        $user = $this->make_user($this->course);
        $claims = array_diff_key($this->attempt($user, [7, 3, 3, 5, 5]), array_flip([
            'kind', 'schema', 'form_id', 'content_version', 'status', 'definition_hash', 'percent_exact', 'sections',
            'answers', 'written', 'written_text_included', 'truncated',
        ]));

        $reply = $this->deliver($claims);

        $this->assert_reply($reply, 200, 'completed', true);
        $this->assertArrayNotHasKey('mirror', $reply['body']);
        $this->assertSame([0, 0], $this->mirrored());
    }

    /**
     * A definition is verified like everything else: a wrong key, audience or tenant keeps nothing.
     */
    public function test_a_definition_is_verified_like_a_result(): void {
        global $DB;
        [$otherkey] = $this->ec_key(self::KID);

        $reply = result_receiver::handle('POST', JWT::encode($this->definition(), $otherkey, 'ES256', self::KID), $this->now);
        $this->assert_reply($reply, 401, 'bad_signature', false);
        $this->assert_reply($this->deliver(['aud' => 'elsewhere'] + $this->definition()), 401, 'bad_audience', false);
        $this->assert_reply($this->deliver(['tenant' => 'other'] + $this->definition()), 401, 'bad_tenant', false);

        $this->assertSame(0, $DB->count_records(mirror_store::DEFINITIONS));
    }

    /**
     * An attempt as large as the service's 64 KB cap allows, in the worst encoding, fits in the receiver and the mirror.
     */
    public function test_an_attempt_at_the_size_cap_is_mirrored(): void {
        $user = $this->make_user($this->course);
        $this->deliver($this->definition());
        $claims = $this->attempt($user, [7, 3, 3, 5, 5]);
        // Four bytes of UTF-8 each, twelve once the signing library has escaped them.
        $claims['written'][0]['text'] = ['q1' => str_repeat("\u{1F600}", 15000)];
        $canonical = strlen(json_encode($claims, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->assertGreaterThan(60000, $canonical);
        $this->assertLessThanOrEqual(65536, $canonical);

        $reply = $this->deliver($claims);

        $this->assertSame([200, 'mirrored'], [$reply['status'], $reply['body']['mirror']]);
    }

    /**
     * The development signer's RS256 form has no definitions or attempt records: such a statement is not routed.
     */
    public function test_an_rs256_statement_is_never_mirrored(): void {
        global $DB;
        set_config('resultkeys', self::$publicpem, 'format_rumbos');
        set_config('resultissuer', 'rumbos-test', 'format_rumbos');
        set_config('resulttenant', 'tenant-1', 'format_rumbos');
        $user = $this->make_user($this->course);
        $claims = $this->claims($this->course, $user, ['kind' => 'definition']);

        $reply = $this->post($this->sign($claims));

        $this->assertSame(200, $reply['status']);
        $this->assertSame(0, $DB->count_records(mirror_store::DEFINITIONS));
    }
}
