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

/**
 * Completion counts per activity for the teacher's heat map: aggregates only.
 *
 * Nothing here returns, caches or logs a user id, a name or a per-user row. The answer holds how many
 * tracked students completed or failed each activity, and the cache holds the same numbers.
 *
 * Who counts: the users Moodle's own completion reports track, that is actively enrolled users
 * (a suspended enrolment, a disabled enrolment method or one outside its dates does not count)
 * who hold the capability moodle/course:isincompletionreports in the course, and never deleted users.
 *
 * Groups: when the course uses groups and the user lacks moodle/site:accessallgroups, only the groups the
 * user belongs to are offered, and "all groups" means those groups together. Without group mode
 * every group is offered, as in the core reports.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_stats {
    /** @var int Contract schema version. */
    public const SCHEMA_VERSION = 1;

    /** @var int Seconds a result is served from the cache. Must match the ttl in db/caches.php. */
    public const CACHE_TTL = 300;

    /** @var int Seconds that must pass since a result was computed before a refresh recomputes it. */
    public const MIN_REFRESH_INTERVAL = 30;

    /** @var string Capability whose holders are counted as students (the one the core completion reports use). */
    private const TRACKED_CAPABILITY = 'moodle/course:isincompletionreports';

    /**
     * The statistics of a course, or of one of its groups, as the given user may see them.
     *
     * @param int $courseid
     * @param int $userid The viewer; the activities and groups listed are the ones this user can see.
     * @param int $groupid 0 for everyone the user may see, or a group the user may see.
     * @param bool $refresh Recompute even if a cached result exists, unless it is younger than MIN_REFRESH_INTERVAL.
     * @return array The CourseStats structure of the contract.
     * @throws \invalid_parameter_exception When the group is not one the user may see.
     */
    public static function for_user(int $courseid, int $userid, int $groupid = 0, bool $refresh = false): array {
        $course = get_course($courseid);
        $context = context_course::instance($courseid);
        [$groups, $restricted] = self::visible_groups($course, $context, $userid);
        if ($groupid !== 0 && !isset($groups[$groupid])) {
            throw new \invalid_parameter_exception('groupid');
        }

        $modinfo = get_fast_modinfo($course, $userid);
        $completioninfo = new \completion_info($course);
        $mincohort = config::get_min_cohort();
        $scope = self::scope($groupid, $groups, $restricted);

        $computed = self::computed($course, $context, $modinfo, $completioninfo, $scope, $mincohort, $refresh);
        $suppressed = $computed['tracked'] < $mincohort;

        $result = [
            'schemaVersion' => self::SCHEMA_VERSION,
            'generatedAt' => gmdate('Y-m-d\TH:i:s\Z', $computed['computedat']),
            'courseId' => $courseid,
            'groupId' => $groupid,
            'groups' => array_map(
                fn($id, $name) => ['id' => (int) $id, 'name' => $name],
                array_keys($groups),
                array_values($groups)
            ),
            'tracked' => $suppressed ? 0 : $computed['tracked'],
            'minCohort' => $mincohort,
            'suppressed' => $suppressed,
        ];
        if (!$suppressed) {
            $result['activities'] = self::activities($modinfo, $completioninfo, $computed['counts']);
        }
        return $result;
    }

    /**
     * The groups the user may see, and whether the user is held to their own groups.
     *
     * @param \stdClass $course
     * @param context_course $context
     * @param int $userid
     * @return array [id => formatted name, restricted]
     */
    private static function visible_groups(\stdClass $course, context_course $context, int $userid): array {
        $restricted = groups_get_course_groupmode($course) != NOGROUPS
            && !has_capability('moodle/site:accessallgroups', $context, $userid);
        $groups = [];
        foreach (groups_get_all_groups($course->id, $restricted ? $userid : 0, 0, 'g.id, g.name') as $group) {
            $groups[(int) $group->id] = format_string($group->name, true, ['context' => $context, 'escape' => false]);
        }
        return [$groups, $restricted];
    }

    /**
     * The groups whose members are counted.
     *
     * @param int $groupid Requested group, 0 for all.
     * @param array $groups Groups the user may see (keys are ids).
     * @param bool $restricted Whether the user is held to their own groups.
     * @return int[]|null Group ids, an empty array when nobody is in scope, or null for everyone.
     */
    private static function scope(int $groupid, array $groups, bool $restricted): ?array {
        if ($groupid !== 0) {
            return [$groupid];
        }
        return $restricted ? array_keys($groups) : null;
    }

    /**
     * Counts, from the cache when it holds a fresh result.
     *
     * @param \stdClass $course
     * @param context_course $context
     * @param \course_modinfo $modinfo
     * @param \completion_info $completioninfo
     * @param int[]|null $scope See scope().
     * @param int $mincohort
     * @param bool $refresh
     * @return array ['computedat' => int, 'tracked' => int, 'counts' => [cmid => [complete, failed]]]
     */
    private static function computed(
        \stdClass $course,
        context_course $context,
        \course_modinfo $modinfo,
        \completion_info $completioninfo,
        ?array $scope,
        int $mincohort,
        bool $refresh
    ): array {
        // The minimum is part of the key, so a changed setting is never answered from a result made under the old one.
        $key = $course->id . '_' . ($scope === null ? 'all' : md5(implode(',', $scope))) . '_' . $mincohort;
        $cache = \cache::make('format_rumbos', 'coursestats');
        $cached = $cache->get($key);
        if ($cached !== false && !($refresh && time() - $cached['computedat'] >= self::MIN_REFRESH_INTERVAL)) {
            return $cached;
        }

        $computed = ['computedat' => time(), 'tracked' => 0, 'counts' => []];
        if ($scope !== []) {
            [$enrolledsql, $params] = get_enrolled_sql($context, self::TRACKED_CAPABILITY, $scope ?? 0, true);
            $computed['tracked'] = self::tracked($enrolledsql, $params);
            if ($computed['tracked'] >= $mincohort) {
                $computed['counts'] = self::counts($modinfo, $completioninfo, $enrolledsql, $params);
            }
        }
        $cache->set($key, $computed);
        return $computed;
    }

    /**
     * How many users are tracked.
     *
     * @param string $enrolledsql A query returning user ids.
     * @param array $params
     * @return int
     */
    private static function tracked(string $enrolledsql, array $params): int {
        global $DB;

        return $DB->count_records_sql("SELECT COUNT(1) FROM ($enrolledsql) eu", $params);
    }

    /**
     * Completed and failed counts of every activity that has a completion condition, in one query.
     *
     * Completion states: complete and complete with pass count as complete, complete with fail as failed.
     * The state that hides a failure from the student is counted as neither, as the map shows it as unfinished.
     *
     * @param \course_modinfo $modinfo
     * @param \completion_info $completioninfo
     * @param string $enrolledsql A query returning the user ids in scope.
     * @param array $params
     * @return array cmid => [complete, failed]
     */
    private static function counts(
        \course_modinfo $modinfo,
        \completion_info $completioninfo,
        string $enrolledsql,
        array $params
    ): array {
        global $DB;

        $cmids = [];
        foreach ($modinfo->get_cms() as $cm) {
            if (!$cm->deletioninprogress && $completioninfo->is_enabled($cm) != COMPLETION_TRACKING_NONE) {
                $cmids[] = (int) $cm->id;
            }
        }
        if (!$cmids) {
            return [];
        }

        [$insql, $inparams] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED, 'rumbosstatcm');
        $sql = "SELECT cmc.coursemoduleid AS cmid,
                       SUM(CASE WHEN cmc.completionstate IN (:complete, :pass) THEN 1 ELSE 0 END) AS completecount,
                       SUM(CASE WHEN cmc.completionstate = :fail THEN 1 ELSE 0 END) AS failedcount
                  FROM {course_modules_completion} cmc
                  JOIN ($enrolledsql) eu ON eu.id = cmc.userid
                 WHERE cmc.coursemoduleid $insql
              GROUP BY cmc.coursemoduleid";
        $params = array_merge($params, $inparams, [
            'complete' => COMPLETION_COMPLETE,
            'pass' => COMPLETION_COMPLETE_PASS,
            'fail' => COMPLETION_COMPLETE_FAIL,
        ]);

        $counts = [];
        $rows = $DB->get_recordset_sql($sql, $params);
        foreach ($rows as $row) {
            $counts[(int) $row->cmid] = [(int) $row->completecount, (int) $row->failedcount];
        }
        $rows->close();
        return $counts;
    }

    /**
     * The activities the viewer can see, with their counts.
     *
     * @param \course_modinfo $modinfo Modinfo of the viewer.
     * @param \completion_info $completioninfo
     * @param array $counts cmid => [complete, failed]
     * @return array
     */
    private static function activities(\course_modinfo $modinfo, \completion_info $completioninfo, array $counts): array {
        $activities = [];
        foreach ($modinfo->cms as $cm) {
            if (!$cm->uservisible || !course_state::is_relevant($cm)) {
                continue;
            }
            $tracked = $completioninfo->is_enabled($cm) != COMPLETION_TRACKING_NONE;
            [$complete, $failed] = $tracked ? ($counts[(int) $cm->id] ?? [0, 0]) : [0, 0];
            $activities[] = [
                'cmid' => (int) $cm->id,
                'completionTracked' => $tracked,
                'complete' => $complete,
                'failed' => $failed,
            ];
        }
        return $activities;
    }
}
