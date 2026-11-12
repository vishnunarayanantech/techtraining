<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace tool_courseproctoring;

use tool_courseproctoring\local\close_service;
use tool_courseproctoring\local\course_key_repository;
use tool_courseproctoring\local\session_repository;
use tool_courseproctoring\task\close_session;

/**
 * Event observers (registered in db/events.php).
 *
 * The events that end a learner's proctoring (course completed, un-enrolled or suspended, user deleted) are observed
 * after the database transaction that raised them has committed ('internal' => false), and none of them can break the
 * action that raised it: every failure is caught and turned into a queued retry.
 *
 * @package    tool_courseproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /** @var bool[] Ids of the courses that are being deleted in this request. */
    private static array $deleting = [];

    /**
     * Remember that a course is being deleted in this request (called from the before_course_deleted hook).
     *
     * Deleting a course un-enrols everybody in it. Closing every learner's vendor session during that would make the
     * deletion wait for the vendor once per learner, so the un-enrolments of a course that is being deleted are left
     * alone; the course's session rows are removed when the deletion is done.
     *
     * @param int $courseid
     */
    public static function note_course_deletion(int $courseid): void {
        self::$deleting[$courseid] = true;
    }

    /**
     * A learner completed a course: close their proctoring session.
     *
     * @param \core\event\course_completed $event
     */
    public static function course_completed(\core\event\course_completed $event): void {
        self::end_sessions((int) $event->relateduserid, (int) $event->courseid);
    }

    /**
     * A learner's enrolment in a course was removed or changed: if they are no longer actively enrolled (un-enrolled,
     * suspended, or the enrolment method is disabled), close their proctoring session. A learner who is still actively
     * enrolled through another method keeps theirs.
     *
     * @param \core\event\base $event user_enrolment_deleted or user_enrolment_updated
     */
    public static function user_enrolment_changed(\core\event\base $event): void {
        $userid = (int) $event->relateduserid;
        $courseid = (int) $event->courseid;
        if ($userid <= 0 || $courseid <= 0 || isset(self::$deleting[$courseid])) {
            return;
        }
        try {
            $context = \context_course::instance($courseid, IGNORE_MISSING);
            if ($context && is_enrolled($context, $userid, '', true)) {
                return;
            }
        } catch (\Throwable $e) {
            debugging('tool_courseproctoring: could not check the enrolment: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return;
        }
        self::end_sessions($userid, $courseid);
    }

    /**
     * A user was deleted: close their proctoring sessions, then forget them (their personal data goes with them).
     *
     * @param \core\event\user_deleted $event
     */
    public static function user_deleted(\core\event\user_deleted $event): void {
        $userid = (int) $event->objectid;
        self::end_sessions($userid, 0);
        (new session_repository())->delete_for_user($userid);
    }

    /**
     * A course was deleted: forget its Proctor Key and its session ledger rows.
     *
     * @param \core\event\course_deleted $event
     */
    public static function course_deleted(\core\event\course_deleted $event): void {
        $courseid = (int) $event->objectid;
        (new course_key_repository())->delete_for_course($courseid);
        (new session_repository())->delete_for_course($courseid);
        unset(self::$deleting[$courseid]);
    }

    /**
     * Close the active proctoring sessions of a learner, in one course or (with 0) in every course.
     *
     * Each close is tried once, with the vendor's bounded timeouts. A retryable failure queues a retry task; nothing here
     * ever throws to the caller.
     *
     * @param int $userid
     * @param int $courseid
     */
    private static function end_sessions(int $userid, int $courseid): void {
        if ($userid <= 0) {
            return;
        }
        try {
            $sessions = (new session_repository())->get_active_for_user($userid, $courseid);
        } catch (\Throwable $e) {
            debugging('tool_courseproctoring: could not read the sessions: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return;
        }

        foreach ($sessions as $session) {
            $id = (int) $session->id;
            try {
                if ((new close_service())->close_session($id) === close_service::RETRY) {
                    close_session::queue($id);
                }
            } catch (\Throwable $e) {
                debugging('tool_courseproctoring: closing the proctoring session failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
                try {
                    close_session::queue($id);
                } catch (\Throwable $queueerror) {
                    debugging('tool_courseproctoring: could not queue a retry: ' . $queueerror->getMessage(), DEBUG_DEVELOPER);
                }
            }
        }
    }
}
