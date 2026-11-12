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

namespace tool_courseproctoring;

use tool_courseproctoring\local\config;
use tool_courseproctoring\local\course_key_repository;
use tool_courseproctoring\local\session_repository;
use tool_courseproctoring\local\vendor\close_result;
use tool_courseproctoring\local\vendor\vendor_factory;
use tool_courseproctoring\task\close_session;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/course_helper_trait.php');

/**
 * Tests for the event observers, driven by REAL Moodle actions: completing a course, un-enrolling, suspending, deleting a
 * user and deleting a course.
 *
 * The observers that call the vendor are registered with 'internal' => false, which only fires after the database
 * transaction that raised the event has committed; these tests therefore run without Moodle's rollback-based test
 * isolation.
 *
 * @package    tool_courseproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_courseproctoring\observer
 */
final class observer_test extends \advanced_testcase {
    use course_helper_trait;

    #[\Override]
    protected function tearDown(): void {
        vendor_factory::set_override(null);
        parent::tearDown();
    }

    /**
     * A switched-on site, a proctored course, its learner, and the learner's started session.
     *
     * @return \stdClass see create_proctored_course(); plus session (the ledger row) and vendor (the fake client)
     */
    private function setup_session(): \stdClass {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->enable_integration('APP-KEY-123456');
        $data = $this->create_proctored_course();
        $data->vendor = $this->create_fake_vendor();
        vendor_factory::set_override($data->vendor);
        $data->session = (new session_repository())->ensure_started($data->user->id, $data->course->id, 'KEY-COURSE');
        return $data;
    }

    /**
     * The status of a ledger row.
     *
     * @param \stdClass $session
     * @return int
     */
    private function status_of(\stdClass $session): int {
        return (int) (new session_repository())->get_by_id($session->id)->status;
    }

    /**
     * Make Moodle announce that a learner completed a course.
     *
     * @param \stdClass $course
     * @param \stdClass $user
     */
    private function complete(\stdClass $course, \stdClass $user): void {
        \core\event\course_completed::create([
            'objectid' => 1,
            'relateduserid' => $user->id,
            'context' => \context_course::instance($course->id),
            'courseid' => $course->id,
            'other' => ['relateduserid' => $user->id],
        ])->trigger();
    }

    /**
     * The manual enrolment instance of a course.
     *
     * @param \stdClass $course
     * @return \stdClass
     */
    private function manual_instance(\stdClass $course): \stdClass {
        global $DB;
        return $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
    }

    /**
     * Completing the course closes the learner's session, with the stored session id and Key ID.
     */
    public function test_completing_the_course_closes_the_session(): void {
        $data = $this->setup_session();

        $this->complete($data->course, $data->user);

        $this->assertSame(session_repository::STATUS_CLOSED, $this->status_of($data->session));
        $this->assertSame(
            [['appkey' => 'APP-KEY-123456', 'keyid' => 'KEY-COURSE', 'sessionid' => $data->session->sessionid]],
            $data->vendor->closes
        );
    }

    /**
     * Only the learner who completed it: somebody else in the course, and the same learner in another course, keep theirs.
     */
    public function test_other_sessions_are_left_alone(): void {
        $data = $this->setup_session();
        $classmate = $this->getDataGenerator()->create_and_enrol($data->course, 'student');
        $classmates = (new session_repository())->ensure_started($classmate->id, $data->course->id, 'KEY-COURSE');
        $other = $this->getDataGenerator()->create_course();
        (new course_key_repository())->set((int) $other->id, 'KEY-OTHER');
        $elsewhere = (new session_repository())->ensure_started($data->user->id, $other->id, 'KEY-OTHER');

        $this->complete($data->course, $data->user);

        $this->assertSame(session_repository::STATUS_CLOSED, $this->status_of($data->session));
        $this->assertSame(session_repository::STATUS_ACTIVE, $this->status_of($classmates));
        $this->assertSame(session_repository::STATUS_ACTIVE, $this->status_of($elsewhere));
        $this->assertCount(1, $data->vendor->closes);
    }

    /**
     * A learner this plugin never started a session for (a course without a key, or one started before the plugin was set
     * up) causes no vendor call.
     */
    public function test_completion_without_a_session_is_left_alone(): void {
        $data = $this->setup_session();
        $stranger = $this->getDataGenerator()->create_and_enrol($data->course, 'student');

        $this->complete($data->course, $stranger);

        $this->assertSame([], $data->vendor->closes);
        $this->assertSame(session_repository::STATUS_ACTIVE, $this->status_of($data->session));
    }

