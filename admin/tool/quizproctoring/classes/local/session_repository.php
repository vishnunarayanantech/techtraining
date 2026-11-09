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

/**
 * The ledger of proctoring sessions started by this plugin, one row per quiz attempt.
 *
 * Only attempts with a row here are ever closed by this plugin. mod_quiz fires its attempt events for EVERY
 * attempt, so this is how the plugin avoids closing sessions it never started (a course without a Key ID,
 * a preview, the integration switched off, ...). The row
 * also remembers the Key ID the session started with, so the close call uses the same Key ID
 * even if an administrator changes the course's Key ID mid-attempt.
 *
 * @package    tool_quizproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class session_repository {
    /** @var string Table name. */
    private const TABLE = 'tool_quizproctoring_session';

    /** @var int Session started and not yet closed. */
    public const STATUS_ACTIVE = 0;
    /** @var int The vendor accepted the close request. */
    public const STATUS_CLOSED = 1;
    /** @var int Closing was abandoned (permanent error, retries exhausted, or no App Key). */
    public const STATUS_FAILED = 2;

    /**
     * The ledger row for an attempt, if this plugin started a session for it.
     *
     * @param int $attemptid
     * @return \stdClass|null
     */
    public function get_by_attempt(int $attemptid): ?\stdClass {
        global $DB;
        $record = $DB->get_record(self::TABLE, ['attemptid' => $attemptid]);
        return $record ?: null;
    }

    /**
     * Record that a session has been started for an attempt. Idempotent.
     *
     * Safe to call on every question page and from concurrent requests: the first call inserts the
     * row, every later call returns that same row unchanged (in particular the Key ID never changes).
     *
     * @param attempt_facts $facts
     * @param string $keyid Key ID to start the session with (ignored if a row already exists).
     * @return \stdClass The ledger row.
     */
    public function ensure_started(attempt_facts $facts, string $keyid): \stdClass {
        global $DB;

        $existing = $this->get_by_attempt($facts->attemptid);
        if ($existing) {
            return $existing;
        }

        $now = time();
        try {
            $DB->insert_record(self::TABLE, (object) [
                'attemptid' => $facts->attemptid,
                'userid' => $facts->userid,
                'courseid' => $facts->courseid,
                'cmid' => $facts->cmid,
                'quizid' => $facts->quizid,
                'keyid' => $keyid,
                'status' => self::STATUS_ACTIVE,
                'closeattempts' => 0,
                'lasterror' => null,
                'timecreated' => $now,
                'timemodified' => $now,
                'timeclosed' => null,
            ]);
        } catch (\dml_write_exception $e) {
            // A concurrent request inserted the row between our read and write (unique attemptid).
            $existing = $this->get_by_attempt($facts->attemptid);
            if (!$existing) {
                throw $e;
            }
            return $existing;
        }
        return $this->get_by_attempt($facts->attemptid);
    }

    /**
     * Mark an active session as closed.
     *
     * @param int $attemptid
     * @return bool True if the row was active and is now closed.
     */
    public function mark_closed(int $attemptid): bool {
        return $this->finish($attemptid, self::STATUS_CLOSED, null);
    }

    /**
     * Mark an active session as failed (it will not be retried).
     *
     * @param int $attemptid
     * @param string $reason Short description; must not contain keys or other secrets.
     * @return bool True if the row was active and is now failed.
     */
    public function mark_failed(int $attemptid, string $reason): bool {
        return $this->finish($attemptid, self::STATUS_FAILED, $reason);
    }

    /**
     * Record one failed (retryable) close attempt on an active session.
     *
     * @param int $attemptid
     * @param string $error Short description; must not contain keys or other secrets.
     */
    public function record_close_error(int $attemptid, string $error): void {
        global $DB;
        $record = $this->get_by_attempt($attemptid);
        if (!$record || (int) $record->status !== self::STATUS_ACTIVE) {
            return;
        }
        $record->closeattempts = (int) $record->closeattempts + 1;
        $record->lasterror = \core_text::substr($error, 0, 255);
        $record->timemodified = time();
        $DB->update_record(self::TABLE, $record);
    }

    /**
     * Delete the ledger row of one attempt.
     *
     * @param int $attemptid
     */
    public function delete_for_attempt(int $attemptid): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['attemptid' => $attemptid]);
    }

    /**
     * Delete all ledger rows of a course.
     *
     * @param int $courseid
     */
    public function delete_for_course(int $courseid): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['courseid' => $courseid]);
    }

    /**
     * Move an active row to a final status.
     *
     * @param int $attemptid
     * @param int $status
     * @param string|null $reason
     * @return bool
     */
    private function finish(int $attemptid, int $status, ?string $reason): bool {
        global $DB;
        $record = $this->get_by_attempt($attemptid);
        if (!$record || (int) $record->status !== self::STATUS_ACTIVE) {
            return false;
        }
        $now = time();
        $record->status = $status;
        $record->timemodified = $now;
        $record->timeclosed = $status === self::STATUS_CLOSED ? $now : null;
        $record->lasterror = $reason === null ? null : \core_text::substr($reason, 0, 255);
        $DB->update_record(self::TABLE, $record);
        return true;
    }
}
