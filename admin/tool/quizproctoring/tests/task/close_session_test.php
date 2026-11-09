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

namespace tool_quizproctoring\task;

use tool_quizproctoring\local\attempt_facts;
use tool_quizproctoring\local\session_repository;
use tool_quizproctoring\local\vendor\close_result;
use tool_quizproctoring\local\vendor\vendor_factory;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/../attempt_helper_trait.php');

/**
 * Tests for the retry task.
 *
 * @package    tool_quizproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_quizproctoring\task\close_session
 */
final class close_session_test extends \advanced_testcase {
    use \tool_quizproctoring\attempt_helper_trait;

    #[\Override]
    protected function tearDown(): void {
        vendor_factory::set_override(null);
        parent::tearDown();
    }

    /**
     * Start a ledger row.
     */
    private function start_session(): void {
        (new session_repository())->ensure_started(new attempt_facts(1, 5, 3, 11, 2, 'inprogress', false, 'C'), 'KEY-LEDGER');
    }

    /**
     * Queueing twice for the same attempt queues one task, and the task carries only the attempt id.
     */
    public function test_queue_is_deduplicated_and_carries_no_secrets(): void {
        $this->resetAfterTest();
        $this->enable_integration('APP-SECRET-1234');
        $this->start_session();

        close_session::queue(1);
        close_session::queue(1);
        close_session::queue(2);

        $tasks = \core\task\manager::get_adhoc_tasks(close_session::class);
        $this->assertCount(2, $tasks);
        $first = reset($tasks);
        $this->assertSame('tool_quizproctoring', $first->get_component());
        $this->assertSame('{"attemptid":1}', $first->get_custom_data_as_string());
        $serialised = json_encode(array_map(static fn($task) => $task->get_custom_data_as_string(), $tasks));
        $this->assertStringNotContainsString('APP-SECRET', $serialised);
        $this->assertStringNotContainsString('KEY-LEDGER', $serialised);
        $this->assertNotEmpty($first->get_name());
    }

    /**
     * A successful run closes the session.
     */
    public function test_execute_success(): void {
        $this->resetAfterTest();
        $this->enable_integration();
        $this->start_session();
        vendor_factory::set_override($this->create_fake_vendor());

        close_session::queue(1);
        $task = array_values(\core\task\manager::get_adhoc_tasks(close_session::class))[0];
        $task->execute();

        $this->assertEquals(session_repository::STATUS_CLOSED, (new session_repository())->get_by_attempt(1)->status);
    }

    /**
     * A retryable failure throws, which makes Moodle reschedule the task with back-off.
     */
    public function test_execute_retryable_failure_throws(): void {
        $this->resetAfterTest();
        $this->enable_integration();
        $this->start_session();
        vendor_factory::set_override($this->create_fake_vendor([close_result::RETRYABLE]));

        close_session::queue(1);
        $task = array_values(\core\task\manager::get_adhoc_tasks(close_session::class))[0];

        $this->expectException(\moodle_exception::class);
        $task->execute();
    }

    /**
     * On the last allowed run the failure is recorded as final and the task finishes without throwing.
     */
    public function test_execute_last_attempt_records_failure_without_throwing(): void {
        $this->resetAfterTest();
        $this->enable_integration();
        $this->start_session();
        vendor_factory::set_override($this->create_fake_vendor([close_result::RETRYABLE]));

        close_session::queue(1);
        $task = array_values(\core\task\manager::get_adhoc_tasks(close_session::class))[0];
        $task->set_attempts_available(1);
        $task->execute();

        $row = (new session_repository())->get_by_attempt(1);
        $this->assertEquals(session_repository::STATUS_FAILED, $row->status);
        $this->assertSame('gave up retrying: HTTP 503', $row->lasterror);
    }

    /**
     * A permanent failure ends the task normally: retrying cannot help.
     */
    public function test_execute_permanent_failure_does_not_throw(): void {
        $this->resetAfterTest();
        $this->enable_integration();
        $this->start_session();
        vendor_factory::set_override($this->create_fake_vendor([close_result::PERMANENT]));

        close_session::queue(1);
        $task = array_values(\core\task\manager::get_adhoc_tasks(close_session::class))[0];
        $task->execute();

        $this->assertEquals(session_repository::STATUS_FAILED, (new session_repository())->get_by_attempt(1)->status);
    }

    /**
     * A task for a session that is already closed, or with bad data, does nothing.
     */
    public function test_execute_is_harmless_when_there_is_nothing_to_do(): void {
        $this->resetAfterTest();
        $this->enable_integration();
        $vendor = $this->create_fake_vendor();
        vendor_factory::set_override($vendor);

        close_session::queue(404);
        array_values(\core\task\manager::get_adhoc_tasks(close_session::class))[0]->execute();

        $bad = new close_session();
        $bad->set_custom_data(['attemptid' => 0]);
        $bad->execute();

        $this->assertSame([], $vendor->closes);
    }
}
