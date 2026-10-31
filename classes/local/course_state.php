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

use cm_info;
use completion_info;
use core\context\course as context_course;
use course_modinfo;

/**
 * Computes the CourseState (shared contract v5) for one user.
 *
 * Contains no Rumbos logic: it only reports what Moodle itself says the user
 * can see, restrict-wise and completion-wise. Completion is always read afresh.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_state {
    /** @var int Contract schema version. */
    public const SCHEMA_VERSION = 1;

    /**
     * Builds the state of a course as the given user sees it.
     *
     * @param int $courseid
     * @param int $userid The user the state is computed for; callers pass the current user.
     * @return array The CourseState structure.
     */
    public static function for_user(int $courseid, int $userid): array {
        self::forget_cached_completion($courseid, $userid);

        $course = get_course($courseid);
        $modinfo = get_fast_modinfo($course, $userid);
        $completioninfo = new completion_info($course);
        $context = context_course::instance($courseid);

        $activities = [];
        $unavailable = [];
        foreach ($modinfo->cms as $cm) {
            if (!self::is_relevant($cm)) {
                continue;
            }
            $activity = self::activity($cm, $completioninfo, $context, $userid);
            if ($activity === null) {
                // The user cannot see this activity at all: expose the id only.
                $unavailable[] = (int) $cm->id;
            } else {
                $activities[] = $activity;
            }
        }

        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'courseId' => (int) $courseid,
            // An ISO 8601 string in UTC, which is the type the Rumbos app validates.
            'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'courseCompletionEnabled' => (bool) $completioninfo->is_enabled(),
            'sections' => self::sections($course, $modinfo),
            'activities' => $activities,
            'unavailableCmids' => $unavailable,
        ];
    }

    /**
     * Drops the cached completion data of a user in a course, so that what follows reads the database.
     *
     * Moodle caches the current user's completion for up to an hour in an application cache. On a
     * site whose web servers do not share that cache a server can keep serving data that another one
     * has already replaced, and the map would show an activity as unfinished after it was finished.
     * The cache is also what availability conditions that depend on completion read, so dropping it
     * first keeps the restrictions as fresh as the completion. It is rebuilt by the reads that follow.
     *
     * The key is the one completion_info uses for its own entries ("{userid}_{courseid}").
     *
     * @param int $courseid
     * @param int $userid
     */
    private static function forget_cached_completion(int $courseid, int $userid): void {
        \cache::make('core', 'completion')->delete($userid . '_' . $courseid);
    }

    /**
     * The sections the user can see.
     *
     * @param \stdClass $course
     * @param course_modinfo $modinfo Modinfo of the user the state is for.
     * @return array
     */
    private static function sections(\stdClass $course, course_modinfo $modinfo): array {
        $sections = [];
        foreach ($modinfo->get_section_info_all() as $section) {
            if ($section->uservisible) {
                $sections[] = [
                    'id' => (int) $section->id,
                    'number' => (int) $section->section,
                    'name' => self::plain_text(get_section_name($course, $section)),
                ];
            }
        }
        return $sections;
    }

    /**
     * The state of one activity for the user.
     *
     * @param cm_info $cm
     * @param completion_info $completioninfo
     * @param context_course $context
     * @param int $userid
     * @return array|null The activity entry, or null when the user cannot see it at all.
     */
    private static function activity(cm_info $cm, completion_info $completioninfo, context_course $context, int $userid): ?array {
        $info = null;
        $url = null;
        if ($cm->uservisible) {
            $availability = $cm->visible ? 'available' : 'hidden';
            $url = $cm->url ? $cm->url->out(false) : null;
        } else {
            $info = self::plain_text((string) $cm->availableinfo);
            if ($info === '') {
                return null;
            }
            $availability = 'restricted';
        }

        return [
            'cmid' => (int) $cm->id,
            'name' => self::plain_text($cm->get_formatted_name(['context' => $context, 'escape' => false])),
            'modname' => $cm->modname,
            'sectionId' => (int) $cm->get_section_info()->id,
            'url' => $url,
            'availability' => $availability,
            'availabilityInfo' => $info,
            'completion' => self::completion_state($completioninfo, $cm, $userid),
        ];
    }

    /**
     * Activities that Rumbos can show: they have a view page and are meant to be linked.
     *
     * @param cm_info $cm
     * @return bool
     */
    public static function is_relevant(cm_info $cm): bool {
        if ($cm->deletioninprogress || $cm->modname === 'label' || !$cm->url) {
            return false;
        }
        return !plugin_supports('mod', $cm->modname, FEATURE_NO_VIEW_LINK, false);
    }

    /**
     * Maps Moodle's completion state to the contract vocabulary.
     *
     * @param completion_info $completioninfo
     * @param cm_info $cm
     * @param int $userid
     * @return string none|incomplete|complete|failed
     */
    private static function completion_state(completion_info $completioninfo, cm_info $cm, int $userid): string {
        if ($completioninfo->is_enabled($cm) == COMPLETION_TRACKING_NONE) {
            return 'none';
        }
        // The whole course in one query (Moodle ignores the flag for any user but the current one).
        $data = $completioninfo->get_data($cm, true, $userid);
        switch ((int) $data->completionstate) {
            case COMPLETION_COMPLETE:
            case COMPLETION_COMPLETE_PASS:
                return 'complete';
            case COMPLETION_COMPLETE_FAIL:
                return 'failed';
            default:
                return 'incomplete';
        }
    }

    /**
     * Reduces possibly formatted HTML to single-line plain text.
     *
     * @param string $html
     * @return string
     */
    private static function plain_text(string $html): string {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $text));
    }
}
