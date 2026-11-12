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
 * Tests for starting proctoring on a page: what is recorded, what the vendor client is given, and what is queued.
 *
 * @package    tool_courseproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_courseproctoring\local\widget_injector
 */
final class widget_injector_test extends \advanced_testcase {
    use \tool_courseproctoring\course_helper_trait;

    /** @var \stdClass&object The fake vendor client. */
    private $vendor;

    /**
     * A switched-on site, a proctored course and its learner, who is the current user.
     *
     * @param array $options see create_proctored_course()
     * @return \stdClass
     */
    private function setup_course(array $options = []): \stdClass {
        $this->resetAfterTest();
        $this->enable_integration();
        $this->vendor = $this->create_fake_vendor();
        $data = $this->create_proctored_course($options);
        $this->setUser($data->user);
        return $data;
    }

    /**
     * A page of a course.
     *
     * @param \stdClass $course
     * @param string $layout
     * @param string $pagetype
     * @param \stdClass|null $module
     * @return \moodle_page
     */
    private function page(
        \stdClass $course,
        string $layout = 'incourse',
        string $pagetype = 'mod-page-view',
        ?\stdClass $module = null
    ): \moodle_page {
        $page = new \moodle_page();
        $page->set_url('/course/view.php', ['id' => $course->id]);
        $page->set_context(\context_course::instance($course->id));
        $page->set_course($course);
        $page->set_pagelayout($layout);
        $page->set_pagetype($pagetype);
        if ($module) {
            $page->set_cm(get_fast_modinfo($course)->get_cm($module->cmid), $course);
        }
        return $page;
    }

    /**
     * An injector that uses the fake vendor.
     *
     * @return widget_injector
     */
    private function injector(): widget_injector {
        return new widget_injector(null, null, $this->vendor);
    }

    /**
     * The widget calls queued on a page (js_call_amd() stores them in a protected array).
     *
     * @param \moodle_page $page
     * @return string[]
     */
    private function widget_calls(\moodle_page $page): array {
        $property = new \ReflectionProperty($page->requires, 'amdjscode');
        return array_values(array_filter(
            array_filter($property->getValue($page->requires)),
            static fn(string $code): bool => str_contains($code, 'tool_courseproctoring/proctor_widget')
        ));
    }

    /**
     * The happy path: the widget is queued with the vendor URL, and the ledger records the session.
     */
    public function test_widget_is_queued_and_the_session_recorded(): void {
        global $CFG;
        $data = $this->setup_course();
        $page = $this->page($data->course);

        $decision = $this->injector()->inject($page);

        $this->assertTrue($decision->proctored);
        $calls = $this->widget_calls($page);
        $this->assertCount(1, $calls);
        $this->assertStringContainsString('amd.init(', $calls[0]);
        $this->assertMatchesRegularExpression(
            '/amd\.init\(".+", ' . (int) $data->course->id . '\)/',
            $calls[0],
            'the course id follows the address'
        );

        $session = (new session_repository())->get($data->user->id, $data->course->id);
        $this->assertNotNull($session);
        $this->assertSame('KEY-COURSE', $session->keyid);
        $this->assertTrue(session_id::is_valid($session->sessionid));
        $this->assertEquals(session_repository::STATUS_ACTIVE, $session->status);
        $this->assertStringContainsString($session->sessionid, $calls[0], 'the address carries the stored session id');

        // What the vendor client was given.
        $context = $this->vendor->contexts[0];
        $this->assertSame('KEY-COURSE', $context->keyid);
        $this->assertSame($session->sessionid, $context->sessionid);
        $this->assertSame((string) $data->course->id, $context->courseid, 'the real course id');
        $this->assertSame($data->course->fullname, $context->coursename);
        $this->assertSame((string) $data->user->id, $context->userid);
        $this->assertSame($data->user->firstname, $context->firstname);
        $this->assertSame($data->user->lastname, $context->lastname);
        $this->assertSame($data->user->email, $context->email);
        $this->assertSame($data->user->username, $context->username);
        $this->assertSame($CFG->wwwroot . '/', $context->redirecturl);
        $this->assertSame('test-app-key-0123456789', $this->vendor->appkeys[0]);
    }

