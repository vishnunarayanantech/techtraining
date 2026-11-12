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
 * Tests for the session ledger.
 *
 * @package    tool_courseproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_courseproctoring\local\session_repository
 */
final class session_repository_test extends \advanced_testcase {
    /**
     * A learner and two courses.
     *
     * @return \stdClass user, course1, course2
     */
    private function setup_data(): \stdClass {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        return (object) [
            'user' => $generator->create_user(),
            'other' => $generator->create_user(),
            'course1' => $generator->create_course(),
            'course2' => $generator->create_course(),
        ];
    }

    /**
     * The first call makes the row, with a valid random id and the Key ID; every later call returns the same row.
     */
    public function test_ensure_started_is_idempotent(): void {
        global $DB;
        $data = $this->setup_data();
        $repo = new session_repository();
        $this->assertNull($repo->get($data->user->id, $data->course1->id));

        $first = $repo->ensure_started($data->user->id, $data->course1->id, 'KEY-ONE');

        $this->assertTrue(session_id::is_valid($first->sessionid));
        $this->assertSame('KEY-ONE', $first->keyid);
        $this->assertEquals(session_repository::STATUS_ACTIVE, $first->status);
        $this->assertEquals(0, $first->closeattempts);
        $this->assertNull($first->lasterror);
        $this->assertNull($first->timeclosed);

        $again = $repo->ensure_started($data->user->id, $data->course1->id, 'KEY-TWO');
        $this->assertEquals($first->id, $again->id);
        $this->assertSame($first->sessionid, $again->sessionid, 'the session id never changes');
        $this->assertSame('KEY-ONE', $again->keyid, 'neither does the Key ID the session started with');
        $this->assertEquals(1, $DB->count_records('tool_courseproctoring_session'));
    }

    /**
     * One session per learner per course: another course or another learner gets its own row and its own id.
     */
    public function test_one_session_per_learner_per_course(): void {
        $data = $this->setup_data();
        $repo = new session_repository();

        $a = $repo->ensure_started($data->user->id, $data->course1->id, 'KEY-1');
        $b = $repo->ensure_started($data->user->id, $data->course2->id, 'KEY-2');
        $c = $repo->ensure_started($data->other->id, $data->course1->id, 'KEY-1');

        $this->assertCount(3, array_unique([$a->sessionid, $b->sessionid, $c->sessionid]));
        $this->assertCount(3, array_unique([$a->id, $b->id, $c->id]));
    }

    /**
     * Two pages starting at the same moment: the insert of the loser fails on the unique key, and it gets the winner's row.
     */
    public function test_a_concurrent_start_returns_the_winning_row(): void {
        $data = $this->setup_data();
        $winner = (new session_repository())->ensure_started($data->user->id, $data->course1->id, 'KEY-WINNER');

        // A repository that does not see the row on its first look (the other request has not committed yet).
        $blind = new class extends session_repository {
            /** @var int Number of calls so far. */
            private int $calls = 0;

            #[\Override]
            public function get(int $userid, int $courseid): ?\stdClass {
                return $this->calls++ === 0 ? null : parent::get($userid, $courseid);
            }
        };
        $loser = $blind->ensure_started($data->user->id, $data->course1->id, 'KEY-LOSER');

        $this->assertEquals($winner->id, $loser->id);
        $this->assertSame($winner->sessionid, $loser->sessionid);
        $this->assertSame('KEY-WINNER', $loser->keyid);
    }

    /**
     * If the database says a drawn id is already in use (practically impossible), a new id is drawn.
     */
    public function test_an_id_clash_draws_a_new_id(): void {
        $data = $this->setup_data();
        $taken = 'course_' . str_repeat('a', 20);
        $ids = [$taken, $taken, 'course_' . str_repeat('b', 20)];
        $repo = new session_repository(static function () use (&$ids): string {
            return array_shift($ids);
        });

        $first = $repo->ensure_started($data->user->id, $data->course1->id, 'KEY-1');
        $this->assertSame($taken, $first->sessionid);
        $second = $repo->ensure_started($data->other->id, $data->course1->id, 'KEY-1');

        $this->assertSame('course_' . str_repeat('b', 20), $second->sessionid, 'the clash was retried with a new id');
    }

