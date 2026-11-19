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
use core\context\system as context_system;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/completion/criteria/completion_criteria_role.php');

/**
 * What a course needs so that a verified result can be recorded and complete it, and how to set that up.
 *
 * The state is: one manual grade item (idnumber {@see self::GRADE_IDNUMBER}) holding the best percent; one
 * "manual completion by" criterion for a role nobody holds ({@see self::ROLE_SHORTNAME}); and course completion on.
 * The reasons are in docs/RESULT-PASSBACK-AUDIT.md. Nothing here runs on install or upgrade: the administrator's
 * command (cli/configure_course.php) is the only caller of {@see self::plan()}.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class result_course {
    /** @var string The idnumber of the grade item that holds the best percent. */
    public const GRADE_IDNUMBER = 'rumbos_result';

    /** @var string The shortname of the role that marks the completion criterion. */
    public const ROLE_SHORTNAME = 'rumbosresult';

    /**
     * The grade item that holds the best percent of the course, or null.
     *
     * @param int $courseid
     * @return \grade_item|null
     */
    public static function grade_item(int $courseid): ?\grade_item {
        $item = \grade_item::fetch(['courseid' => $courseid, 'idnumber' => self::GRADE_IDNUMBER]);
        return $item ?: null;
    }

    /**
     * The id of the role that marks the criterion, or null when the site does not have it yet.
     *
     * @return int|null
     */
    public static function role_id(): ?int {
        global $DB;

        $id = $DB->get_field('role', 'id', ['shortname' => self::ROLE_SHORTNAME]);
        return $id === false ? null : (int) $id;
    }

    /**
     * The completion criterion the receiver marks in a course, or null.
     *
     * @param int $courseid
     * @return \completion_criteria_role|null
     */
    public static function criterion(int $courseid): ?\completion_criteria_role {
        $roleid = self::role_id();
        if ($roleid === null) {
            return null;
        }
        $info = new \completion_info((object) ['id' => $courseid]);
        foreach ($info->get_criteria(COMPLETION_CRITERIA_TYPE_ROLE) as $criterion) {
            if ((int) $criterion->role === $roleid) {
                return $criterion;
            }
        }
        return null;
    }

    /**
     * Why the receiver cannot apply a result to a course yet.
     *
     * @param \stdClass $course The course record.
     * @return string|null Null when it can; else `completion_disabled`, `no_grade_item` or `no_criterion`.
     */
    public static function not_ready_reason(\stdClass $course): ?string {
        if (!(new \completion_info($course))->is_enabled()) {
            return 'completion_disabled';
        }
        if (self::grade_item((int) $course->id) === null) {
            return 'no_grade_item';
        }
        if (self::criterion((int) $course->id) === null) {
            return 'no_criterion';
        }
        return null;
    }

    /**
     * The value to show as "grade to pass" in the gradebook: a display mirror that decides nothing.
     *
     * Moodle compares with "greater or equal". The percent has two decimals, so "strictly more than 80" is "80.01 or
     * more" and the mirror for `gt` is one hundredth above the threshold.
     *
     * @param string $passpercent The threshold, for example "80".
     * @param string $operator `gt` or `gte`.
     * @return float
     */
    public static function mirror_pass_mark(string $passpercent, string $operator): float {
        $mark = round((float) $passpercent * 100) / 100;
        return $operator === 'gt' ? round(($mark + 0.01) * 100) / 100 : $mark;
    }

    /**
     * Everything an administrator needs to see about a course, read only.
     *
     * @param int $courseid
     * @return array
     */
    public static function inspect(int $courseid): array {
        global $CFG, $DB, $COMPLETION_CRITERIA_TYPES;

        $course = $DB->get_record('course', ['id' => $courseid], 'id, shortname, fullname, format, enablecompletion', MUST_EXIST);
        $info = new \completion_info($course);
        $context = context_course::instance($courseid);

        $criteria = [];
        foreach ($info->get_criteria() as $criterion) {
            $criteria[] = [
                'id' => (int) $criterion->id,
                'type' => $COMPLETION_CRITERIA_TYPES[$criterion->criteriatype] ?? (string) $criterion->criteriatype,
                'title' => strip_tags((string) $criterion->get_title()),
            ];
        }
        $method = fn(?int $type): string =>
            (int) $info->get_aggregation_method($type) === COMPLETION_AGGREGATION_ANY ? 'any' : 'all';

        $items = [];
        foreach (\grade_item::fetch_all(['courseid' => $courseid]) ?: [] as $item) {
            $items[] = [
                'id' => (int) $item->id,
                'type' => $item->itemtype,
                'name' => $item->itemtype === 'course' ? 'course total' : (string) ($item->itemname ?? ''),
                'idnumber' => (string) ($item->idnumber ?? ''),
                'grademax' => (float) $item->grademax,
                'gradepass' => (float) $item->gradepass,
            ];
        }

        $roleid = self::role_id();
        $role = ['exists' => $roleid !== null, 'id' => $roleid, 'markcomplete' => false, 'assignments' => 0];
        if ($roleid !== null) {
            $role['markcomplete'] = in_array($roleid, array_keys(
                get_roles_with_capability('moodle/course:markcomplete', CAP_ALLOW, $context)
            ), true);
            $role['assignments'] = $DB->count_records('role_assignments', ['roleid' => $roleid]);
        }

        return [
            'course' => ['id' => (int) $course->id, 'shortname' => $course->shortname, 'format' => $course->format],
            'sitecompletion' => !empty($CFG->enablecompletion),
            'coursecompletion' => !empty($course->enablecompletion),
            'criteria' => $criteria,
            'aggregation' => ['overall' => $method(null), 'role' => $method(COMPLETION_CRITERIA_TYPE_ROLE)],
            'gradeitems' => $items,
            'role' => $role,
            'hasresultitem' => self::grade_item($courseid) !== null,
            'hascriterion' => self::criterion($courseid) !== null,
            'tracked' => $info->get_num_tracked_users(),
            'completed' => $DB->count_records_select('course_completions', 'course = ? AND timecompleted > 0', [$courseid]),
        ];
    }

    /**
     * The steps that bring a course to the desired state, each with what it would change.
     *
     * A step that is already satisfied is not in the list, so an empty list means "nothing to do". Running the
     * steps twice therefore changes nothing the second time.
     *
     * @param int $courseid
     * @param string $passpercent The threshold the mirror is built from, for example "80".
     * @param string $operator `gt` or `gte`.
     * @param bool $aggregationany Also set the overall aggregation to "any" (needed for "check-in or final" when
     *     the course has other criteria).
     * @return array[] Each `['key' => string, 'text' => string, 'run' => callable]`.
     * @throws \moodle_exception When the site has completion switched off: that is a site setting, not changed here.
     */
    public static function plan(int $courseid, string $passpercent, string $operator, bool $aggregationany): array {
        global $CFG, $DB;

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        if (empty($CFG->enablecompletion)) {
            throw new \moodle_exception('errorsitecompletion', 'format_rumbos');
        }
        $steps = [];

        if (empty($course->enablecompletion)) {
            $steps[] = [
                'key' => 'enablecompletion',
                'text' => 'Switch course completion on for the course.',
                'run' => function () use ($courseid) {
                    global $DB;
                    $DB->set_field('course', 'enablecompletion', 1, ['id' => $courseid]);
                    rebuild_course_cache($courseid, true);
                },
            ];
        }

        $roleid = self::role_id();
        if ($roleid === null) {
            $steps[] = [
                'key' => 'createrole',
                'text' => 'Create the role "' . self::ROLE_SHORTNAME . '" with only moodle/course:markcomplete, ' .
                    'at course level, assigned to nobody.',
                'run' => fn() => self::create_role(),
            ];
        } else {
            $granted = array_keys(
                get_roles_with_capability('moodle/course:markcomplete', CAP_ALLOW, context_course::instance($courseid))
            );
            if (!in_array($roleid, $granted, true)) {
                $steps[] = [
                    'key' => 'rolecapability',
                    'text' => 'Allow moodle/course:markcomplete for the role "' . self::ROLE_SHORTNAME .
                        '" (without it the completion form would drop the criterion).',
                    'run' => fn() => assign_capability(
                        'moodle/course:markcomplete',
                        CAP_ALLOW,
                        $roleid,
                        context_system::instance()->id,
                        true
                    ),
                ];
            }
        }

        $mirror = self::mirror_pass_mark($passpercent, $operator);
        $item = self::grade_item($courseid);
        if ($item === null) {
            $steps[] = [
                'key' => 'gradeitem',
                'text' => 'Create the grade item "Rumbos result" (idnumber ' . self::GRADE_IDNUMBER .
                    ', 0 to 100, grade to pass ' . format_float($mirror, 2) . ' as a display mirror).',
                'run' => function () use ($courseid, $mirror) {
                    $item = new \grade_item([
                        'courseid' => $courseid,
                        'itemtype' => 'manual',
                        'itemname' => get_string('gradeitemname', 'format_rumbos'),
                        'idnumber' => self::GRADE_IDNUMBER,
                        'gradetype' => GRADE_TYPE_VALUE,
                        'grademin' => 0,
                        'grademax' => 100,
                        'gradepass' => $mirror,
                    ], false);
                    $item->insert('format_rumbos');
                },
            ];
        } else if (\grade_floats_different((float) $item->gradepass, $mirror)) {
            $steps[] = [
                'key' => 'gradepass',
                'text' => 'Change the grade to pass of "Rumbos result" from ' . format_float($item->gradepass, 2) .
                    ' to ' . format_float($mirror, 2) . ' (a display mirror, it decides nothing).',
                'run' => function () use ($courseid, $mirror) {
                    $item = self::grade_item($courseid);
                    $item->gradepass = $mirror;
                    $item->update('format_rumbos');
                },
            ];
        }

        if (self::criterion($courseid) === null) {
            $steps[] = [
                'key' => 'criterion',
                'text' => 'Add the course completion criterion "Manual completion by Rumbos result".',
                'run' => function () use ($courseid) {
                    $criterion = new \completion_criteria_role(['course' => $courseid, 'role' => self::role_id()]);
                    $criterion->insert();
                    self::announce($courseid);
                },
            ];
        }

        $info = new \completion_info($course);
        if ($aggregationany && (int) $info->get_aggregation_method() !== COMPLETION_AGGREGATION_ANY) {
            $steps[] = [
                'key' => 'aggregation',
                'text' => 'Set the overall aggregation to "any criterion" (so that a pass of the check-in or of the final, ' .
                    'or any other criterion the course has, completes it).',
                'run' => function () use ($courseid) {
                    $aggregation = new \completion_aggregation(['course' => $courseid, 'criteriatype' => null]);
                    $aggregation->setMethod(COMPLETION_AGGREGATION_ANY);
                    $aggregation->save();
                    self::announce($courseid);
                },
            ];
        }

        return $steps;
    }

    /**
     * The steps that undo what {@see self::plan()} did, without touching a completion that happened.
     *
     * The criterion and the grade item go. The grade item goes only when it holds no grade, unless told to drop them.
     * The role, the overall aggregation and every completion record stay.
     *
     * @param int $courseid
     * @param bool $dropgrades Delete the grade item even when it holds grades.
     * @return array[] Each `['key' => string, 'text' => string, 'run' => callable]`.
     */
    public static function plan_revert(int $courseid, bool $dropgrades): array {
        global $DB;

        $steps = [];
        $criterion = self::criterion($courseid);
        if ($criterion !== null) {
            $steps[] = [
                'key' => 'removecriterion',
                'text' => 'Remove the course completion criterion "Manual completion by Rumbos result". ' .
                    'Completions that already happened stay.',
                'run' => function () use ($courseid, $criterion) {
                    global $DB;
                    $DB->delete_records('course_completion_criteria', ['id' => $criterion->id]);
                    $DB->delete_records('course_completion_crit_compl', ['criteriaid' => $criterion->id]);
                    self::announce($courseid);
                },
            ];
        }
        $item = self::grade_item($courseid);
        if ($item !== null) {
            $grades = $DB->count_records_select('grade_grades', 'itemid = ? AND finalgrade IS NOT NULL', [$item->id]);
            if ($grades === 0 || $dropgrades) {
                $steps[] = [
                    'key' => 'removegradeitem',
                    'text' => 'Delete the grade item "Rumbos result" and its ' . $grades . ' grade(s).',
                    'run' => function () use ($courseid) {
                        self::grade_item($courseid)->delete('format_rumbos');
                    },
                ];
            }
        }
        return $steps;
    }

    /**
     * Creates the role that marks the criterion: no capability but marking, and nobody holds it.
     */
    private static function create_role(): void {
        $roleid = create_role(
            get_string('rolename', 'format_rumbos'),
            self::ROLE_SHORTNAME,
            get_string('roledescription', 'format_rumbos'),
            ''
        );
        set_role_contextlevels($roleid, [CONTEXT_COURSE]);
        assign_capability('moodle/course:markcomplete', CAP_ALLOW, $roleid, context_system::instance()->id, true);
    }

    /**
     * Tells Moodle that the completion settings of a course changed, as its own form does.
     *
     * @param int $courseid
     */
    private static function announce(int $courseid): void {
        \core\event\course_completion_updated::create([
            'courseid' => $courseid,
            'context' => context_course::instance($courseid),
        ])->trigger();
    }
}
