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

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/../course_helper_trait.php');

/**
 * Tests for the rules that decide whether a page of a course is proctored.
 *
 * @package    tool_courseproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_courseproctoring\local\course_gate
 * @covers     \tool_courseproctoring\local\decision
 */
final class course_gate_test extends \advanced_testcase {
    use \tool_courseproctoring\course_helper_trait;

    /**
     * A switched-on site, a proctored course and its learner.
     *
     * @param array $options see create_proctored_course()
     * @return \stdClass
     */
    private function setup_course(array $options = []): \stdClass {
        $this->resetAfterTest();
        $this->enable_integration();
        return $this->create_proctored_course($options);
    }

    /**
     * Evaluate.
     *
     * @param \stdClass $data
     * @param array $override see facts_for()
     * @return decision
     */
    private function evaluate(\stdClass $data, array $override = []): decision {
        return (new course_gate())->evaluate($this->facts_for($data, $override));
    }

    /**
     * Everything is in order: a new session is started with the course's Key ID.
     */
    public function test_a_learner_in_a_proctored_course_is_proctored(): void {
        $data = $this->setup_course();

        $decision = $this->evaluate($data);

        $this->assertTrue($decision->proctored);
        $this->assertSame(course_gate::PROCTORED, $decision->reason);
        $this->assertSame('KEY-COURSE', $decision->keyid);
    }

    /**
     * The course page and the pages of its activities are proctored; so are pages of every kind of activity.
     */
    public function test_course_and_activity_pages(): void {
        $data = $this->setup_course();

        foreach (['course' => 'course-view-topics', 'incourse' => 'mod-forum-view'] as $layout => $pagetype) {
            $decision = $this->evaluate($data, ['layout' => $layout, 'pagetype' => $pagetype, 'activitytype' => '']);
            $this->assertTrue($decision->proctored, $layout);
        }
        foreach (['page', 'book', 'url', 'resource', 'forum', 'assign', 'scorm'] as $activity) {
            $this->assertTrue($this->evaluate($data, ['activitytype' => $activity])->proctored, $activity);
        }
    }

    /**
     * Nothing is looked at while the integration switch is off, whatever else is true.
     */
    public function test_switch_off(): void {
        $data = $this->setup_course();
        config::set_vendor_enabled(false);

        $decision = $this->evaluate($data);

        $this->assertFalse($decision->proctored);
        $this->assertSame(course_gate::VENDOR_DISABLED, $decision->reason);
    }

    /**
     * Pages that are not pages of a course, and layouts that are not learning pages.
     *
     * @dataProvider not_course_pages
     * @param array $override
     */
    public function test_not_a_course_page(array $override): void {
        $data = $this->setup_course();
        $override += ['layout' => 'incourse'];
        if (($override['courseid'] ?? null) === 'course') {
            $override['courseid'] = (int) $data->course->id;
        }

        $decision = $this->evaluate($data, $override);

        $this->assertFalse($decision->proctored);
        $this->assertSame(course_gate::NOT_A_COURSE_PAGE, $decision->reason);
    }

    /**
     * Pages that are not proctored for lack of a course or of a learning layout.
     *
     * @return array
     */
    public static function not_course_pages(): array {
        return [
            'the site home page' => [['courseid' => 1, 'layout' => 'frontpage']],
            'the site home course with a course layout' => [['courseid' => 1, 'layout' => 'course']],
            'no course at all' => [['courseid' => 0]],
            'the dashboard' => [['courseid' => 1, 'layout' => 'mydashboard']],
            'a standard page of the course' => [['courseid' => 'course', 'layout' => 'standard']],
            'a report page of the course' => [['courseid' => 'course', 'layout' => 'report']],
            'the admin pages' => [['courseid' => 'course', 'layout' => 'admin']],
            'an embedded page' => [['courseid' => 'course', 'layout' => 'embedded']],
            'a pop-up' => [['courseid' => 'course', 'layout' => 'popup']],
            'a printable page' => [['courseid' => 'course', 'layout' => 'print']],
            'the secure layout' => [['courseid' => 'course', 'layout' => 'secure']],
        ];
    }

    /**
     * D5: never on a quiz activity, however the page is recognised; other activities are untouched.
     *
     * @dataProvider quiz_pages
     * @param array $override
     */
    public function test_quiz_pages_are_never_proctored(array $override): void {
        $data = $this->setup_course();

        $decision = $this->evaluate($data, $override);

        $this->assertFalse($decision->proctored);
        $this->assertSame(course_gate::QUIZ_PAGE, $decision->reason);
    }

