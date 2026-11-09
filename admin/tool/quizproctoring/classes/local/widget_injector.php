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

namespace tool_quizproctoring\local;

use tool_quizproctoring\local\vendor\vendor_client;
use tool_quizproctoring\local\vendor\vendor_factory;
use tool_quizproctoring\local\vendor\widget_context;

/**
 * Starts proctoring on a quiz attempt page: decides, records the session, and queues the widget JavaScript.
 *
 * Idempotent: the quiz attempt page is rendered once per question page and again on every reload, and each
 * call after the first reuses the same ledger row (and Key ID).
 *
 * @package    tool_quizproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class widget_injector {
    /** @var attempt_gate */
    private attempt_gate $gate;
    /** @var session_repository */
    private session_repository $sessions;
    /** @var vendor_client */
    private vendor_client $vendor;

    /**
     * Constructor.
     *
     * @param attempt_gate|null $gate
     * @param session_repository|null $sessions
     * @param vendor_client|null $vendor
     */
    public function __construct(
        ?attempt_gate $gate = null,
        ?session_repository $sessions = null,
        ?vendor_client $vendor = null,
    ) {
        $this->gate = $gate ?? new attempt_gate();
        $this->sessions = $sessions ?? new session_repository();
        $this->vendor = $vendor ?? vendor_factory::get();
    }

    /**
     * Proctor the attempt on this page if the rules say so.
     *
     * @param int $attemptid
     * @param int $viewerid The logged-in user.
     * @param \moodle_page $page The page to add the widget JavaScript to.
     * @return decision What was decided, and why.
     */
    public function inject(int $attemptid, int $viewerid, \moodle_page $page): decision {
        $facts = attempt_facts::from_database($attemptid);
        if ($facts === null) {
            return decision::no(attempt_gate::ATTEMPT_NOT_FOUND);
        }

        $decision = $this->gate->evaluate($facts, $viewerid);
        if (!$decision->proctored) {
            return $decision;
        }

        // Record the session first: if this fails, no widget is shown that we could never close.
        $session = $this->sessions->ensure_started($facts, $decision->keyid);

        $user = \core_user::get_user($viewerid, 'id, firstname, lastname, email, username', MUST_EXIST);
        $context = new widget_context(
            (string) $session->keyid,
            (string) $facts->attemptid,
            (string) $facts->quizid,
            format_string($facts->coursefullname, true, ['context' => \context_course::instance($facts->courseid)]),
            (string) $user->id,
            (string) $user->firstname,
            (string) $user->lastname,
            (string) $user->email,
            (string) $user->username,
            (new \moodle_url('/'))->out(false),
        );

        $url = $this->vendor->get_widget_script_url(config::get_app_key(), $context);
        $page->requires->js_call_amd('tool_quizproctoring/proctor_widget', 'init', [$url]);

        return $decision;
    }
}
