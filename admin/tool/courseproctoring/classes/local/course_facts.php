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
 * The facts about the page being shown and the person looking at it that the proctoring decision needs.
 *
 * Collected without any database read, so the footer hook, which runs on every page, stays cheap. Everything that needs
 * the database (the course's Key ID, enrolment, the session ledger, course completion) is looked at later, by the gate,
 * and only for pages that passed the cheap checks.
 *
 * @package    tool_courseproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class course_facts {
    /**
     * Constructor.
     *
     * @param int $courseid The course of the page (the site course for pages outside a course).
     * @param string $layout The page layout, for example "course" or "incourse".
     * @param string $pagetype The page type, for example "mod-page-view".
     * @param string $activitytype The activity type of the page, or an empty string if it is not an activity page.
     * @param int $userid The person looking at the page.
     * @param bool $loggedin Whether that person is logged in as a real user (not a visitor, not a guest).
     * @param bool $loggedinas Whether an administrator is looking at the site "logged in as" that person.
     * @param bool $roleswitched Whether the person switched their role in this course.
     * @param bool $siteadmin Whether the person is a site administrator.
     */
    public function __construct(
        /** @var int The course of the page. */
        public readonly int $courseid,
        /** @var string The page layout. */
        public readonly string $layout,
        /** @var string The page type. */
        public readonly string $pagetype,
        /** @var string The activity type of the page, or empty. */
        public readonly string $activitytype,
        /** @var int The person looking at the page. */
        public readonly int $userid,
        /** @var bool Logged in as a real user. */
        public readonly bool $loggedin,
        /** @var bool "Logged in as" another person. */
        public readonly bool $loggedinas,
        /** @var bool Role switched in this course. */
        public readonly bool $roleswitched,
        /** @var bool A site administrator. */
        public readonly bool $siteadmin,
    ) {
    }

    /**
     * Collect the facts for the page that is being shown to the current user.
     *
     * @param \moodle_page $page
     * @return self
     */
    public static function from_page(\moodle_page $page): self {
        global $USER;

        $courseid = (int) $page->course->id;
        $cm = $page->cm;
        $loggedin = isloggedin() && !isguestuser();
        $userid = $loggedin ? (int) $USER->id : 0;

        return new self(
            $courseid,
            (string) $page->pagelayout,
            (string) $page->pagetype,
            $cm ? (string) $cm->modname : '',
            $userid,
            $loggedin,
            $loggedin && \core\session\manager::is_loggedinas(),
            $loggedin && $courseid > 0 && is_role_switched($courseid),
            $loggedin && is_siteadmin($userid),
        );
    }
}
