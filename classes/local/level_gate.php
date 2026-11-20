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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/completionlib.php');

/**
 * Decides whether a user may open the Rumbos content of a level.
 *
 * A student may open level N only after completing level N-1 in Moodle (course completion of the previous course in the
 * configured chain). The gate decides at launch time: when it says no, the plugin does not sign the launch assertion and
 * does not render the iframe. It reads the same Moodle completion that the result passback writes, so Moodle stays the single
 * source of truth.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class level_gate {
    /** @var string Capability that records an exception for one user in one level. */
    public const SKIP_CAPABILITY = 'format/rumbos:skiplevelcheck';

    /**
     * Decides for a user in a course, honouring the admin switch.
     *
     * With the switch off the answer is always {@see level_decision::OFF}, which opens the content as it always did.
     *
     * @param int $courseid The level being opened.
     * @param int $userid
     * @return level_decision
     */
    public static function check(int $courseid, int $userid): level_decision {
        if (!config::is_level_gate_enabled()) {
            return new level_decision(level_decision::OFF);
        }
        return self::decide($courseid, $userid, config::get_level_chain());
    }

    /**
     * Decides for a user in a course against a chain, whatever the switch says.
     *
     * The dry-run report uses this to show what would happen if the switch were on. The order matters: whether the course has
     * a prerequisite at all, then the people who are never blocked, and only then the completion check. Any failure of that
     * check closes the content.
     *
     * @param int $courseid The level being opened.
     * @param int $userid
     * @param string[] $chain The ordered level ID numbers.
     * @param bool $log Whether a failed check is written to the error log (the report turns it off and prints it instead).
     * @return level_decision
     */
    public static function decide(int $courseid, int $userid, array $chain, bool $log = true): level_decision {
        try {
            $course = get_course($courseid);
            $prerequisiteid = level_chain::predecessor($chain, (string) $course->idnumber);
            if ($prerequisiteid === null) {
                return new level_decision(level_decision::NOT_GATED);
            }

            $context = context_course::instance($courseid);
            if (has_capability('format/rumbos:editmap', $context, $userid)) {
                return new level_decision(level_decision::BYPASS);
            }
            if (has_capability(self::SKIP_CAPABILITY, $context, $userid)) {
                return new level_decision(level_decision::OVERRIDE);
            }

            return self::check_completion($prerequisiteid, $courseid, $userid, $log);
        } catch (\Throwable $e) {
            return self::failed("course $courseid, user $userid: " . get_class($e) . ': ' . $e->getMessage(), null, $log);
        }
    }

    /**
     * Reads the user's completion of the previous level.
     *
     * @param string $idnumber ID number of the previous level.
     * @param int $courseid The level being opened (for the log).
     * @param int $userid
     * @param bool $log Whether a failure is logged.
     * @return level_decision
     */
    private static function check_completion(string $idnumber, int $courseid, int $userid, bool $log): level_decision {
        global $DB;

        $found = $DB->get_records_select('course', 'idnumber = :idnumber AND id <> :site', [
            'idnumber' => $idnumber,
            'site' => SITEID,
        ]);
        if (count($found) !== 1) {
            return self::failed("course $courseid, user $userid: " . ($found ? 'several courses share' : 'no course has')
                . " the level ID number '$idnumber'", null, $log);
        }
        $prerequisite = reset($found);

        $info = new \completion_info($prerequisite);
        if (!$info->is_enabled()) {
            return self::failed("course $courseid, user $userid: completion tracking is off in the previous level "
                . "'$idnumber' (course {$prerequisite->id}) or for the site", $prerequisite, $log);
        }
        if (!$info->has_criteria()) {
            return self::failed("course $courseid, user $userid: the previous level '$idnumber' (course {$prerequisite->id}) "
                . 'has no course completion criteria, so nobody can ever complete it', $prerequisite, $log);
        }
        if ($info->is_course_complete($userid)) {
            return new level_decision(level_decision::MET, $prerequisite);
        }

        $enrolled = is_enrolled(context_course::instance($prerequisite->id), $userid);
        return new level_decision(
            level_decision::LOCKED,
            $prerequisite,
            $enrolled ? level_decision::REASON_INCOMPLETE : level_decision::REASON_NOT_ENROLLED
        );
    }

    /**
     * Builds a closed decision for a check that could not run, and logs why.
     *
     * @param string $message What went wrong, precisely.
     * @param \stdClass|null $prerequisite The previous level, when it was found.
     * @param bool $log Whether to write it to the error log.
     * @return level_decision
     */
    private static function failed(string $message, ?\stdClass $prerequisite, bool $log): level_decision {
        if ($log) {
            // Not debugging(): that prints nothing at the production debug level, and this must reach the server log.
            // phpcs:ignore moodle.PHP.ForbiddenFunctions.FoundWithAlternative
            error_log('format_rumbos level gate failed closed: ' . $message);
        }
        return new level_decision(level_decision::ERROR, $prerequisite, $message);
    }
}