    /**
     * Quiz pages.
     *
     * @return array
     */
    public static function quiz_pages(): array {
        return [
            'the quiz page' => [['activitytype' => 'quiz', 'pagetype' => 'mod-quiz-view']],
            'an attempt' => [['activitytype' => 'quiz', 'pagetype' => 'mod-quiz-attempt']],
            'a review' => [['activitytype' => 'quiz', 'pagetype' => 'mod-quiz-review']],
            'the summary' => [['activitytype' => 'quiz', 'pagetype' => 'mod-quiz-summary']],
            'a quiz with an unusual page type' => [['activitytype' => 'quiz', 'pagetype' => 'something-else']],
            'the list of the quizzes of a course' => [['activitytype' => '', 'pagetype' => 'mod-quiz-index']],
            'a quiz report' => [['activitytype' => '', 'pagetype' => 'mod-quiz-report-grading']],
        ];
    }

    /**
     * Not for visitors, guests, administrators who are logged in as somebody, role-switched people, or administrators.
     *
     * @dataProvider not_real_learners
     * @param array $override
     * @param string $reason
     */
    public function test_people_who_are_not_real_learners(array $override, string $reason): void {
        $data = $this->setup_course();

        $decision = $this->evaluate($data, $override);

        $this->assertFalse($decision->proctored);
        $this->assertSame($reason, $decision->reason);
    }

    /**
     * People who are not proctored.
     *
     * @return array
     */
    public static function not_real_learners(): array {
        return [
            'a visitor or a guest' => [['loggedin' => false, 'userid' => 0], course_gate::NOT_LOGGED_IN],
            'logged in as the learner' => [['loggedinas' => true], course_gate::LOGGED_IN_AS],
            'switched to another role' => [['roleswitched' => true], course_gate::ROLE_SWITCHED],
            'a site administrator' => [['siteadmin' => true], course_gate::SITE_ADMIN],
        ];
    }

    /**
     * No Proctor Key in the course's settings: no proctoring, and a key on another course does not count.
     */
    public function test_a_course_without_a_key(): void {
        $data = $this->setup_course(['key' => '']);
        $other = $this->getDataGenerator()->create_course();
        (new course_key_repository())->set((int) $other->id, 'KEY-OTHER');

        $decision = $this->evaluate($data);

        $this->assertFalse($decision->proctored);
        $this->assertSame(course_gate::NO_COURSE_KEY, $decision->reason);
    }

    /**
     * No App Key, or one that is stored but cannot be read, is no key at all.
     */
    public function test_no_usable_app_key(): void {
        $data = $this->setup_course();

        config::set_app_key('');
        $this->assertSame(course_gate::NO_APP_KEY, $this->evaluate($data)->reason);

        set_config('appkey', 'not-an-encrypted-value', 'tool_courseproctoring');
        $decision = $this->evaluate($data);
        $this->assertDebuggingCalled(null, DEBUG_DEVELOPER);
        $this->assertFalse($decision->proctored);
        $this->assertSame(course_gate::NO_APP_KEY, $decision->reason);
    }

    /**
     * Without a Proctor Key the App Key is not even read: the cheap lookup comes first.
     */
    public function test_the_course_key_is_checked_before_the_app_key(): void {
        $data = $this->setup_course(['key' => '']);
        set_config('appkey', 'not-an-encrypted-value', 'tool_courseproctoring');

        $decision = $this->evaluate($data);

        $this->assertSame(course_gate::NO_COURSE_KEY, $decision->reason);
        // No "could not be decrypted" debugging: the unreadable key was never touched.
    }

    /**
     * Only people who are actively enrolled and hold the permission are proctored.
     */
    public function test_only_enrolled_learners_with_the_permission(): void {
        global $DB;
        $data = $this->setup_course();
        $generator = $this->getDataGenerator();
        $course = $data->course;
        $notenrolled = $generator->create_user();
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $suspended = $generator->create_and_enrol($course, 'student', null, 'manual', 0, 0, ENROL_USER_SUSPENDED);
        $ended = $generator->create_and_enrol($course, 'student', null, 'manual', time() - 7200, time() - 3600);
        $future = $generator->create_and_enrol($course, 'student', null, 'manual', time() + 3600);

        $people = [
            'not enrolled' => $notenrolled,
            'teacher' => $teacher,
            'suspended' => $suspended,
            'enrolment ended' => $ended,
            'enrolment not started' => $future,
        ];
        foreach ($people as $who => $user) {
            $decision = $this->evaluate($data, ['userid' => (int) $user->id]);
            $this->assertFalse($decision->proctored, $who);
            $this->assertSame(course_gate::NOT_A_LEARNER, $decision->reason, $who);
        }

        // The permission is what counts: a student role that lost it is not proctored; another role that has it is.
        $studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
        $context = \context_course::instance($course->id);
        assign_capability('tool/courseproctoring:beproctored', CAP_PROHIBIT, $studentrole->id, $context->id, true);
        $this->assertSame(course_gate::NOT_A_LEARNER, $this->evaluate($data)->reason, 'permission taken away');

        assign_capability('tool/courseproctoring:beproctored', CAP_INHERIT, $studentrole->id, $context->id, true);
        $this->assertTrue($this->evaluate($data)->proctored, 'permission back');
    }

