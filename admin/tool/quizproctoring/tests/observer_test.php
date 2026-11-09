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

namespace tool_quizproctoring;

use mod_quiz\quiz_attempt;
use tool_quizproctoring\local\attempt_facts;
use tool_quizproctoring\local\quiz_key_repository;
use tool_quizproctoring\local\session_repository;
use tool_quizproctoring\local\vendor\close_result;
use tool_quizproctoring\local\vendor\vendor_factory;
use tool_quizproctoring\task\close_session;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/attempt_helper_trait.php');

/**
 * Tests for the event observers, driven by REAL quiz submissions and abandonments.
 *
 * The observers are registered with 'internal' => false, which only fires after the quiz transaction commits; these
 * tests therefore run without Moodle's rollback-based test isolation.
 *
 * @package    tool_quizproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_quizproctoring\observer
 */
final class observer_test extends \advanced_testcase {
    use attempt_helper_trait;

    #[\Override]
    protected function tearDown(): void {
        vendor_factory::set_override(null);
        parent::tearDown();
    }

    /**
     * A quiz attempt whose proctoring session this plugin has "started" (a ledger row).
     *
     * @param bool $withsession False for an attempt this plugin never started a session for.
     * @return \stdClass see create_quiz_attempt(); plus keyid
     */
    private function attempt_with_session(bool $withsession = true): \stdClass {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->enable_integration('APP-KEY-123456');

        $data = $this->create_quiz_attempt();
        (new quiz_key_repository())->set((int) $data->cm->id, 'KEY-QUIZ');
        $data->keyid = 'KEY-QUIZ';
        if ($withsession) {
            (new session_repository())->ensure_started(attempt_facts::from_database($data->attempt->id), 'KEY-QUIZ');
        }
        $this->setUser($data->user);
        return $data;
    }

    /**
     * Submitting the attempt closes its proctoring session once the quiz transaction has committed.
     */
    public function test_submitted_attempt_closes_the_session(): void {
        global $DB;
        $data = $this->attempt_with_session();
        $vendor = $this->create_fake_vendor();
        vendor_factory::set_override($vendor);

        $data->attemptobj->process_finish(time(), false);

        $this->assertSame(
            [['appkey' => 'APP-KEY-123456', 'keyid' => 'KEY-QUIZ', 'attemptid' => (int) $data->attempt->id]],
            $vendor->closes
        );
        $this->assertEquals(
            session_repository::STATUS_CLOSED,
            (new session_repository())->get_by_attempt($data->attempt->id)->status
        );
        $this->assertSame(quiz_attempt::FINISHED, $DB->get_field('quiz_attempts', 'state', ['id' => $data->attempt->id]));
    }

    /**
     * An abandoned attempt (for example one left to expire) closes its session too.
     */
    public function test_abandoned_attempt_closes_the_session(): void {
        $data = $this->attempt_with_session();
        $vendor = $this->create_fake_vendor();
        vendor_factory::set_override($vendor);

        $data->attemptobj->process_abandon(time(), false);

        $this->assertCount(1, $vendor->closes);
        $this->assertEquals(
            session_repository::STATUS_CLOSED,
            (new session_repository())->get_by_attempt($data->attempt->id)->status
        );
    }

    /**
     * Only sessions this plugin started are closed: an attempt without a ledger row (for example in a course without a
     * Key ID, or one started before this plugin was set up) causes no vendor call.
     */
    public function test_attempt_without_a_session_is_left_alone(): void {
        $data = $this->attempt_with_session(false);
        $vendor = $this->create_fake_vendor();
        vendor_factory::set_override($vendor);

        $data->attemptobj->process_finish(time(), false);

        $this->assertSame([], $vendor->closes);
    }

    /**
     * A vendor outage never breaks the student's submission: the attempt is finished, no exception reaches the quiz,
     * the session stays active and a retry task is queued.
     */
    public function test_vendor_outage_does_not_break_the_submission_and_queues_a_retry(): void {
        global $DB;
        $data = $this->attempt_with_session();
        $vendor = $this->create_fake_vendor([close_result::RETRYABLE]);
        vendor_factory::set_override($vendor);

        $data->attemptobj->process_finish(time(), false);

        $this->assertSame(quiz_attempt::FINISHED, $DB->get_field('quiz_attempts', 'state', ['id' => $data->attempt->id]));
        $row = (new session_repository())->get_by_attempt($data->attempt->id);
        $this->assertEquals(session_repository::STATUS_ACTIVE, $row->status);
        $this->assertEquals(1, $row->closeattempts);
        $tasks = \core\task\manager::get_adhoc_tasks(close_session::class);
        $this->assertCount(1, $tasks);
        $this->assertSame(
            '{"attemptid":' . (int) $data->attempt->id . '}',
            reset($tasks)->get_custom_data_as_string()
        );
    }

