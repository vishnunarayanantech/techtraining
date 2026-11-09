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

use tool_quizproctoring\local\vendor\close_result;
use tool_quizproctoring\local\vendor\vendor_client;
use tool_quizproctoring\local\vendor\vendor_factory;

/**
 * Closes the vendor session of a finished quiz attempt.
 *
 * Used by the post-commit observer (first, synchronous try, with bounded timeouts) and by the adhoc
 * retry task. It only ever closes sessions that have an ACTIVE row in the session ledger, serialises
 * concurrent callers with a lock, and records every outcome in the ledger, so calling it again for
 * the same attempt after success is a no-op.
 *
 * @package    tool_quizproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class close_service {
    /** @var string There is nothing to do: no active ledger row for the attempt. */
    public const IGNORED = 'ignored';
    /** @var string The vendor session was closed. */
    public const CLOSED = 'closed';
    /** @var string The attempt failed in a retryable way; try again later. */
    public const RETRY = 'retry';
    /** @var string Closing was given up on; the ledger row is marked failed. */
    public const FAILED = 'failed';

    /** @var int Default seconds to wait for the per-attempt lock. */
    private const LOCK_TIMEOUT = 10;

    /** @var session_repository */
    private session_repository $sessions;
    /** @var vendor_client */
    private vendor_client $vendor;
    /** @var int */
    private int $locktimeout;
    /** @var \core\lock\lock_factory */
    private \core\lock\lock_factory $lockfactory;

    /**
     * Constructor.
     *
     * @param session_repository|null $sessions
     * @param vendor_client|null $vendor
     * @param int $locktimeout Seconds to wait for the per-attempt lock.
     * @param \core\lock\lock_factory|null $lockfactory Lock factory (tests).
     */
    public function __construct(
        ?session_repository $sessions = null,
        ?vendor_client $vendor = null,
        int $locktimeout = self::LOCK_TIMEOUT,
        ?\core\lock\lock_factory $lockfactory = null,
    ) {
        $this->sessions = $sessions ?? new session_repository();
        $this->vendor = $vendor ?? vendor_factory::get();
        $this->locktimeout = $locktimeout;
        $this->lockfactory = $lockfactory ?? \core\lock\lock_config::get_lock_factory('tool_quizproctoring');
    }

    /**
     * Try to close the vendor session of an attempt.
     *
     * The integration switch is deliberately NOT checked here: sessions that were started must still be
     * closed after an administrator switches the integration off.
     *
     * @param int $attemptid
     * @param bool $lastchance If true, a retryable failure is recorded as final instead of asking for a retry.
     * @return string One of the IGNORED / CLOSED / RETRY / FAILED constants.
     */
    public function close_attempt(int $attemptid, bool $lastchance = false): string {
        $lock = $this->lockfactory->get_lock('close_' . $attemptid, $this->locktimeout);
        if (!$lock) {
            return $lastchance ? $this->give_up($attemptid, 'could not obtain lock') : self::RETRY;
        }

        try {
            $session = $this->sessions->get_by_attempt($attemptid);
            if (!$session || (int) $session->status !== session_repository::STATUS_ACTIVE) {
                return self::IGNORED;
            }

            $appkey = config::get_app_key();
            if ($appkey === '') {
                $this->sessions->mark_failed($attemptid, 'App Key is not configured');
                return self::FAILED;
            }

            $result = $this->vendor->close_session($appkey, (string) $session->keyid, $attemptid);

            switch ($result->outcome) {
                case close_result::SUCCESS:
                    $this->sessions->mark_closed($attemptid);
                    return self::CLOSED;

                case close_result::PERMANENT:
                    $this->sessions->mark_failed($attemptid, $result->detail);
                    return self::FAILED;

                default:
                    if ($lastchance) {
                        return $this->give_up($attemptid, $result->detail);
                    }
                    $this->sessions->record_close_error($attemptid, $result->detail);
                    return self::RETRY;
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Stop retrying: mark the ledger row failed.
     *
     * @param int $attemptid
     * @param string $detail
     * @return string FAILED
     */
    private function give_up(int $attemptid, string $detail): string {
        $this->sessions->mark_failed($attemptid, 'gave up retrying: ' . $detail);
        return self::FAILED;
    }
}