    /**
     * Sessions that were started are still closed after the switch is turned off.
     */
    public function test_closing_works_with_the_switch_off(): void {
        $data = $this->setup_session();
        config::set_vendor_enabled(false);

        $this->complete($data->course, $data->user);

        $this->assertSame(session_repository::STATUS_CLOSED, $this->status_of($data->session));
    }

    /**
     * Un-enrolling the learner closes the session.
     */
    public function test_unenrolling_closes_the_session(): void {
        $data = $this->setup_session();

        enrol_get_plugin('manual')->unenrol_user($this->manual_instance($data->course), $data->user->id);

        $this->assertSame(session_repository::STATUS_CLOSED, $this->status_of($data->session));
        $this->assertCount(1, $data->vendor->closes);
    }

    /**
     * Suspending the learner closes the session.
     */
    public function test_suspending_closes_the_session(): void {
        $data = $this->setup_session();

        enrol_get_plugin('manual')->update_user_enrol($this->manual_instance($data->course), $data->user->id, ENROL_USER_SUSPENDED);

        $this->assertSame(session_repository::STATUS_CLOSED, $this->status_of($data->session));
    }

    /**
     * A change that leaves the learner actively enrolled (here a later end date) does not close anything.
     */
    public function test_a_harmless_enrolment_change_keeps_the_session(): void {
        $data = $this->setup_session();

        enrol_get_plugin('manual')->update_user_enrol(
            $this->manual_instance($data->course),
            $data->user->id,
            ENROL_USER_ACTIVE,
            0,
            time() + DAYSECS
        );

        $this->assertSame(session_repository::STATUS_ACTIVE, $this->status_of($data->session));
        $this->assertSame([], $data->vendor->closes);
    }

    /**
     * A learner who is un-enrolled from one method but still actively enrolled through another keeps the session.
     */
    public function test_still_enrolled_another_way_keeps_the_session(): void {
        global $DB;
        $data = $this->setup_session();
        $self = $DB->get_record('enrol', ['courseid' => $data->course->id, 'enrol' => 'self'], '*', MUST_EXIST);
        $DB->set_field('enrol', 'status', ENROL_INSTANCE_ENABLED, ['id' => $self->id]);
        $self->status = ENROL_INSTANCE_ENABLED;
        enrol_get_plugin('self')->enrol_user($self, $data->user->id);

        enrol_get_plugin('manual')->unenrol_user($this->manual_instance($data->course), $data->user->id);

        $this->assertSame(session_repository::STATUS_ACTIVE, $this->status_of($data->session));
        $this->assertSame([], $data->vendor->closes);

        enrol_get_plugin('self')->unenrol_user($self, $data->user->id);
        $this->assertSame(session_repository::STATUS_CLOSED, $this->status_of($data->session), 'and now they are out');
    }

    /**
     * Deleting a user closes their sessions and removes every trace of them from the ledger.
     */
    public function test_deleting_a_user_closes_and_forgets(): void {
        global $DB;
        $data = $this->setup_session();
        $classmate = $this->getDataGenerator()->create_and_enrol($data->course, 'student');
        (new session_repository())->ensure_started($classmate->id, $data->course->id, 'KEY-COURSE');

        delete_user($data->user);

        $this->assertCount(1, $data->vendor->closes, 'the deleted learner\'s session was closed once');
        $this->assertSame($data->session->sessionid, $data->vendor->closes[0]['sessionid']);
        $this->assertFalse($DB->record_exists('tool_courseproctoring_session', ['userid' => $data->user->id]));
        $this->assertTrue($DB->record_exists('tool_courseproctoring_session', ['userid' => $classmate->id]), 'others stay');
    }

    /**
     * A user who is deleted while a session of theirs cannot be closed at the moment is still forgotten at once.
     */
    public function test_a_deleted_user_is_forgotten_even_if_the_vendor_is_down(): void {
        global $DB;
        $data = $this->setup_session();
        $data->vendor->throw = new \RuntimeException('vendor down');

        delete_user($data->user);

        $this->assertFalse($DB->record_exists('tool_courseproctoring_session', ['userid' => $data->user->id]));
        // One notice from the un-enrolment that deleting a user causes, one from the user deletion itself.
        $this->assertDebuggingCalledCount(2);
    }

