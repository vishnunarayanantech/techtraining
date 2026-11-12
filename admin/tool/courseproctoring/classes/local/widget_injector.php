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

use tool_courseproctoring\local\vendor\vendor_client;
use tool_courseproctoring\local\vendor\vendor_factory;
use tool_courseproctoring\local\vendor\widget_context;

/**
 * Starts proctoring on a page of a course: decides, records the session, and queues the widget JavaScript.
 *
 * Idempotent: every page of the course calls it, and every call after the first reuses the same ledger row, so the
 * learner keeps the same session id (and Key ID) on every page, tab and visit.
 *
 * @package    tool_courseproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class widget_injector {
    /** @var course_gate */
    private course_gate $gate;
    /** @var session_repository */
    private session_repository $sessions;
    /** @var vendor_client */
    private vendor_client $vendor;

    /**
     * Constructor.
     *
     * @param course_gate|null $gate
     * @param session_repository|null $sessions
     * @param vendor_client|null $vendor
     */
    public function __construct(
        ?course_gate $gate = null,
        ?session_repository $sessions = null,
        ?vendor_client $vendor = null,
    ) {
        $this->gate = $gate ?? new course_gate();
        $this->sessions = $sessions ?? new session_repository();
        $this->vendor = $vendor ?? vendor_factory::get();
    }

    /**
     * Proctor the current user on this page if the rules say so.
     *
     * @param \moodle_page $page The page to add the widget JavaScript to.
     * @return decision What was decided, and why.
     */
    public function inject(\moodle_page $page): decision {
        $facts = course_facts::from_page($page);

        $decision = $this->gate->evaluate($facts);
        if (!$decision->proctored) {
            return $decision;
        }

        // Record the session first: if this fails, no widget is shown that we could never close.
        $session = $this->sessions->ensure_started($facts->userid, $facts->courseid, $decision->keyid);
        if (!session_id::is_valid((string) $session->sessionid)) {
            return decision::no(course_gate::SESSION_ID_INVALID);
        }

        $user = \core_user::get_user($facts->userid, 'id, firstname, lastname, email, username', MUST_EXIST);
        $course = get_course($facts->courseid);
        $context = new widget_context(
            (string) $session->keyid,
            (string) $session->sessionid,
            (string) $course->id,
            format_string($course->fullname, true, ['context' => \context_course::instance($course->id)]),
            (string) $user->id,
            (string) $user->firstname,
            (string) $user->lastname,
            (string) $user->email,
            (string) $user->username,
            (new \moodle_url('/'))->out(false),
        );

        $url = $this->vendor->get_widget_script_url(config::get_app_key(), $context);
        $page->requires->js_call_amd('tool_courseproctoring/proctor_widget', 'init', [$url]);

        return $decision;
    }
}
