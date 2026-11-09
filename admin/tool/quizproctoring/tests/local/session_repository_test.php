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
 * Tests for the session ledger.
 *
 * @package    tool_quizproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_quizproctoring\local\session_repository
 */
final class session_repository_test extends \advanced_testcase {
    /**
     * Facts for a made-up attempt.
     *
     * @param int $attemptid
     * @return attempt_facts
     */
    private function facts(int $attemptid = 501): attempt_facts {
        return new attempt_facts($attemptid, 7, 3, 11, 5, 'inprogress', false, 'Course');
    }

    /**
     * Starting a session twice (two question pages, a reload) gives the same single row.
     */
    public function test_ensure_started_is_idempotent(): void {
        global $DB;
        $this->resetAfterTest();
        $repo = new session_repository();

        $first = $repo->ensure_started($this->facts(), 'KEY-FIRST');
        $second = $repo->ensure_started($this->facts(), 'KEY-SECOND');
        $third = $repo->ensure_started($this->facts(), 'KEY-THIRD');

        $this->assertEquals(1, $DB->count_records('tool_quizproctoring_session'));
        $this->assertEquals($first->id, $second->id);
        $this->assertEquals($first->id, $third->id);
        $this->assertSame('KEY-FIRST', $third->keyid, 'the Key ID the session started with never changes');
        $this->assertEquals(session_repository::STATUS_ACTIVE, $third->status);
        $this->assertEquals(501, $third->attemptid);
        $this->assertEquals(7, $third->userid);
        $this->assertEquals(3, $third->courseid);
        $this->assertEquals(11, $third->cmid);
        $this->assertEquals(5, $third->quizid);
        $this->assertNull($third->timeclosed);
    }

    /**
     * Two concurrent requests: the loser of the insert race gets the winner's row instead of an error.
     */
    public function test_insert_race_returns_the_existing_row(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();

        (new session_repository())->ensure_started($this->facts(), 'KEY-WINNER');

        // A repository whose first read still sees "no row", as the slower request would.
        $slow = new class extends session_repository {
            /** @var int Number of reads. */
            private int $reads = 0;

            #[\Override]
            public function get_by_attempt(int $attemptid): ?\stdClass {
                return ++$this->reads === 1 ? null : parent::get_by_attempt($attemptid);
            }
        };
        $row = $slow->ensure_started($this->facts(), 'KEY-LOSER');

        $this->assertSame('KEY-WINNER', $row->keyid);
        $this->assertEquals(1, $DB->count_records('tool_quizproctoring_session'));
    }

    /**
     * Only an active session can be closed or failed, and only once.
     */
    public function test_state_transitions(): void {
        $this->resetAfterTest();
        $repo = new session_repository();
        $repo->ensure_started($this->facts(1), 'KEY');
        $repo->ensure_started($this->facts(2), 'KEY');

        $this->assertTrue($repo->mark_closed(1));
        $row = $repo->get_by_attempt(1);
        $this->assertEquals(session_repository::STATUS_CLOSED, $row->status);
        $this->assertNotNull($row->timeclosed);
        $this->assertNull($row->lasterror);
        $this->assertFalse($repo->mark_closed(1), 'already closed');
        $this->assertFalse($repo->mark_failed(1, 'late failure'), 'a closed session is final');
        $this->assertEquals(session_repository::STATUS_CLOSED, $repo->get_by_attempt(1)->status);

        $this->assertTrue($repo->mark_failed(2, 'HTTP 400'));
        $row = $repo->get_by_attempt(2);
        $this->assertEquals(session_repository::STATUS_FAILED, $row->status);
        $this->assertSame('HTTP 400', $row->lasterror);
        $this->assertNull($row->timeclosed);
        $this->assertFalse($repo->mark_closed(2), 'a failed session is final');

        $this->assertFalse($repo->mark_closed(999), 'unknown attempt');
    }

    /**
     * Retryable failures are counted on active sessions only, and long messages are cut to the column size.
     */
    public function test_record_close_error(): void {
        $this->resetAfterTest();
        $repo = new session_repository();
        $repo->ensure_started($this->facts(1), 'KEY');

        $repo->record_close_error(1, 'HTTP 503');
        $repo->record_close_error(1, str_repeat('x', 400));
        $row = $repo->get_by_attempt(1);
        $this->assertEquals(2, $row->closeattempts);
        $this->assertSame(255, \core_text::strlen($row->lasterror));

        $repo->mark_closed(1);
        $repo->record_close_error(1, 'ignored');
        $this->assertEquals(2, $repo->get_by_attempt(1)->closeattempts, 'not counted once closed');

        $repo->record_close_error(404, 'no such session');
        $this->assertNull($repo->get_by_attempt(404));
    }

    /**
     * Deleting by attempt and by course.
     */
    public function test_deletion(): void {
        global $DB;
        $this->resetAfterTest();
        $repo = new session_repository();
        $repo->ensure_started(new attempt_facts(1, 7, 3, 11, 5, 'inprogress', false, 'C'), 'K');
        $repo->ensure_started(new attempt_facts(2, 7, 3, 11, 5, 'inprogress', false, 'C'), 'K');
        $repo->ensure_started(new attempt_facts(3, 8, 4, 12, 6, 'inprogress', false, 'D'), 'K');

        $repo->delete_for_attempt(1);
        $this->assertNull($repo->get_by_attempt(1));
        $this->assertEquals(2, $DB->count_records('tool_quizproctoring_session'));

        $repo->delete_for_course(3);
        $this->assertNull($repo->get_by_attempt(2));
        $this->assertNotNull($repo->get_by_attempt(3));
    }
}
