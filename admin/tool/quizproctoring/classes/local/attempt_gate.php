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

use mod_quiz\quiz_attempt;

/**
 * Decides whether a quiz attempt page should be proctored.
 *
 * Pure decision logic with no output and no vendor knowledge, so every branch is unit tested. It looks ONLY at this
 * plugin's own settings (the integration switch, the App Key, the Key ID entered in the quiz's settings, this plugin's
 * session ledger) and at the attempt itself. It deliberately knows nothing about any other proctoring set-up on the site:
 * what other code, tables or core-file edits exist must never change the decision.
 *
 * Checks run cheapest first. There is intentionally NO option to proctor teacher previews:
 * mod_quiz does not fire attempt_submitted for previews (quiz_attempt::process_finish()), so a
 * session started for a preview would never be closed.
 *
 * @package    tool_quizproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attempt_gate {
    /** @var string Proctored: a new session is being started with the quiz's Key ID. */
    public const PROCTORED = 'proctored';
    /** @var string Proctored: an active session of this plugin is being continued. */
    public const CONTINUING = 'continuing_session';
    /** @var string Not proctored: the attempt does not exist. */
    public const ATTEMPT_NOT_FOUND = 'attempt_not_found';
    /** @var string Not proctored: the vendor integration switch is off. */
    public const VENDOR_DISABLED = 'vendor_disabled';
    /** @var string Not proctored: the attempt belongs to somebody else. */
    public const NOT_OWNER = 'not_owner';
    /** @var string Not proctored: teacher preview attempt. */
    public const PREVIEW = 'preview';
    /** @var string Not proctored: the attempt is not in progress. */
    public const NOT_IN_PROGRESS = 'not_in_progress';
    /** @var string Not proctored: no App Key saved. */
    public const NO_APP_KEY = 'no_app_key';
    /** @var string Not proctored: no Key ID is entered in the quiz's settings. */
    public const NO_QUIZ_KEY = 'no_quiz_key';
    /** @var string Not proctored: this plugin's session for the attempt has already been closed or failed. */
    public const SESSION_ENDED = 'session_already_ended';

    /** @var quiz_key_repository */
    private quiz_key_repository $keys;
    /** @var session_repository */
    private session_repository $sessions;

    /**
     * Constructor.
     *
     * @param quiz_key_repository|null $keys
     * @param session_repository|null $sessions
     */
    public function __construct(?quiz_key_repository $keys = null, ?session_repository $sessions = null) {
        $this->keys = $keys ?? new quiz_key_repository();
        $this->sessions = $sessions ?? new session_repository();
    }

    /**
     * Decide whether the given attempt should be proctored for the given viewer.
     *
     * @param attempt_facts $facts
     * @param int $viewerid The logged-in user requesting the page.
     * @return decision
     */
    public function evaluate(attempt_facts $facts, int $viewerid): decision {
        if (!config::is_vendor_enabled()) {
            return decision::no(self::VENDOR_DISABLED);
        }
        if ($facts->userid !== $viewerid) {
            return decision::no(self::NOT_OWNER);
        }
        if ($facts->preview) {
            return decision::no(self::PREVIEW);
        }
        if ($facts->state !== quiz_attempt::IN_PROGRESS) {
            return decision::no(self::NOT_IN_PROGRESS);
        }
        // A key that is stored but cannot be decrypted is as good as none: never send the widget an empty App Key.
        if (config::get_app_key() === '') {
            return decision::no(self::NO_APP_KEY);
        }

        // The Key ID entered in the quiz's settings is the only one ever used: no Key ID there, no proctoring.
        $quizkey = $this->keys->get($facts->cmid);
        if ($quizkey === '') {
            return decision::no(self::NO_QUIZ_KEY);
        }

        // A session this plugin already started keeps the Key ID it started with, even if the quiz's Key ID is changed
        // while the attempt is under way.
        $session = $this->sessions->get_by_attempt($facts->attemptid);
        $keyid = $session ? (string) $session->keyid : $quizkey;
        if ($keyid === '') {
            return decision::no(self::NO_QUIZ_KEY);
        }

        if ($session && (int) $session->status !== session_repository::STATUS_ACTIVE) {
            return decision::no(self::SESSION_ENDED);
        }

        return decision::yes($keyid, $session ? self::CONTINUING : self::PROCTORED);
    }
}
