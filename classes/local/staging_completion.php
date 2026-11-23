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
 * STAGING ONLY: marks a test student's tracked SCORM activities complete or incomplete, so the final-exam gate of
 * the Rumbos service can be exercised end to end.
 *
 * It does what a teacher does with "Override" in the activity completion report: it uses Moodle's own override
 * ({@see \completion_info::update_state()} with the override flag), so the state is recorded with the user who
 * overrode it and Moodle logs a course module completion event for each change. Nothing is written behind Moodle's
 * back, and the service sees the change through the completion web service like any other.
 *
 * It refuses unless the site's config.php says it is a staging site ({@see self::CONFIG_FLAG}). A production site
 * does not have that line, so the helper is inert there even though the file is installed with the plugin.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class staging_completion {
    /** @var string The $CFG property config.php must set to true on a staging site. */
    public const CONFIG_FLAG = 'format_rumbos_staging_tools';

    /** @var string The module that is marked unless another is asked for. */
    public const DEFAULT_MODULE = 'scorm';

    /**
     * Why the helper must not run on this site, or null when it may.
     *
     * Only the exact value true allows it: a missing flag, "1" or any other value refuses, so the default of every
     * site, production included, is to refuse.
     *
     * @return string|null
     */
    public static function refusal(): ?string {
        global $CFG;

        if (($CFG->{self::CONFIG_FLAG} ?? false) !== true) {
            return 'This is a staging-only tool and this site is not marked as a staging site. Add '
                . '$CFG->' . self::CONFIG_FLAG . ' = true; to config.php of a staging site to allow it. '
                . 'Never add that line to a production site.';
        }
        return null;
    }

    /**
     * The activities the helper would change, with their state now.
     *
     * Only activities that track completion count: the others record nothing in Moodle. The student must be a
     * real, active participant who is not an editor of the map (editors skip the gate, so marking them proves
     * nothing).
     *
     * @param int $courseid
     * @param int $userid
     * @param string $modname The module to mark.
     * @return array[] Each with cmid, name, modname, visible and state (the completion state now).
     * @throws \moodle_exception When the user is not a student of the course.
     */
    public static function plan(int $courseid, int $userid, string $modname = self::DEFAULT_MODULE): array {
        $course = get_course($courseid);
        $context = context_course::instance($courseid);
        $user = \core_user::get_user($userid, '*', MUST_EXIST);
        if ($user->deleted || isguestuser($user) || !is_enrolled($context, $user, '', true)) {
            throw new \moodle_exception('invaliduser');
        }
        if (has_capability('format/rumbos:editmap', $context, $user)) {
            throw new \moodle_exception('invaliduser', '', '', null, 'The user is an editor of the course, not a student.');
        }

        $completion = new \completion_info($course);
        $plan = [];
        foreach ($completion->get_activities() as $cm) {
            if ($cm->modname !== $modname) {
                continue;
            }
            $plan[] = [
                'cmid' => (int) $cm->id,
                'name' => $cm->name,
                'modname' => $cm->modname,
                'visible' => (bool) $cm->visible,
                'state' => (int) $completion->get_data($cm, false, $userid)->completionstate,
            ];
        }
        return $plan;
    }

    /**
     * Marks every activity of the plan complete or incomplete for the user, as the current user's override.
     *
     * The current user needs moodle/course:overridecompletion, as in the completion report.
     *
     * @param int $courseid
     * @param int $userid
     * @param bool $complete True to mark complete, false to mark incomplete.
     * @param string $modname The module to mark.
     * @return array[] The plan again, with the state after the change.
     */
    public static function apply(int $courseid, int $userid, bool $complete, string $modname = self::DEFAULT_MODULE): array {
        $course = get_course($courseid);
        $completion = new \completion_info($course);
        $target = $complete ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;

        $activities = $completion->get_activities();
        foreach (self::plan($courseid, $userid, $modname) as $item) {
            $completion->update_state($activities[$item['cmid']], $target, $userid, true);
        }
        return self::plan($courseid, $userid, $modname);
    }
}