    /**
     * The permission belongs to the student role by default, and to no other.
     */
    public function test_default_permission(): void {
        global $DB;
        $this->resetAfterTest();
        $system = \context_system::instance();
        $allowed = static function (string $shortname) use ($DB, $system): bool {
            $roleid = $DB->get_field('role', 'id', ['shortname' => $shortname], MUST_EXIST);
            $permission = $DB->get_field('role_capabilities', 'permission', [
                'roleid' => $roleid, 'contextid' => $system->id, 'capability' => 'tool/courseproctoring:beproctored',
            ]);
            return (int) $permission === CAP_ALLOW;
        };

        $this->assertTrue($allowed('student'));
        foreach (['editingteacher', 'teacher', 'manager', 'guest', 'user'] as $other) {
            $this->assertFalse($allowed($other), $other);
        }
        $capability = get_capability_info('tool/courseproctoring:beproctored');
        $this->assertSame('read', $capability->captype);
        $this->assertEquals(CONTEXT_COURSE, $capability->contextlevel);
    }

    /**
     * A session that is already running continues with its own Key ID, even if the course's Key ID is changed.
     * When the course's Key ID is removed, the course stops being proctored.
     */
    public function test_a_running_session_keeps_its_key(): void {
        $data = $this->setup_course();
        $repo = new session_repository();
        $repo->ensure_started($data->user->id, $data->course->id, 'KEY-COURSE');

        $decision = $this->evaluate($data);
        $this->assertSame(course_gate::CONTINUING, $decision->reason);
        $this->assertSame('KEY-COURSE', $decision->keyid);

        (new course_key_repository())->set((int) $data->course->id, 'KEY-CHANGED');
        $this->assertSame('KEY-COURSE', $this->evaluate($data)->keyid, 'the session keeps the key it started with');

        (new course_key_repository())->set((int) $data->course->id, '');
        $decision = $this->evaluate($data);
        $this->assertFalse($decision->proctored);
        $this->assertSame(course_gate::NO_COURSE_KEY, $decision->reason);
    }

    /**
     * A session that is closed or failed is never started again.
     */
    public function test_finished_sessions_are_never_restarted(): void {
        $data = $this->setup_course();
        $repo = new session_repository();
        $session = $repo->ensure_started($data->user->id, $data->course->id, 'KEY-COURSE');

        $repo->mark_closed($session->id);
        $decision = $this->evaluate($data);
        $this->assertFalse($decision->proctored);
        $this->assertSame(course_gate::SESSION_ENDED, $decision->reason);

        $other = $this->getDataGenerator()->create_and_enrol($data->course, 'student');
        $second = $repo->ensure_started($other->id, $data->course->id, 'KEY-COURSE');
        $repo->mark_failed($second->id, 'HTTP 400');
        $this->assertSame(course_gate::SESSION_ENDED, $this->evaluate($data, ['userid' => (int) $other->id])->reason);
    }

    /**
     * A learner who has completed the course is not proctored: not at all if they never started, and not any more
     * if their session is still waiting to be closed.
     */
    public function test_a_completed_course(): void {
        $data = $this->setup_course();
        $this->complete_course($data->user->id, $data->course->id);

        $decision = $this->evaluate($data);
        $this->assertFalse($decision->proctored);
        $this->assertSame(course_gate::COURSE_COMPLETE, $decision->reason);

        // A session that is still open (its close is waiting for a retry) stops showing the widget too.
        $other = $this->getDataGenerator()->create_and_enrol($data->course, 'student');
        (new session_repository())->ensure_started($other->id, $data->course->id, 'KEY-COURSE');
        $this->assertSame(course_gate::CONTINUING, $this->evaluate($data, ['userid' => (int) $other->id])->reason);
        $this->complete_course($other->id, $data->course->id);
        $this->assertSame(course_gate::COURSE_COMPLETE, $this->evaluate($data, ['userid' => (int) $other->id])->reason);
    }

    /**
     * A course without completion tracking is proctored until the learner leaves it.
     */
    public function test_a_course_without_completion_tracking(): void {
        $data = $this->setup_course(['completion' => false]);
        $this->complete_course($data->user->id, $data->course->id);

        $this->assertTrue($this->evaluate($data)->proctored);
    }

