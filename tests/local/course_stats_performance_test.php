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
 * Performance check of the completion statistics with about 1,000 students and 30 activities.
 *
 * Building the course takes a minute, so it runs only when the environment variable RUMBOS_PERF is set:
 *
 *   mdc exec -T -e RUMBOS_PERF=1 webserver vendor/bin/phpunit \
 *     public/course/format/rumbos/tests/local/course_stats_performance_test.php
 *
 * It prints the number of database reads and the time of a cold computation and of a cached one.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\local\course_stats::class)]
final class course_stats_performance_test extends \advanced_testcase {
    /** @var int Students in the course. */
    private const STUDENTS = 1000;

    /** @var int Activities with a completion condition. */
    private const ACTIVITIES = 30;

    /**
     * A cold computation reads the database a fixed number of times and returns correct totals.
     */
    public function test_one_thousand_students_thirty_activities(): void {
        global $DB;

        if (!getenv('RUMBOS_PERF')) {
            $this->markTestSkipped('Set RUMBOS_PERF=1 to run the performance check.');
        }
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['format' => 'rumbos', 'enablecompletion' => 1]);
        $cmids = [];
        for ($i = 0; $i < self::ACTIVITIES; $i++) {
            $page = $gen->create_module('page', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
            $cmids[] = (int) $page->cmid;
        }
        $teacher = $gen->create_and_enrol($course, 'editingteacher');
        $group = $gen->create_group(['courseid' => $course->id]);

        $rows = [];
        $expected = array_fill_keys($cmids, 0);
        for ($i = 0; $i < self::STUDENTS; $i++) {
            $student = $gen->create_and_enrol($course, 'student');
            if ($i % 2 === 0) {
                $gen->create_group_member(['groupid' => $group->id, 'userid' => $student->id]);
            }
            foreach ($cmids as $index => $cmid) {
                // A fixed pattern: states 0 to 3, with 1 and 2 counting as complete.
                $state = ($i + $index) % 4;
                $rows[] = (object) [
                    'coursemoduleid' => $cmid,
                    'userid' => $student->id,
                    'completionstate' => $state,
                    'viewed' => 0,
                    'timemodified' => time(),
                ];
                $expected[$cmid] += $state === 1 || $state === 2 ? 1 : 0;
            }
        }
        $DB->insert_records('course_modules_completion', $rows);
        if ($DB->get_dbfamily() === 'postgres') {
            // Right after a bulk insert PostgreSQL has no statistics yet and plans badly; a real site has them.
            $DB->execute('ANALYZE');
        }
        set_config('mincohort', 5, 'format_rumbos');
        \cache_helper::purge_by_definition('format_rumbos', 'coursestats');

        $reads = $DB->perf_get_reads();
        $start = microtime(true);
        $cold = course_stats::for_user($course->id, $teacher->id);
        $coldms = (microtime(true) - $start) * 1000;
        $coldreads = $DB->perf_get_reads() - $reads;

        $reads = $DB->perf_get_reads();
        $start = microtime(true);
        course_stats::for_user($course->id, $teacher->id);
        $warmms = (microtime(true) - $start) * 1000;
        $warmreads = $DB->perf_get_reads() - $reads;

        $reads = $DB->perf_get_reads();
        $start = microtime(true);
        $ingroup = course_stats::for_user($course->id, $teacher->id, (int) $group->id);
        $groupms = (microtime(true) - $start) * 1000;
        $groupreads = $DB->perf_get_reads() - $reads;

        fwrite(STDERR, sprintf(
            "\n%d students, %d activities: cold %d reads %.0f ms; cached %d reads %.0f ms; "
                . "one group (%d students) %d reads %.0f ms\n",
            self::STUDENTS,
            self::ACTIVITIES,
            $coldreads,
            $coldms,
            $warmreads,
            $warmms,
            $ingroup['tracked'],
            $groupreads,
            $groupms
        ));

        $this->assertSame(self::STUDENTS, $cold['tracked']);
        foreach ($cold['activities'] as $activity) {
            $this->assertSame($expected[$activity['cmid']], $activity['complete']);
        }
        $this->assertSame(self::STUDENTS / 2, $ingroup['tracked']);
        $this->assertLessThanOrEqual(3, $warmreads, 'A cached answer does not run the aggregate queries');
    }
}
