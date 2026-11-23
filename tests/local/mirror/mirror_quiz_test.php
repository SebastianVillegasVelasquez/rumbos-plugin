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
use mod_quiz\quiz_settings;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../fixtures/mirror_fixture.php');

/**
 * Tests for the native quiz that mirrors an exam version.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(mirror_quiz::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(mirror_questions::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(service_identity::class)]
final class mirror_quiz_test extends \advanced_testcase {
    /**
     * Loads what the tests need from Moodle's quiz code.
     */
    public static function setUpBeforeClass(): void {
        global $CFG;
        parent::setUpBeforeClass();
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->libdir . '/gradelib.php');
    }

    /**
     * A course in the Rumbos format.
     *
     * @return \stdClass
     */
    private function course(): \stdClass {
        $this->resetAfterTest();
        return $this->getDataGenerator()->create_course(['format' => 'rumbos']);
    }

    /**
     * Makes the mirror of the fixture exam.
     *
     * @param \stdClass $course
     * @param string $version
     * @return array{0: \stdClass, 1: bool}
     */
    private function mirror(\stdClass $course, string $version = 'v1hash'): array {
        return mirror_quiz::ensure($course, exam::from_array(mirror_fixture::form($version)), 1000);
    }

    /**
     * The ids of the questions a quiz uses now, by slot.
     *
     * @param int $quizid
     * @return int[]
     */
    private function questions_in(int $quizid): array {
        $quizobj = quiz_settings::create($quizid);
        $quizobj->preload_questions();
        $quizobj->load_questions();
        return array_map(fn($question) => (int) $question->id, $quizobj->get_questions());
    }

    /**
     * The quiz is made hidden, with one question per item, no grade, no pass mark and no completion.
     */
    public function test_the_quiz_is_made_hidden_with_the_questions(): void {
        global $DB;
        $course = $this->course();

        [$row, $created] = $this->mirror($course);

        $this->assertTrue($created);
        $quiz = $DB->get_record('quiz', ['id' => $row->quizid], '*', MUST_EXIST);
        $cm = $DB->get_record('course_modules', ['id' => $row->cmid], '*', MUST_EXIST);
        $this->assertSame('b2-form-a', $row->formid);
        $this->assertSame('v1hash', $row->version);
        $this->assertStringContainsString('B2 check-in (fixture)', $quiz->name);
        $this->assertStringContainsString('check-in', $quiz->name);
        $this->assertSame('0', $cm->visible);
        $this->assertSame('0', $cm->visibleoncoursepage);
        $this->assertSame('0', $cm->completion);
        $this->assertEquals(0, $quiz->grade);
        $this->assertEquals(0, $quiz->gradepass ?? 0);
        $this->assertEqualsWithDelta(100.0, $quiz->sumgrades, 0.00001);
        $this->assertSame('deferredfeedback', $quiz->preferredbehaviour);
        $this->assertSame(11, $DB->count_records('quiz_slots', ['quizid' => $quiz->id]));
    }

    /**
     * Each item becomes the question of its type, worth what the exam says, in the quiz's own question bank.
     */
    public function test_each_item_is_the_question_of_its_type(): void {
        global $DB;
        $course = $this->course();
        [$row] = $this->mirror($course);

        $items = mirror_store::items((int) $row->id);
        $this->assertSame(
            ['l-01', 'l-02', 'l-11', 'r-01', 'r-02', 'r-11', 'u-01', 'u-02', 'u-03', 'w-01', 's-02'],
            array_keys($items)
        );
        $types = [];
        foreach ($items as $itemid => $item) {
            $types[$itemid] = $DB->get_field('question', 'qtype', ['id' => $item->questionid]);
        }
        $this->assertSame('multichoice', $types['l-01']);
        $this->assertSame('multichoice', $types['r-11']);
        $this->assertSame('match', $types['l-11']);
        $this->assertSame('essay', $types['w-01']);
        $this->assertSame('essay', $types['s-02']);

        $context = \context_module::instance($row->cmid);
        $total = 0.0;
        foreach ($items as $item) {
            $question = $DB->get_record('question', ['id' => $item->questionid], '*', MUST_EXIST);
            $category = $DB->get_field('question_bank_entries', 'questioncategoryid', [
                'id' => $DB->get_field('question_versions', 'questionbankentryid', ['questionid' => $question->id]),
            ]);
            $this->assertEquals($context->id, $DB->get_field('question_categories', 'contextid', ['id' => $category]));
            $total += (float) $DB->get_field('quiz_slots', 'maxmark', ['quizid' => $row->quizid, 'slot' => $item->slot]);
        }
        $this->assertEqualsWithDelta(100.0, $total, 0.00001);
        $matching = $DB->get_field('quiz_slots', 'maxmark', ['quizid' => $row->quizid, 'slot' => $items['l-11']->slot]);
        $this->assertEquals(17.8571429, $matching);
        $this->assertEquals(3, $items['l-11']->slot);
        $this->assertEquals(5, $items['l-11']->units);
    }

    /**
     * The shared passage, the audio's transcript and the prompt are in the question text, escaped.
     */
    public function test_the_question_text_carries_what_a_student_read(): void {
        global $DB;
        $course = $this->course();
        $data = mirror_fixture::form();
        $data['sections'][0]['items'][0]['prompt'] = 'Is 1 < 2 & is it <b>bold</b>?';
        [$row] = mirror_quiz::ensure($course, exam::from_array($data), 1000);
        $items = mirror_store::items((int) $row->id);

        $listening = $DB->get_field('question', 'questiontext', ['id' => $items['l-01']->questionid]);
        $this->assertStringContainsString('Around nine forty.', $listening);
        $this->assertStringContainsString('Audio transcript', $listening);
        $this->assertStringContainsString('Is 1 &lt; 2 &amp; is it &lt;b&gt;bold&lt;/b&gt;?', $listening);
        $this->assertStringNotContainsString('<b>bold</b>', $listening);

        $gap = $DB->get_field('question', 'questiontext', ['id' => $items['r-11']->questionid]);
        $this->assertStringContainsString('Chair: I am pleased to (a)___ this meeting.', $gap);
        $this->assertSame('r-11', $DB->get_field('question_bank_entries', 'idnumber', [
            'id' => $DB->get_field('question_versions', 'questionbankentryid', ['questionid' => $items['r-11']->questionid]),
        ]));
    }

    /**
     * A place that no clip belongs to is kept as a choice with no stem.
     */
    public function test_a_matching_item_may_have_more_places_than_clips(): void {
        global $DB;
        $course = $this->course();
        $data = mirror_fixture::form();
        $item = &$data['sections'][0]['items'][2];
        $item['clips'] = array_slice($item['clips'], 0, 3);
        $item['key'] = array_slice($item['key'], 0, 3);
        unset($item);

        [$row] = mirror_quiz::ensure($course, exam::from_array($data), 1000);

        $questionid = mirror_store::items((int) $row->id)['l-11']->questionid;
        $stems = $DB->get_records('qtype_match_subquestions', ['questionid' => $questionid], 'id');
        $this->assertCount(5, $stems);
        $this->assertSame(['', ''], array_values(array_map(fn($s) => trim($s->questiontext), array_slice($stems, 3))));
        $this->assertEquals(3, mirror_store::items((int) $row->id)['l-11']->units);
    }

    /**
     * A student cannot see the quiz, take it, or review attempts at it, whatever role carries the student archetype.
     */
    public function test_students_cannot_see_or_take_the_quiz(): void {
        $course = $this->course();
        [$row] = $this->mirror($course);
        $context = \context_module::instance($row->cmid);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $roleid = $this->getDataGenerator()->create_role(['shortname' => 'tutorstudent', 'archetype' => 'student']);
        $custom = $this->getDataGenerator()->create_and_enrol($course, 'tutorstudent');
        // A role made after the quiz is locked with the next delivery, and the quiz is hidden from it meanwhile.
        $this->assertFalse((bool) get_fast_modinfo($course->id, $custom->id)->get_cm($row->cmid)->uservisible);
        $this->mirror($course);
        accesslib_clear_all_caches_for_unit_testing();

        foreach ([$student, $custom] as $user) {
            $cm = get_fast_modinfo($course->id, $user->id)->get_cm($row->cmid);
            $this->assertFalse((bool) $cm->uservisible);
            foreach (mirror_quiz::LOCKED_CAPABILITIES as $capability) {
                $this->assertFalse(has_capability($capability, $context, $user), $capability);
            }
        }
        // A teacher still sees it and can read its reports.
        $this->assertTrue((bool) get_fast_modinfo($course->id, $teacher->id)->get_cm($row->cmid)->uservisible);
        $this->assertTrue(has_capability('mod/quiz:view', $context, $teacher));
        $this->assertTrue(has_capability('mod/quiz:viewreports', $context, $teacher));
        $this->assertNotEmpty($roleid);
    }

    /**
     * The quiz adds no grade item: the course keeps its one grade.
     */
    public function test_the_quiz_adds_nothing_to_the_gradebook(): void {
        $course = $this->course();
        [$row] = $this->mirror($course);

        $this->assertFalse(\grade_item::fetch(['courseid' => $course->id, 'itemtype' => 'mod', 'itemmodule' => 'quiz',
            'iteminstance' => $row->quizid]));
        // Only the course total, which every course has, is in the gradebook.
        $items = \grade_item::fetch_all(['courseid' => $course->id]) ?: [];
        $this->assertSame(['course'], array_values(array_unique(array_map(fn($item) => $item->itemtype, $items))));
    }

    /**
     * The same version is made once, however often it is sent.
     */
    public function test_the_same_version_is_made_once(): void {
        global $DB;
        $course = $this->course();
        [$first, $created] = $this->mirror($course);
        $quizzes = $DB->count_records('quiz', ['course' => $course->id]);
        $questions = $DB->count_records('question');

        [$again, $createdagain] = $this->mirror($course);

        $this->assertTrue($created);
        $this->assertFalse($createdagain);
        $this->assertSame($first->id, $again->id);
        $this->assertSame($quizzes, $DB->count_records('quiz', ['course' => $course->id]));
        $this->assertSame($questions, $DB->count_records('question'));
        $this->assertCount(1, mirror_store::recent_log(false));
    }

    /**
     * Another content version makes another quiz with its own questions, and leaves the first one alone.
     */
    public function test_a_new_version_makes_a_new_quiz(): void {
        $course = $this->course();
        [$one] = $this->mirror($course, 'v1hash');
        $before = $this->questions_in((int) $one->quizid);

        [$two, $created] = $this->mirror($course, 'v2hash');

        $this->assertTrue($created);
        $this->assertNotSame($one->quizid, $two->quizid);
        $this->assertNotSame($one->cmid, $two->cmid);
        $this->assertSame($before, $this->questions_in((int) $one->quizid));
        $this->assertEmpty(array_intersect($before, $this->questions_in((int) $two->quizid)));
        $this->assertSame('v2hash', $two->version);
        $this->assertEquals(0, get_fast_modinfo($course->id)->get_cm($two->cmid)->visible);
    }

    /**
     * Another course gets its own quiz for the same version.
     */
    public function test_each_course_has_its_own_quiz(): void {
        $one = $this->course();
        $other = $this->getDataGenerator()->create_course(['format' => 'rumbos']);

        [$first] = $this->mirror($one);
        [$second, $created] = $this->mirror($other);

        $this->assertTrue($created);
        $this->assertNotSame($first->quizid, $second->quizid);
    }

    /**
     * A teacher who edits a question in the bank makes a new version of it; the quiz keeps the one it was made with.
     */
    public function test_the_questions_are_pinned_against_a_bank_edit(): void {
        global $DB;
        $course = $this->course();
        [$row] = $this->mirror($course);
        $before = $this->questions_in((int) $row->quizid);
        $items = mirror_store::items((int) $row->id);
        $question = $DB->get_record('question', ['id' => $items['u-01']->questionid], '*', MUST_EXIST);
        $question->category = $DB->get_field('question_bank_entries', 'questioncategoryid', [
            'id' => $DB->get_field('question_versions', 'questionbankentryid', ['questionid' => $question->id]),
        ]);

        $this->getDataGenerator()->get_plugin_generator('core_question')
            ->update_question($question, null, ['name' => 'Edited by a teacher']);

        $entry = $DB->get_field('question_versions', 'questionbankentryid', ['questionid' => $question->id]);
        $this->assertSame(2, $DB->count_records('question_versions', ['questionbankentryid' => $entry]));
        $this->assertSame($before, $this->questions_in((int) $row->quizid));
        $slotid = $DB->get_field('quiz_slots', 'id', ['quizid' => $row->quizid, 'slot' => $items['u-01']->slot]);
        $this->assertSame('1', $DB->get_field('question_references', 'version', [
            'itemid' => $slotid, 'component' => 'mod_quiz', 'questionarea' => 'slot',
        ]));
    }

    /**
     * A quiz that was shown again or whose permissions were lost (a restored course) is locked again on the next use.
     */
    public function test_the_lock_is_applied_again(): void {
        global $DB;
        $course = $this->course();
        [$row] = $this->mirror($course);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $context = \context_module::instance($row->cmid);
        \core_courseformat\formatactions::cm((int) $course->id)->set_visibility((int) $row->cmid, 1, 1);
        $DB->delete_records('role_capabilities', ['contextid' => $context->id]);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertTrue(has_capability('mod/quiz:attempt', $context, $student));

        $this->mirror($course);
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertFalse(has_capability('mod/quiz:attempt', $context, $student));
        $this->assertSame('0', $DB->get_field('course_modules', 'visible', ['id' => $row->cmid]));
    }

    /**
     * A quiz that a teacher deleted is made again, with a new mapping, when its version is sent again.
     */
    public function test_a_deleted_quiz_is_made_again(): void {
        $course = $this->course();
        [$first] = $this->mirror($course);
        \core_courseformat\formatactions::cm((int) $course->id)->delete((int) $first->cmid);

        [$second, $created] = $this->mirror($course);

        $this->assertTrue($created);
        $this->assertNotSame($first->cmid, $second->cmid);
        $this->assertTrue(mirror_quiz::exists($second));
        $this->assertCount(11, mirror_store::items((int) $second->id));
    }

    /**
     * What the mirror writes is attributed to an administrator, and the user who was current is back afterwards.
     */
    public function test_the_service_identity_is_restored(): void {
        global $DB, $USER;
        $course = $this->course();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        [$row] = $this->mirror($course);

        $this->assertEquals($user->id, $USER->id);
        $questionid = mirror_store::items((int) $row->id)['l-01']->questionid;
        $owner = $DB->get_field('question_bank_entries', 'ownerid', [
            'id' => $DB->get_field('question_versions', 'questionbankentryid', ['questionid' => $questionid]),
        ]);
        $this->assertEquals(get_admin()->id, $owner);

        $this->setUser(0);
        service_identity::run(fn() => $this->assertEquals(get_admin()->id, $GLOBALS['USER']->id));
        $this->assertEmpty($USER->id);
    }
}
