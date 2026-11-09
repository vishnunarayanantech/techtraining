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

namespace tool_quizproctoring;

use tool_quizproctoring\local\close_service;
use tool_quizproctoring\local\quiz_key_repository;
use tool_quizproctoring\local\session_repository;
use tool_quizproctoring\task\close_session;

/**
 * Event observers.
 *
 * Identifying the attempt reliably (verified against mod_quiz 4.5.3, quiz_attempt::fire_state_transition_event()):
 * for \mod_quiz\event\attempt_submitted and attempt_abandoned, $event->objectid is the quiz_attempts id,
 * $event->courseid the course, $event->contextinstanceid the quiz course module id, and
 * $event->relateduserid the attempt's owner ($event->userid is only the actor, which may be a teacher or cron).
 * Only the attempt id is needed here: the session ledger holds everything else.
 *
 * @package    tool_quizproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * A quiz attempt was submitted or abandoned: close its vendor session if this plugin started one.
     *
     * Registered with 'internal' => false, so this runs after the quiz transaction has committed. It must
     * never break the quiz: all failures are caught and turned into a queued retry.
     *
     * @param \core\event\base $event
     */
    public static function attempt_ended(\core\event\base $event): void {
        $attemptid = (int) $event->objectid;
        try {
            $outcome = (new close_service())->close_attempt($attemptid);
            if ($outcome === close_service::RETRY) {
                close_session::queue($attemptid);
            }
        } catch (\Throwable $e) {
            debugging('tool_quizproctoring: closing the proctoring session failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            try {
                close_session::queue($attemptid);
            } catch (\Throwable $queueerror) {
                debugging('tool_quizproctoring: could not queue a retry: ' . $queueerror->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    /**
     * A quiz attempt was deleted: forget its session ledger row.
     *
     * @param \mod_quiz\event\attempt_deleted $event
     */
    public static function attempt_deleted(\mod_quiz\event\attempt_deleted $event): void {
        (new session_repository())->delete_for_attempt((int) $event->objectid);
    }

    /**
     * A course was deleted: forget the Key IDs of its quizzes and its session ledger rows.
     *
     * @param \core\event\course_deleted $event
     */
    public static function course_deleted(\core\event\course_deleted $event): void {
        (new quiz_key_repository())->delete_for_course((int) $event->objectid);
        (new session_repository())->delete_for_course((int) $event->objectid);
    }

    /**
     * An activity was deleted: if it was a quiz, forget its Key ID.
     *
     * @param \core\event\course_module_deleted $event
     */
    public static function course_module_deleted(\core\event\course_module_deleted $event): void {
        if (($event->other['modulename'] ?? '') === 'quiz') {
            (new quiz_key_repository())->delete_for_cm((int) $event->objectid);
        }
    }
}
