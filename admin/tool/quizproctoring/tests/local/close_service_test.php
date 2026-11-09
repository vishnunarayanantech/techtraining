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

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/../attempt_helper_trait.php');

/**
 * Tests for closing a vendor session: ledger-only, locked, idempotent and retry-aware.
 *
 * @package    tool_quizproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_quizproctoring\local\close_service
 */
final class close_service_test extends \advanced_testcase {
    use \tool_quizproctoring\attempt_helper_trait;

    /**
     * Start a ledger row for an attempt.
     *
     * @param int $attemptid
     * @param string $keyid
     */
    private function start_session(int $attemptid = 1, string $keyid = 'KEY-LEDGER'): void {
        (new session_repository())->ensure_started(new attempt_facts($attemptid, 5, 3, 11, 2, 'inprogress', false, 'C'), $keyid);
    }

    /**
     * An attempt this plugin never started a session for is not touched: no vendor call at all.
     */
    public function test_attempt_without_a_ledger_row_is_ignored(): void {
        $this->resetAfterTest();
        $this->enable_integration();
        $vendor = $this->create_fake_vendor();

        $this->assertSame(close_service::IGNORED, $this->create_close_service($vendor)->close_attempt(77));
        $this->assertSame([], $vendor->closes);
    }

    /**
     * Success closes the session, using the Key ID the session started with, and the ledger records it.
     */
    public function test_success(): void {
        $this->resetAfterTest();
        $this->enable_integration('APP-KEY-123456');
        $this->start_session(1, 'KEY-LEDGER');
        $vendor = $this->create_fake_vendor();

        $this->assertSame(close_service::CLOSED, $this->create_close_service($vendor)->close_attempt(1));

        $this->assertSame([['appkey' => 'APP-KEY-123456', 'keyid' => 'KEY-LEDGER', 'attemptid' => 1]], $vendor->closes);
        $row = (new session_repository())->get_by_attempt(1);
        $this->assertEquals(session_repository::STATUS_CLOSED, $row->status);
        $this->assertNotNull($row->timeclosed);
    }

    /**
     * Idempotent: calling again after success (a second event, a queued retry) sends nothing.
     */
    public function test_second_call_after_success_sends_nothing(): void {
        $this->resetAfterTest();
        $this->enable_integration();
        $this->start_session();
        $vendor = $this->create_fake_vendor();
        $service = $this->create_close_service($vendor);

        $this->assertSame(close_service::CLOSED, $service->close_attempt(1));
        $this->assertSame(close_service::IGNORED, $service->close_attempt(1));
        $this->assertSame(close_service::IGNORED, $service->close_attempt(1, true));
        $this->assertCount(1, $vendor->closes);
    }

    /**
     * Sessions that were started must still be closed after the integration switch is turned off.
     */
    public function test_closing_does_not_require_the_integration_switch(): void {
        $this->resetAfterTest();
        $this->enable_integration();
        $this->start_session();
        config::set_vendor_enabled(false);
        $vendor = $this->create_fake_vendor();

        $this->assertSame(close_service::CLOSED, $this->create_close_service($vendor)->close_attempt(1));
        $this->assertCount(1, $vendor->closes);
    }

    /**
     * A retryable failure leaves the session active, counts and describes the failure, and a later success closes it.
     */
    public function test_retryable_failure_then_success(): void {
        $this->resetAfterTest();
        $this->enable_integration();
        $this->start_session();
        $vendor = $this->create_fake_vendor([close_result::RETRYABLE, close_result::RETRYABLE, close_result::SUCCESS]);
        $service = $this->create_close_service($vendor);
        $repo = new session_repository();

        $this->assertSame(close_service::RETRY, $service->close_attempt(1));
        $row = $repo->get_by_attempt(1);
        $this->assertEquals(session_repository::STATUS_ACTIVE, $row->status);
        $this->assertEquals(1, $row->closeattempts);
        $this->assertSame('HTTP 503', $row->lasterror);

        $this->assertSame(close_service::RETRY, $service->close_attempt(1));
        $this->assertEquals(2, $repo->get_by_attempt(1)->closeattempts);

        $this->assertSame(close_service::CLOSED, $service->close_attempt(1));
        $this->assertEquals(session_repository::STATUS_CLOSED, $repo->get_by_attempt(1)->status);
        $this->assertCount(3, $vendor->closes);
    }