    /**
     * Giving up: if every try clashes, the error is not hidden.
     */
    public function test_endless_clashes_are_reported(): void {
        $data = $this->setup_data();
        $same = 'course_' . str_repeat('c', 20);
        $repo = new session_repository(static fn(): string => $same);
        $repo->ensure_started($data->user->id, $data->course1->id, 'KEY-1');

        $this->expectException(\dml_write_exception::class);
        $repo->ensure_started($data->other->id, $data->course1->id, 'KEY-1');
    }

    /**
     * Close, fail, and count failed closes; only an active row can be moved on.
     */
    public function test_status_changes(): void {
        $data = $this->setup_data();
        $repo = new session_repository();
        $one = $repo->ensure_started($data->user->id, $data->course1->id, 'KEY-1');
        $two = $repo->ensure_started($data->user->id, $data->course2->id, 'KEY-2');

        $repo->record_close_error($one->id, 'HTTP 503');
        $repo->record_close_error($one->id, str_repeat('x', 400));
        $row = $repo->get_by_id($one->id);
        $this->assertEquals(2, $row->closeattempts);
        $this->assertSame(255, strlen($row->lasterror), 'a long message is cut');
        $this->assertEquals(session_repository::STATUS_ACTIVE, $row->status);

        $this->assertTrue($repo->mark_closed($one->id));
        $row = $repo->get_by_id($one->id);
        $this->assertEquals(session_repository::STATUS_CLOSED, $row->status);
        $this->assertNotNull($row->timeclosed);
        $this->assertNull($row->lasterror);
        $this->assertFalse($repo->mark_closed($one->id), 'already closed');
        $this->assertFalse($repo->mark_failed($one->id, 'late'), 'a closed session cannot fail');
        $repo->record_close_error($one->id, 'ignored');
        $this->assertEquals(2, $repo->get_by_id($one->id)->closeattempts, 'errors are only counted on active rows');

        $this->assertTrue($repo->mark_failed($two->id, 'HTTP 400'));
        $row = $repo->get_by_id($two->id);
        $this->assertEquals(session_repository::STATUS_FAILED, $row->status);
        $this->assertSame('HTTP 400', $row->lasterror);
        $this->assertNull($row->timeclosed);
        $this->assertFalse($repo->mark_closed($two->id));

        $this->assertNull($repo->get_by_id(987654));
        $this->assertFalse($repo->mark_closed(987654));
    }

    /**
     * The active sessions of a learner, in one course or all.
     */
    public function test_get_active_for_user(): void {
        $data = $this->setup_data();
        $repo = new session_repository();
        $one = $repo->ensure_started($data->user->id, $data->course1->id, 'KEY-1');
        $two = $repo->ensure_started($data->user->id, $data->course2->id, 'KEY-2');
        $repo->ensure_started($data->other->id, $data->course1->id, 'KEY-1');
        $repo->mark_closed($two->id);

        $this->assertSame([(int) $one->id], array_map('intval', array_keys($repo->get_active_for_user($data->user->id))));
        $inone = $repo->get_active_for_user($data->user->id, $data->course1->id);
        $this->assertSame([(int) $one->id], array_map('intval', array_keys($inone)));
        $this->assertSame([], $repo->get_active_for_user($data->user->id, $data->course2->id));
        $this->assertSame([], $repo->get_active_for_user(987654));
    }

    /**
     * Rows go with their course or their user, and only theirs.
     */
    public function test_delete(): void {
        global $DB;
        $data = $this->setup_data();
        $repo = new session_repository();
        $repo->ensure_started($data->user->id, $data->course1->id, 'KEY-1');
        $repo->ensure_started($data->user->id, $data->course2->id, 'KEY-2');
        $repo->ensure_started($data->other->id, $data->course1->id, 'KEY-1');

        $repo->delete_for_course($data->course1->id);
        $this->assertEquals(1, $DB->count_records('tool_courseproctoring_session'));
        $this->assertNotNull($repo->get($data->user->id, $data->course2->id));

        $repo->delete_for_user($data->user->id);
        $this->assertEquals(0, $DB->count_records('tool_courseproctoring_session'));
    }
}
