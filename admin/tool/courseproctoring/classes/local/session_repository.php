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
 * The ledger of proctoring sessions started by this plugin: one row per learner and course.
 *
 * Only sessions with a row here are ever closed by this plugin. The row holds the vendor session id (made once, here,
 * and never changed) and the Key ID the session started with, so starting and closing keep working even if the
 * course's Key ID is changed or removed while the learner is studying.
 *
 * @package    tool_courseproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class session_repository {
    /** @var string Table name. */
    private const TABLE = 'tool_courseproctoring_session';

    /** @var int Session started and not yet closed. */
    public const STATUS_ACTIVE = 0;
    /** @var int The vendor accepted the close request. */
    public const STATUS_CLOSED = 1;
    /** @var int Closing was abandoned (permanent error, retries exhausted, or no App Key). */
    public const STATUS_FAILED = 2;

    /** @var int How many times a new id is drawn if the database says the id is already in use. */
    private const ID_TRIES = 3;

    /** @var callable Makes a session id (replaced in tests). */
    private $idgenerator;

    /**
     * Constructor.
     *
     * @param callable|null $idgenerator Replacement for {@see session_id::generate()} (tests).
     */
    public function __construct(?callable $idgenerator = null) {
        $this->idgenerator = $idgenerator ?? [session_id::class, 'generate'];
    }

    /**
     * The ledger row of a learner in a course, if this plugin started a session for them.
     *
     * @param int $userid
     * @param int $courseid
     * @return \stdClass|null
     */
    public function get(int $userid, int $courseid): ?\stdClass {
        global $DB;
        $record = $DB->get_record(self::TABLE, ['userid' => $userid, 'courseid' => $courseid]);
        return $record ?: null;
    }

    /**
     * A ledger row by its own id.
     *
     * @param int $id
     * @return \stdClass|null
     */
    public function get_by_id(int $id): ?\stdClass {
        global $DB;
        $record = $DB->get_record(self::TABLE, ['id' => $id]);
        return $record ?: null;
    }

    /**
     * The sessions of a learner that are still active.
     *
     * @param int $userid
     * @param int $courseid Only this course; 0 for every course.
     * @return \stdClass[] Ledger rows, by id.
     */
    public function get_active_for_user(int $userid, int $courseid = 0): array {
        global $DB;
        $conditions = ['userid' => $userid, 'status' => self::STATUS_ACTIVE];
        if ($courseid > 0) {
            $conditions['courseid'] = $courseid;
        }
        return $DB->get_records(self::TABLE, $conditions, 'id ASC');
    }

    /**
     * Record that a session has been started for a learner in a course, and make its session id. Idempotent.
     *
     * Safe to call on every page and from concurrent requests: the first call inserts the row (and makes the id), every
     * later call returns that same row unchanged. In particular the session id and the Key ID never change. If two
     * pages start at the same moment, the database's unique key on learner + course lets one row win and both calls
     * return it.
     *
     * @param int $userid
     * @param int $courseid
     * @param string $keyid Key ID to start the session with (ignored if a row already exists).
     * @return \stdClass The ledger row.
     */
    public function ensure_started(int $userid, int $courseid, string $keyid): \stdClass {
        global $DB;

        $existing = $this->get($userid, $courseid);
        if ($existing) {
            return $existing;
        }

        $now = time();
        for ($try = 1; $try <= self::ID_TRIES; $try++) {
            try {
                $DB->insert_record(self::TABLE, (object) [
                    'userid' => $userid,
                    'courseid' => $courseid,
                    'keyid' => $keyid,
                    'sessionid' => ($this->idgenerator)(),
                    'status' => self::STATUS_ACTIVE,
                    'closeattempts' => 0,
                    'lasterror' => null,
                    'timecreated' => $now,
                    'timemodified' => $now,
                    'timeclosed' => null,
                ]);
            } catch (\dml_write_exception $e) {
                // Either a concurrent request inserted the row for this learner and course, or (practically impossible)
                // the random id is already in use. In the second case a new id is drawn.
                $existing = $this->get($userid, $courseid);
                if ($existing) {
                    return $existing;
                }
                if ($try === self::ID_TRIES) {
                    throw $e;
                }
                continue;
            }
            return $this->get($userid, $courseid);
        }
        throw new \coding_exception('The session could not be recorded');
    }

    /**
     * Mark an active session as closed.
     *
     * @param int $id Ledger row id.
     * @return bool True if the row was active and is now closed.
     */
    public function mark_closed(int $id): bool {
        return $this->finish($id, self::STATUS_CLOSED, null);
    }

    /**
     * Mark an active session as failed (it will not be retried).
     *
     * @param int $id Ledger row id.
     * @param string $reason Short description; must not contain keys or other secrets.
     * @return bool True if the row was active and is now failed.
     */
    public function mark_failed(int $id, string $reason): bool {
        return $this->finish($id, self::STATUS_FAILED, $reason);
    }

    /**
     * Record one failed (retryable) close attempt on an active session.
     *
     * @param int $id Ledger row id.
     * @param string $error Short description; must not contain keys or other secrets.
     */
    public function record_close_error(int $id, string $error): void {
        global $DB;
        $record = $this->get_by_id($id);
        if (!$record || (int) $record->status !== self::STATUS_ACTIVE) {
            return;
        }
        $record->closeattempts = (int) $record->closeattempts + 1;
        $record->lasterror = \core_text::substr($error, 0, 255);
        $record->timemodified = time();
        $DB->update_record(self::TABLE, $record);
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
     * Delete all ledger rows of a user.
     *
     * @param int $userid
     */
    public function delete_for_user(int $userid): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['userid' => $userid]);
    }

    /**
     * Move an active row to a final status.
     *
     * @param int $id
     * @param int $status
     * @param string|null $reason
     * @return bool
     */
    private function finish(int $id, int $status, ?string $reason): bool {
        global $DB;
        $record = $this->get_by_id($id);
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