    /**
     * On the last chance a retryable failure becomes final instead of leaving the session active forever.
     */
    public function test_retryable_failure_on_the_last_chance_is_final(): void {
        $this->resetAfterTest();
        $this->enable_integration();
        $this->start_session();
        $vendor = $this->create_fake_vendor([close_result::RETRYABLE]);
        $service = $this->create_close_service($vendor);

        $this->assertSame(close_service::FAILED, $service->close_attempt(1, true));
        $row = (new session_repository())->get_by_attempt(1);
        $this->assertEquals(session_repository::STATUS_FAILED, $row->status);
        $this->assertSame('gave up retrying: HTTP 503', $row->lasterror);
        $this->assertSame(close_service::IGNORED, $service->close_attempt(1), 'and it is not tried again');
    }

    /**
     * A permanent failure is final at once and is never retried.
     */
    public function test_permanent_failure_is_final(): void {
        $this->resetAfterTest();
        $this->enable_integration();
        $this->start_session();
        $vendor = $this->create_fake_vendor([close_result::PERMANENT]);
        $service = $this->create_close_service($vendor);

        $this->assertSame(close_service::FAILED, $service->close_attempt(1));
        $row = (new session_repository())->get_by_attempt(1);
        $this->assertEquals(session_repository::STATUS_FAILED, $row->status);
        $this->assertSame('HTTP 400', $row->lasterror);
        $this->assertSame(close_service::IGNORED, $service->close_attempt(1));
        $this->assertCount(1, $vendor->closes);
    }

    /**
     * Without an App Key there is nothing to call the vendor with: final failure, no request.
     */
    public function test_no_app_key(): void {
        $this->resetAfterTest();
        $this->enable_integration();
        $this->start_session();
        config::set_app_key('');
        $vendor = $this->create_fake_vendor();

        $this->assertSame(close_service::FAILED, $this->create_close_service($vendor)->close_attempt(1));
        $this->assertSame([], $vendor->closes);
        $this->assertSame('App Key is not configured', (new session_repository())->get_by_attempt(1)->lasterror);
    }

    /**
     * While another process holds the lock for the attempt, no second request is sent.
     */
    public function test_lock_contention_sends_no_second_request(): void {
        $this->resetAfterTest();
        $this->enable_integration();
        $this->start_session();
        $vendor = $this->create_fake_vendor();

        // A file lock conflicts even inside this one process (database lock factories are re-entrant per connection).
        $factory = new \core\lock\file_lock_factory('tool_quizproctoring');
        $held = $factory->get_lock('close_1', 1);
        $this->assertNotFalse($held);

        $service = new close_service(null, $vendor, 1, $factory);
        $this->assertSame(close_service::RETRY, $service->close_attempt(1));
        $this->assertSame([], $vendor->closes);

        $this->assertSame(close_service::FAILED, $service->close_attempt(1, true), 'last chance: stop retrying');

        $held->release();
        $this->assertSame(close_service::IGNORED, $service->close_attempt(1), 'failed rows are final');
    }

    /**
     * The lock is released even if the vendor client throws, so the next attempt can proceed.
     */
    public function test_lock_is_released_when_the_vendor_throws(): void {
        $this->resetAfterTest();
        $this->enable_integration();
        $this->start_session();
        $vendor = $this->create_fake_vendor();
        $vendor->throw = new \RuntimeException('boom');
        $factory = new \core\lock\file_lock_factory('tool_quizproctoring');
        $service = new close_service(null, $vendor, 1, $factory);

        try {
            $service->close_attempt(1);
            $this->fail('the exception should propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $lock = $factory->get_lock('close_1', 1);
        $this->assertNotFalse($lock, 'the lock was released');
        $lock->release();
        $this->assertEquals(session_repository::STATUS_ACTIVE, (new session_repository())->get_by_attempt(1)->status);
    }
}
