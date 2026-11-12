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

namespace tool_courseproctoring\local;

/**
 * Decides whether a page of a course should be proctored for the person looking at it.
 *
 * Pure decision logic with no output and no vendor knowledge, so every branch is unit tested. It looks ONLY at this
 * plugin's own settings (the integration switch, the App Key, the Key ID entered in the course's settings, this plugin's
 * session ledger), at Moodle's own facts (the page, the person, enrolment, course completion) and at nothing else. It
 * deliberately knows nothing about any other proctoring set-up on the site.
 *
 * The checks run cheapest first, and stop at the first "no":
 *  1. the integration switch is on (no database read);
 *  2. the page is inside a course (not the site home), with the layout of a course or an activity, and is not a page of
 *     a quiz activity (no database read);
 *  3. the person is a real logged-in user: not a visitor or guest, not "logged in as" somebody else, not role-switched,
 *     not a site administrator (no database read);
 *  4. the course has a Proctor Key (one indexed read);
 *  5. an App Key is saved and can be read (decrypting is the costliest step, so it comes after the key lookup, which
 *     is the check that is "no" on nearly every page of a site);
 *  6. the person is actively enrolled in the course and holds the permission tool/courseproctoring:beproctored;
 *  7. the session ledger: a finished session (closed or failed) is never restarted;
 *  8. the person has not completed the course (a session that is still open then waits to be closed).
 *
 * @package    tool_courseproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_gate {
    /** @var string Proctored: a new session is being started with the course's Key ID. */
    public const PROCTORED = 'proctored';
    /** @var string Proctored: an active session of this plugin is being continued. */
    public const CONTINUING = 'continuing_session';
    /** @var string Not proctored: the vendor integration switch is off. */
    public const VENDOR_DISABLED = 'vendor_disabled';
    /** @var string Not proctored: not a page of a course (the site home, the dashboard, ...), or not a learning layout. */
    public const NOT_A_COURSE_PAGE = 'not_a_course_page';
    /** @var string Not proctored: a page of a quiz activity (D5: this plugin never runs there). */
    public const QUIZ_PAGE = 'quiz_page';
    /** @var string Not proctored: nobody is logged in, or it is a guest. */
    public const NOT_LOGGED_IN = 'not_logged_in';
    /** @var string Not proctored: an administrator is logged in as somebody else. */
    public const LOGGED_IN_AS = 'logged_in_as';
    /** @var string Not proctored: the person switched their role in this course. */
    public const ROLE_SWITCHED = 'role_switched';
    /** @var string Not proctored: a site administrator. */
    public const SITE_ADMIN = 'site_admin';
    /** @var string Not proctored: no Proctor Key is entered in the course's settings. */
    public const NO_COURSE_KEY = 'no_course_key';
    /** @var string Not proctored: no App Key saved, or it cannot be read. */
    public const NO_APP_KEY = 'no_app_key';
    /** @var string Not proctored: not an actively enrolled person with the permission to be proctored. */
    public const NOT_A_LEARNER = 'not_a_learner';
    /** @var string Not proctored: this plugin's session has already been closed or failed. */
    public const SESSION_ENDED = 'session_already_ended';
    /** @var string Not proctored: the learner has completed the course. */
    public const COURSE_COMPLETE = 'course_complete';
    /** @var string Not proctored: the stored session id is not one this plugin made. */
    public const SESSION_ID_INVALID = 'session_id_invalid';

    /** @var string[] Page layouts of a course page and of the pages of its activities and resources. */
    private const LAYOUTS = ['course', 'incourse'];

    /** @var course_key_repository */
    private course_key_repository $keys;
    /** @var session_repository */
    private session_repository $sessions;

    /**
     * Constructor.
     *
     * @param course_key_repository|null $keys
     * @param session_repository|null $sessions
     */
    public function __construct(?course_key_repository $keys = null, ?session_repository $sessions = null) {
        $this->keys = $keys ?? new course_key_repository();
        $this->sessions = $sessions ?? new session_repository();
    }

    /**
     * Decide whether the page described by the facts should be proctored.
     *
     * @param course_facts $facts
     * @return decision
     */
    public function evaluate(course_facts $facts): decision {
        if (!config::is_vendor_enabled()) {
            return decision::no(self::VENDOR_DISABLED);
        }

        if ($facts->courseid <= 0 || $facts->courseid === (int) SITEID || !in_array($facts->layout, self::LAYOUTS, true)) {
            return decision::no(self::NOT_A_COURSE_PAGE);
        }
        // Never on a quiz activity: its own pages, and the list of a course's quizzes.
        if ($facts->activitytype === 'quiz' || str_starts_with($facts->pagetype, 'mod-quiz-')) {
            return decision::no(self::QUIZ_PAGE);
        }

        if (!$facts->loggedin) {
            return decision::no(self::NOT_LOGGED_IN);
        }
        if ($facts->loggedinas) {
            return decision::no(self::LOGGED_IN_AS);
        }
        if ($facts->roleswitched) {
            return decision::no(self::ROLE_SWITCHED);
        }
        if ($facts->siteadmin) {
            return decision::no(self::SITE_ADMIN);
        }

        // The Key ID entered in the course's settings is the only one ever used: no Key ID there, no proctoring.
        $coursekey = $this->keys->get($facts->courseid);
        if ($coursekey === '') {
            return decision::no(self::NO_COURSE_KEY);
        }
        // A key that is stored but cannot be decrypted is as good as none: never send the widget an empty App Key.
        if (config::get_app_key() === '') {
            return decision::no(self::NO_APP_KEY);
        }

        $context = \context_course::instance($facts->courseid, IGNORE_MISSING);
        if (!$context || !is_enrolled($context, $facts->userid, 'tool/courseproctoring:beproctored', true)) {
            return decision::no(self::NOT_A_LEARNER);
        }

        // A session this plugin already started keeps the Key ID it started with, even if the course's Key ID is changed
        // while the learner is studying. A finished session is never restarted.
        $session = $this->sessions->get($facts->userid, $facts->courseid);
        if ($session && (int) $session->status !== session_repository::STATUS_ACTIVE) {
            return decision::no(self::SESSION_ENDED);
        }

        if ($this->has_completed($facts)) {
            return decision::no(self::COURSE_COMPLETE);
        }

        if ($session) {
            if (!session_id::is_valid((string) $session->sessionid)) {
                return decision::no(self::SESSION_ID_INVALID);
            }
            return decision::yes((string) $session->keyid, self::CONTINUING);
        }
        return decision::yes($coursekey, self::PROCTORED);
    }

    /**
     * Whether proctoring is over for a person in a course: the session this plugin started for them has ended (closed,
     * or given up on), or they have completed the course.
     *
     * Asked by the page script of a learner whose widget is running (see the external function
     * {@see \tool_courseproctoring\external\is_proctoring_over}), right after they mark an activity as done, to know
     * whether to stop the widget. It answers from the same two facts that {@see self::evaluate()} uses for its rules 7 and 8,
     * so a page that would no longer start the widget is a page whose running widget is stopped.
     *
     * @param int $userid
     * @param int $courseid
     * @return bool
     */
    public function is_over(int $userid, int $courseid): bool {
        $session = $this->sessions->get($userid, $courseid);
        if ($session && (int) $session->status !== session_repository::STATUS_ACTIVE) {
            return true;
        }
        return $this->is_course_complete($userid, $courseid);
    }

    /**
     * Whether the person has completed the course. Always false in a course without completion tracking.
     *
     * @param course_facts $facts
     * @return bool
     */
    private function has_completed(course_facts $facts): bool {
        return $this->is_course_complete($facts->userid, $facts->courseid);
    }

    /**
     * Whether a person has completed a course. Always false in a course without completion tracking.
     *
     * @param int $userid
     * @param int $courseid
     * @return bool
     */
    private function is_course_complete(int $userid, int $courseid): bool {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        $completion = new \completion_info(get_course($courseid));
        return $completion->is_enabled() && $completion->is_course_complete($userid);
    }
}
