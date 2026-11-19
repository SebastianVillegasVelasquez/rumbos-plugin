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
 * Who a result statement may complete a course for.
 *
 * Only a student. A teacher, a manager, a site administrator or anybody who may edit the map never completes the
 * course, even when also enrolled as a student: a false refusal is fixed by an administrator in minutes, a false
 * completion would be a certificate that was not earned. The receiver has no session, so a role switched in a
 * browser is never seen: what counts is what the person is.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class result_access {
    /**
     * The course a statement is about.
     *
     * @param int $courseid
     * @return \stdClass The course record.
     * @throws result_rejection 422 unknown_course when there is no such course or it does not use the Rumbos format.
     */
    public static function course(int $courseid): \stdClass {
        global $DB;

        $course = $courseid > SITEID ? $DB->get_record('course', ['id' => $courseid]) : false;
        if (!$course || $course->format !== 'rumbos') {
            throw new result_rejection(422, 'unknown_course');
        }
        return $course;
    }

    /**
     * The student a pseudonym belongs to in a course, if they may complete it.
     *
     * @param \stdClass $course The course record.
     * @param string $pseudonym The statement's `sub`.
     * @return int The Moodle user id.
     * @throws result_rejection 422 unknown_user, not_enrolled or role_not_allowed.
     */
    public static function student(\stdClass $course, string $pseudonym): int {
        $context = context_course::instance($course->id);
        // Every user who has an enrolment, active or not, so that "not enrolled any more" differs from "unknown".
        $enrolled = get_enrolled_users($context, '', 0, 'u.id', null, 0, 0, false);
        $userid = pseudonym::find(array_keys($enrolled), $pseudonym);
        if ($userid === null) {
            throw new result_rejection(422, 'unknown_user');
        }
        self::assert_may_complete($context, $userid);
        return $userid;
    }

    /**
     * Checks that a user is a student of the course: not a teacher, manager or administrator, and actively enrolled.
     *
     * @param context_course $context
     * @param int $userid
     * @throws result_rejection 422 role_not_allowed or not_enrolled.
     */
    public static function assert_may_complete(context_course $context, int $userid): void {
        // The last argument of has_capability() switches the administrator bypass off: an administrator holds every
        // capability, and that must not make them a student.
        $isstaff = is_siteadmin($userid) || has_capability('format/rumbos:editmap', $context, $userid, false);
        if ($isstaff || !has_capability('moodle/course:isincompletionreports', $context, $userid, false)) {
            throw new result_rejection(422, 'role_not_allowed');
        }
        if (!is_enrolled($context, $userid, '', true)) {
            throw new result_rejection(422, 'not_enrolled');
        }
    }
}