    /**
     * Even an unexpected exception in the vendor client is contained: the submission completes and a retry is queued.
     */
    public function test_unexpected_exception_is_contained(): void {
        global $DB;
        $data = $this->attempt_with_session();
        $vendor = $this->create_fake_vendor();
        $vendor->throw = new \RuntimeException('unexpected');
        vendor_factory::set_override($vendor);

        $data->attemptobj->process_finish(time(), false);

        $this->assertSame(quiz_attempt::FINISHED, $DB->get_field('quiz_attempts', 'state', ['id' => $data->attempt->id]));
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(close_session::class));
        $this->assertDebuggingCalled(null, DEBUG_DEVELOPER);
    }

    /**
     * Sessions of teacher previews are never started, and finishing a preview raises no submit event at all.
     */
    public function test_preview_attempt_finish_closes_nothing(): void {
        global $DB;
        $data = $this->attempt_with_session(false);
        $DB->set_field('quiz_attempts', 'preview', 1, ['id' => $data->attempt->id]);
        $vendor = $this->create_fake_vendor();
        vendor_factory::set_override($vendor);

        quiz_attempt::create($data->attempt->id)->process_finish(time(), false);

        $this->assertSame([], $vendor->closes);
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(close_session::class));
    }

    /**
     * The observer registrations: the two end-of-attempt events are post-commit ('internal' => false).
     */
    public function test_observers_are_registered_for_after_commit(): void {
        global $CFG;
        $observers = [];
        require($CFG->dirroot . '/admin/tool/quizproctoring/db/events.php');
        $byevent = array_column($observers, null, 'eventname');

        foreach (['\mod_quiz\event\attempt_submitted', '\mod_quiz\event\attempt_abandoned'] as $eventname) {
            $this->assertArrayHasKey($eventname, $byevent);
            $this->assertFalse($byevent[$eventname]['internal'], "$eventname must be handled after the transaction commits");
            $this->assertSame('\tool_quizproctoring\observer::attempt_ended', $byevent[$eventname]['callback']);
        }
        $this->assertTrue(class_exists(\mod_quiz\event\attempt_submitted::class));
        $this->assertTrue(class_exists(\mod_quiz\event\attempt_abandoned::class));
    }

    /**
     * The event really carries what the observer relies on (verified against mod_quiz): the attempt id, the course,
     * the quiz course module and the attempt's owner.
     */
    public function test_event_payload(): void {
        $data = $this->attempt_with_session();
        $sink = $this->redirectEvents();

        $data->attemptobj->process_finish(time(), false);
        $events = array_values(array_filter(
            $sink->get_events(),
            static fn($event) => $event instanceof \mod_quiz\event\attempt_submitted
        ));

        // Redirected events are not delivered to external observers, so close nothing here.
        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertEquals($data->attempt->id, $event->objectid);
        $this->assertEquals($data->course->id, $event->courseid);
        $this->assertEquals($data->cm->id, $event->contextinstanceid);
        $this->assertEquals($data->user->id, $event->relateduserid);
        $this->assertEquals($data->quiz->id, $event->other['quizid']);
    }

    /**
     * Deleting an attempt forgets its ledger row.
     */
    public function test_deleting_an_attempt_removes_its_session(): void {
        $data = $this->attempt_with_session();
        $this->assertNotNull((new session_repository())->get_by_attempt($data->attempt->id));

        quiz_delete_attempt($data->attempt->id, $data->quiz);

        $this->assertNull((new session_repository())->get_by_attempt($data->attempt->id));
    }

    /**
     * Deleting a course forgets the Key IDs of its quizzes and all its ledger rows, and leaves other courses alone.
     */
    public function test_deleting_a_course_removes_its_quiz_keys_and_sessions(): void {
        global $DB;
        $data = $this->attempt_with_session();
        $other = $this->create_quiz_attempt();
        (new session_repository())->ensure_started(attempt_facts::from_database($other->attempt->id), 'KEY-OTHER');
        (new quiz_key_repository())->set((int) $other->cm->id, 'KEY-OTHER');

        delete_course($data->course, false);

        $this->assertSame('', (new quiz_key_repository())->get((int) $data->cm->id));
        $this->assertNull((new session_repository())->get_by_attempt($data->attempt->id));
        $this->assertSame('KEY-OTHER', (new quiz_key_repository())->get((int) $other->cm->id));
        $this->assertNotNull((new session_repository())->get_by_attempt($other->attempt->id));
        $this->assertEquals(1, $DB->count_records('tool_quizproctoring_quiz'));
    }

    /**
     * Deleting a quiz forgets its Key ID; the Key ID of another quiz in the same course stays.
     */
    public function test_deleting_a_quiz_removes_its_key(): void {
        global $DB;
        $data = $this->attempt_with_session();
        $sibling = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance(['course' => $data->course->id]);
        (new quiz_key_repository())->set((int) $sibling->cmid, 'KEY-SIBLING');

        course_delete_module((int) $data->cm->id);

        $this->assertSame('', (new quiz_key_repository())->get((int) $data->cm->id));
        $this->assertSame('KEY-SIBLING', (new quiz_key_repository())->get((int) $sibling->cmid));
        $this->assertEquals(1, $DB->count_records('tool_quizproctoring_quiz'));
    }

    /**
     * Deleting an activity that is not a quiz never touches a quiz's Key ID, even if the ids collide.
     */
    public function test_deleting_another_activity_leaves_quiz_keys_alone(): void {
        $data = $this->attempt_with_session();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $data->course->id]);

        course_delete_module((int) $page->cmid);

        $this->assertSame('KEY-QUIZ', (new quiz_key_repository())->get((int) $data->cm->id));
    }
}