    /**
     * Proctoring is over for a learner whose session has ended or who has completed the course, and only then.
     */
    public function test_is_over(): void {
        $data = $this->setup_course();
        $gate = new course_gate();
        $repo = new session_repository();
        $userid = (int) $data->user->id;
        $courseid = (int) $data->course->id;

        $this->assertFalse($gate->is_over($userid, $courseid), 'nothing has started');
        $session = $repo->ensure_started($userid, $courseid, 'KEY-COURSE');
        $this->assertFalse($gate->is_over($userid, $courseid), 'a running session');

        $this->complete_course($userid, $courseid);
        $this->assertTrue($gate->is_over($userid, $courseid), 'completed, the session not yet closed');

        $repo->mark_closed($session->id);
        $this->assertTrue($gate->is_over($userid, $courseid), 'completed and closed');
    }

    /**
     * A session that is closed or failed is over, completed or not, and one person's state is not another's.
     */
    public function test_is_over_for_finished_sessions(): void {
        $data = $this->setup_course();
        $gate = new course_gate();
        $repo = new session_repository();
        $courseid = (int) $data->course->id;
        $second = $this->getDataGenerator()->create_and_enrol($data->course, 'student');
        $third = $this->getDataGenerator()->create_and_enrol($data->course, 'student');

        $repo->mark_closed($repo->ensure_started($data->user->id, $courseid, 'KEY-COURSE')->id);
        $repo->mark_failed($repo->ensure_started($second->id, $courseid, 'KEY-COURSE')->id, 'HTTP 400');
        $repo->ensure_started($third->id, $courseid, 'KEY-COURSE');

        $this->assertTrue($gate->is_over((int) $data->user->id, $courseid), 'closed');
        $this->assertTrue($gate->is_over((int) $second->id, $courseid), 'failed');
        $this->assertFalse($gate->is_over((int) $third->id, $courseid), 'still running');
        $elsewhere = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->assertFalse($gate->is_over((int) $data->user->id, (int) $elsewhere->id), 'another course');
    }

    /**
     * A course without completion tracking is never completed.
     */
    public function test_is_over_without_completion_tracking(): void {
        $data = $this->setup_course(['completion' => false]);
        $this->complete_course($data->user->id, $data->course->id);

        $this->assertFalse((new course_gate())->is_over((int) $data->user->id, (int) $data->course->id));
    }

    /**
     * What is_over() says always agrees with the rules: it is true exactly when the rules would refuse to start the widget
     * because the session ended or the course is completed.
     */
    public function test_is_over_agrees_with_the_rules(): void {
        $this->resetAfterTest();
        $this->enable_integration();
        $gate = new course_gate();
        $repo = new session_repository();
        $states = ['nothing started', 'running', 'running, completed', 'completed, never started', 'closed', 'failed'];

        foreach ($states as $state) {
            $data = $this->create_proctored_course();
            $userid = (int) $data->user->id;
            $courseid = (int) $data->course->id;
            $session = in_array($state, ['nothing started', 'completed, never started'], true)
                ? null : $repo->ensure_started($userid, $courseid, 'KEY-COURSE');
            if (str_contains($state, 'completed')) {
                $this->complete_course($userid, $courseid);
            }
            if ($state === 'closed') {
                $repo->mark_closed($session->id);
            }
            if ($state === 'failed') {
                $repo->mark_failed($session->id, 'HTTP 400');
            }

            $reason = $gate->evaluate($this->facts_for($data))->reason;
            $this->assertSame(
                in_array($reason, [course_gate::SESSION_ENDED, course_gate::COURSE_COMPLETE], true),
                $gate->is_over($userid, $courseid),
                $state . ' (the rules say: ' . $reason . ')'
            );
        }
    }

    /**
     * A stored session id that this plugin did not make is not used.
     */
    public function test_an_invalid_stored_session_id(): void {
        global $DB;
        $data = $this->setup_course();
        $session = (new session_repository())->ensure_started($data->user->id, $data->course->id, 'KEY-COURSE');
        $DB->set_field('tool_courseproctoring_session', 'sessionid', 'moodle_5_9', ['id' => $session->id]);

        $decision = $this->evaluate($data);

        $this->assertFalse($decision->proctored);
        $this->assertSame(course_gate::SESSION_ID_INVALID, $decision->reason);
    }

    /**
     * The gate neither writes anything nor starts a session: only the injector records one.
     */
    public function test_the_gate_writes_nothing(): void {
        global $DB;
        $data = $this->setup_course();

        $this->evaluate($data);
        $this->evaluate($data);

        $this->assertEquals(0, $DB->count_records('tool_courseproctoring_session'));
    }
}
