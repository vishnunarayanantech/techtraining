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

namespace tool_courseproctoring\task;

use tool_courseproctoring\local\session_repository;
use tool_courseproctoring\local\vendor\close_result;
use tool_courseproctoring\local\vendor\vendor_factory;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/../course_helper_trait.php');

/**
 * Tests for the retry task.
 *
 * @package    tool_courseproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_courseproctoring\task\close_session
 */
final class close_session_test extends \advanced_testcase {
    use \tool_courseproctoring\course_helper_trait;

    #[\Override]
    protected function tearDown(): void {
        vendor_factory::set_override(null);
        parent::tearDown();
    }

    /**
     * A switched-on site and a started session.
     *
     * @return \stdClass the ledger row
     */
    private function start_session(): \stdClass {
        $this->resetAfterTest();
        $this->enable_integration('APP-SECRET-1234');
        $data = $this->create_proctored_course();
        return (new session_repository())->ensure_started($data->user->id, $data->course->id, 'KEY-LEDGER');
    }

    /**
     * The task that was queued.
     *
     * @return close_session
     */
    private function queued_task(): close_session {
        return array_values(\core\task\manager::get_adhoc_tasks(close_session::class))[0];
    }

    /**
     * Queueing twice for the same row queues one task, and the task carries only the row id.
     */
    public function test_queue_is_deduplicated_and_carries_no_secrets(): void {
        $session = $this->start_session();

        close_session::queue($session->id);
        close_session::queue($session->id);
        close_session::queue($session->id + 1);

        $tasks = \core\task\manager::get_adhoc_tasks(close_session::class);
        $this->assertCount(2, $tasks);
        $first = reset($tasks);
        $this->assertSame('tool_courseproctoring', $first->get_component());
        $this->assertSame('{"rowid":' . $session->id . '}', $first->get_custom_data_as_string());
        $serialised = json_encode(array_map(static fn($task) => $task->get_custom_data_as_string(), $tasks));
        foreach (['APP-SECRET', 'KEY-LEDGER', $session->sessionid] as $secret) {
            $this->assertStringNotContainsString($secret, $serialised);
        }
        $this->assertNotEmpty($first->get_name());
    }

    /**
     * A successful run closes the session.
     */
    public function test_execute_success(): void {
        $session = $this->start_session();
        $vendor = $this->create_fake_vendor();
        vendor_factory::set_override($vendor);

        close_session::queue($session->id);
        $this->queued_task()->execute();

        $this->assertEquals(session_repository::STATUS_CLOSED, (new session_repository())->get_by_id($session->id)->status);
        $this->assertSame($session->sessionid, $vendor->closes[0]['sessionid']);
    }

    /**
     * A retryable failure throws, which makes Moodle reschedule the task with back-off.
     */
    public function test_execute_retryable_failure_throws(): void {
        $session = $this->start_session();
        vendor_factory::set_override($this->create_fake_vendor([close_result::RETRYABLE]));

        close_session::queue($session->id);
        $task = $this->queued_task();

        $this->expectException(\moodle_exception::class);
        $task->execute();
    }

    /**
     * On the last allowed run the failure is recorded as final and the task finishes without throwing.
     */
    public function test_execute_last_attempt_records_failure_without_throwing(): void {
        $session = $this->start_session();
        vendor_factory::set_override($this->create_fake_vendor([close_result::RETRYABLE]));

        close_session::queue($session->id);
        $task = $this->queued_task();
        $task->set_attempts_available(1);
        $task->execute();

        $row = (new session_repository())->get_by_id($session->id);
        $this->assertEquals(session_repository::STATUS_FAILED, $row->status);
        $this->assertSame('gave up retrying: HTTP 503', $row->lasterror);
    }

    /**
     * A permanent failure ends the task normally: retrying cannot help.
     */
    public function test_execute_permanent_failure_does_not_throw(): void {
        $session = $this->start_session();
        vendor_factory::set_override($this->create_fake_vendor([close_result::PERMANENT]));

        close_session::queue($session->id);
        $this->queued_task()->execute();

        $this->assertEquals(session_repository::STATUS_FAILED, (new session_repository())->get_by_id($session->id)->status);
    }

    /**
     * A task for a session that does not exist, or with bad data, does nothing.
     */
    public function test_execute_is_harmless_when_there_is_nothing_to_do(): void {
        $this->resetAfterTest();
        $this->enable_integration();
        $vendor = $this->create_fake_vendor();
        vendor_factory::set_override($vendor);

        close_session::queue(404);
        $this->queued_task()->execute();

        $bad = new close_session();
        $bad->set_custom_data(['rowid' => 0]);
        $bad->execute();

        $this->assertSame([], $vendor->closes);
    }
}