    /**
     * One session per learner per course: every page, tab and visit gets the same session id and Key ID, and the ledger
     * keeps its one row and its start time.
     */
    public function test_every_page_uses_the_same_session(): void {
        global $DB;
        $data = $this->setup_course();

        $this->injector()->inject($this->page($data->course, 'course', 'course-view-topics'));
        $first = (new session_repository())->get($data->user->id, $data->course->id);
        $this->injector()->inject($this->page($data->course));
        (new course_key_repository())->set((int) $data->course->id, 'KEY-CHANGED');
        $this->injector()->inject($this->page($data->course));

        $this->assertEquals(1, $DB->count_records('tool_courseproctoring_session'));
        $again = (new session_repository())->get($data->user->id, $data->course->id);
        $this->assertEquals($first->id, $again->id);
        $this->assertEquals($first->timecreated, $again->timecreated);
        $this->assertCount(1, array_unique(array_map(static fn($c) => $c->sessionid, $this->vendor->contexts)));
        $keys = array_map(static fn($context) => $context->keyid, $this->vendor->contexts);
        $this->assertSame(['KEY-COURSE', 'KEY-COURSE', 'KEY-COURSE'], $keys);
    }

    /**
     * Pages that are not proctored cause no ledger row and no vendor call.
     */
    public function test_pages_that_are_not_proctored_leave_no_trace(): void {
        global $DB;
        $data = $this->setup_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $data->course->id]);
        $pages = [
            'a quiz page' => $this->page($data->course, 'incourse', 'mod-quiz-view', $quiz),
            'the dashboard' => $this->page(get_course(SITEID), 'mydashboard', 'my-index'),
            'a report page' => $this->page($data->course, 'report', 'report-log-index'),
        ];

        foreach ($pages as $name => $page) {
            $this->assertFalse($this->injector()->inject($page)->proctored, $name);
            $this->assertSame([], $this->widget_calls($page), $name);
        }
        $this->assertEquals(0, $DB->count_records('tool_courseproctoring_session'));
        $this->assertSame([], $this->vendor->contexts, 'the vendor client was not even asked');
    }

    /**
     * Teachers and administrators are never proctored, and leave no trace.
     */
    public function test_teachers_and_administrators(): void {
        global $DB;
        $data = $this->setup_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($data->course, 'editingteacher');

        foreach ([$teacher, get_admin()] as $user) {
            $this->setUser($user);
            $page = $this->page($data->course);
            $this->assertFalse($this->injector()->inject($page)->proctored);
            $this->assertSame([], $this->widget_calls($page));
        }
        $this->assertEquals(0, $DB->count_records('tool_courseproctoring_session'));
    }

    /**
     * An administrator who is "logged in as" a learner does not start the learner's session with their own camera.
     */
    public function test_logged_in_as_a_learner_starts_nothing(): void {
        global $DB;
        $data = $this->setup_course();
        $this->setAdminUser();
        \core\session\manager::loginas($data->user->id, \context_system::instance());
        $page = $this->page($data->course);

        $decision = $this->injector()->inject($page);

        $this->assertFalse($decision->proctored);
        $this->assertSame(course_gate::LOGGED_IN_AS, $decision->reason);
        $this->assertSame([], $this->widget_calls($page));
        $this->assertEquals(0, $DB->count_records('tool_courseproctoring_session'), 'and no session is recorded for the learner');
    }

    /**
     * The session is recorded before the vendor client is asked; if the client fails, nothing is queued and the error
     * reaches the caller (the footer hook), which swallows it.
     */
    public function test_a_failing_vendor_client(): void {
        $data = $this->setup_course();
        $this->vendor->throw = new \RuntimeException('vendor client exploded');
        $page = $this->page($data->course);

        try {
            $this->injector()->inject($page);
            $this->fail('the failure should reach the caller');
        } catch (\RuntimeException $e) {
            $this->assertSame('vendor client exploded', $e->getMessage());
        }
        $this->assertSame([], $this->widget_calls($page));
    }

    /**
     * A ledger row with an id this plugin did not make is not used, and nothing is queued.
     */
    public function test_a_stored_session_id_that_is_not_valid(): void {
        global $DB;
        $data = $this->setup_course();
        $session = (new session_repository())->ensure_started($data->user->id, $data->course->id, 'KEY-COURSE');
        $DB->set_field('tool_courseproctoring_session', 'sessionid', 'moodle_5_9', ['id' => $session->id]);
        $page = $this->page($data->course);

        $decision = $this->injector()->inject($page);

        $this->assertFalse($decision->proctored);
        $this->assertSame([], $this->widget_calls($page));
        $this->assertSame([], $this->vendor->contexts);
    }
}