    /**
     * Deleting a course removes its Proctor Key and session rows, without calling the vendor once per learner: the
     * un-enrolments that deleting a course causes must not slow the deletion down.
     */
    public function test_deleting_a_course_does_not_call_the_vendor(): void {
        global $DB;
        $data = $this->setup_session();
        $classmate = $this->getDataGenerator()->create_and_enrol($data->course, 'student');
        (new session_repository())->ensure_started($classmate->id, $data->course->id, 'KEY-COURSE');
        $kept = $this->create_proctored_course(['key' => 'KEY-KEPT']);
        (new session_repository())->ensure_started($kept->user->id, $kept->course->id, 'KEY-KEPT');

        delete_course($data->course, false);

        $this->assertSame([], $data->vendor->closes, 'no vendor call at all');
        $this->assertSame('', (new course_key_repository())->get((int) $data->course->id));
        $this->assertFalse($DB->record_exists('tool_courseproctoring_session', ['courseid' => $data->course->id]));
        $this->assertSame('KEY-KEPT', (new course_key_repository())->get((int) $kept->course->id));
        $this->assertTrue($DB->record_exists('tool_courseproctoring_session', ['courseid' => $kept->course->id]));
    }

    /**
     * After a course was deleted, un-enrolling someone from another course works as usual again.
     */
    public function test_the_deletion_note_does_not_leak_to_other_courses(): void {
        $data = $this->setup_session();
        $gone = $this->create_proctored_course(['key' => 'KEY-GONE']);
        delete_course($gone->course, false);

        enrol_get_plugin('manual')->unenrol_user($this->manual_instance($data->course), $data->user->id);

        $this->assertSame(session_repository::STATUS_CLOSED, $this->status_of($data->session));
    }

    /**
     * Deleting a course that has no key and no sessions is harmless.
     */
    public function test_deleting_a_course_without_a_key(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->assertTrue(delete_course($course, false));
    }

    /**
     * A vendor outage never breaks completing the course: the session stays active and a retry is queued.
     */
    public function test_a_vendor_outage_queues_a_retry_and_breaks_nothing(): void {
        $data = $this->setup_session();
        $data->vendor = $this->create_fake_vendor([close_result::RETRYABLE]);
        vendor_factory::set_override($data->vendor);

        $this->complete($data->course, $data->user);

        $row = (new session_repository())->get_by_id($data->session->id);
        $this->assertEquals(session_repository::STATUS_ACTIVE, $row->status);
        $this->assertEquals(1, $row->closeattempts);
        $tasks = \core\task\manager::get_adhoc_tasks(close_session::class);
        $this->assertCount(1, $tasks);
        $this->assertSame('{"rowid":' . $data->session->id . '}', reset($tasks)->get_custom_data_as_string());
    }

    /**
     * A permanent refusal is final and queues nothing.
     */
    public function test_a_permanent_refusal_queues_nothing(): void {
        $data = $this->setup_session();
        vendor_factory::set_override($this->create_fake_vendor([close_result::PERMANENT]));

        $this->complete($data->course, $data->user);

        $this->assertSame(session_repository::STATUS_FAILED, $this->status_of($data->session));
        $this->assertSame([], \core\task\manager::get_adhoc_tasks(close_session::class));
    }

    /**
     * Even a vendor client that blows up is contained: nothing reaches the action that raised the event, and a retry
     * is queued.
     */
    public function test_an_exception_is_contained_and_queues_a_retry(): void {
        $data = $this->setup_session();
        $data->vendor->throw = new \RuntimeException('boom');

        $this->complete($data->course, $data->user);

        $this->assertDebuggingCalled(null, DEBUG_DEVELOPER);
        $this->assertSame(session_repository::STATUS_ACTIVE, $this->status_of($data->session));
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(close_session::class));
    }

    /**
     * Moodle finds the observers (db/events.php). The ones that call the vendor run after the transaction has
     * committed; the clean-up of a deleted course runs at once.
     */
    public function test_moodle_finds_the_observers(): void {
        $expected = [
            '\core\event\course_completed' => ['course_completed', false],
            '\core\event\user_enrolment_deleted' => ['user_enrolment_changed', false],
            '\core\event\user_enrolment_updated' => ['user_enrolment_changed', false],
            '\core\event\user_deleted' => ['user_deleted', false],
            '\core\event\course_deleted' => ['course_deleted', true],
        ];
        $observers = \core\event\manager::get_all_observers();
        foreach ($expected as $event => [$method, $internal]) {
            $ours = array_values(array_filter(
                $observers[$event] ?? [],
                static fn($observer) => str_starts_with($observer->callable, '\tool_courseproctoring\\')
            ));
            $this->assertCount(1, $ours, $event);
            $this->assertSame('\tool_courseproctoring\observer::' . $method, $ours[0]->callable, $event);
            $this->assertSame($internal, (bool) $ours[0]->internal, $event . ' internal');
        }
    }
}
