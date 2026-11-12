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

use core\hook\output\before_footer_html_generation;
use core\hook\output\before_standard_footer_html_generation;
use core_course\hook\before_course_deleted;
use tool_courseproctoring\local\config;
use tool_courseproctoring\local\course_key_repository;
use tool_courseproctoring\local\session_repository;
use tool_courseproctoring\local\vendor\vendor_factory;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/course_helper_trait.php');

/**
 * Tests for the footer hook: when the widget JavaScript is queued on a page of a course, and when it is not.
 *
 * @package    tool_courseproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_courseproctoring\hook_callbacks
 */
final class hook_callbacks_test extends \advanced_testcase {
    use course_helper_trait;

    /** @var \stdClass&object The fake vendor client. */
    private $vendor;

    #[\Override]
    protected function tearDown(): void {
        vendor_factory::set_override(null);
        parent::tearDown();
    }

    /**
     * A switched-on site and a learner looking at an activity page of a proctored course, with the global page set up
     * the way a script of Moodle would.
     *
     * @param array $options 'key' => '' for a course without a Proctor Key; 'layout', 'pagetype'
     * @return \stdClass see create_proctored_course()
     */
    private function setup_page(array $options = []): \stdClass {
        global $PAGE;
        $this->resetAfterTest();
        $this->enable_integration();
        $this->vendor = $this->create_fake_vendor();
        vendor_factory::set_override($this->vendor);

        $data = $this->create_proctored_course(['key' => $options['key'] ?? 'KEY-COURSE']);
        $this->setUser($data->user);
        $PAGE->set_url('/mod/page/view.php', ['id' => 1]);
        $PAGE->set_context(\context_course::instance($data->course->id));
        $PAGE->set_course($data->course);
        $PAGE->set_pagelayout($options['layout'] ?? 'incourse');
        $PAGE->set_pagetype($options['pagetype'] ?? 'mod-page-view');
        return $data;
    }

    /**
     * Run the callback as core_renderer::footer() would.
     */
    private function run_hook(): void {
        global $PAGE;
        hook_callbacks::before_footer_html_generation(new before_footer_html_generation($PAGE->get_renderer('core')));
    }

    /**
     * Queued calls for this plugin's module.
     *
     * @return string[]
     */
    private function widget_calls(): array {
        global $PAGE;
        $property = new \ReflectionProperty($PAGE->requires, 'amdjscode');
        return array_values(array_filter(
            array_filter($property->getValue($PAGE->requires)),
            static fn(string $code): bool => str_contains($code, 'tool_courseproctoring/proctor_widget')
        ));
    }

    /**
     * The happy path through the real hook: the widget is queued with the vendor URL, once per page view, and the
     * session is recorded once.
     */
    public function test_widget_is_added_and_the_session_recorded(): void {
        global $DB;
        $data = $this->setup_page();

        $this->run_hook();

        $calls = $this->widget_calls();
        $this->assertCount(1, $calls);
        $this->assertStringContainsString('vendor.example', $calls[0]);
        $session = (new session_repository())->get($data->user->id, $data->course->id);
        $this->assertNotNull($session);
        $this->assertStringContainsString($session->sessionid, $calls[0]);

        $this->run_hook();
        $this->run_hook();
        $this->assertCount(3, $this->widget_calls(), 'each page view queues the widget (each page is a new document)');
        $this->assertEquals(1, $DB->count_records('tool_courseproctoring_session'), 'but there is still one session');
        $this->assertCount(1, array_unique(array_map(static fn($c) => $c->sessionid, $this->vendor->contexts)));
    }

    /**
     * Nothing happens while the switch is off or there is no App Key, and no database row is made.
     */
    public function test_switch_off_or_no_app_key(): void {
        global $DB;
        $this->setup_page();

        config::set_vendor_enabled(false);
        $this->run_hook();
        config::set_vendor_enabled(true);
        config::set_app_key('');
        $this->run_hook();

        $this->assertSame([], $this->widget_calls());
        $this->assertSame([], $this->vendor->contexts);
        $this->assertEquals(0, $DB->count_records('tool_courseproctoring_session'));
    }

    /**
     * A course without a Proctor Key, a quiz page and a page outside a course are left alone.
     */
    public function test_pages_that_are_not_proctored(): void {
        global $DB, $PAGE;
        $this->setup_page(['key' => '']);
        $this->run_hook();
        $this->assertSame([], $this->widget_calls(), 'a course without a key');

        $PAGE->set_pagetype('mod-quiz-view');
        (new course_key_repository())->set((int) $PAGE->course->id, 'KEY-NOW');
        $this->run_hook();
        $this->assertSame([], $this->widget_calls(), 'a quiz page');

        $this->assertEquals(0, $DB->count_records('tool_courseproctoring_session'));
    }

    /**
     * Not for guests or visitors who are not logged in.
     */
    public function test_guests_and_visitors_are_ignored(): void {
        $this->setup_page();

        $this->setGuestUser();
        $this->run_hook();
        $this->setUser(0);
        $this->run_hook();

        $this->assertSame([], $this->widget_calls());
    }

    /**
     * Whatever goes wrong, the page itself is never broken: the failure is swallowed (and shown in developer debugging).
     */
    public function test_a_failure_never_breaks_the_page(): void {
        $this->setup_page();
        $this->vendor->throw = new \RuntimeException('vendor client exploded');

        $this->run_hook();

        $this->assertSame([], $this->widget_calls());
        $this->assertDebuggingCalled(null, DEBUG_DEVELOPER);
    }

    /**
     * The callback is registered for the hook that is dispatched for every layout, and NOT for the one that only some
     * theme templates dispatch; the course-deletion note is registered too.
     */
    public function test_hook_registration(): void {
        $manager = \core\di::get(\core\hook\manager::class);
        $isours = static fn(array $definition): bool => $definition['component'] === 'tool_courseproctoring';

        $callbacks = array_values(array_filter($manager->get_callbacks_for_hook(before_footer_html_generation::class), $isours));
        $this->assertCount(1, $callbacks);
        $this->assertSame(hook_callbacks::class . '::before_footer_html_generation', $callbacks[0]['callback']);

        $standard = $manager->get_callbacks_for_hook(before_standard_footer_html_generation::class);
        $this->assertEmpty(array_filter($standard, $isours), 'not registered for before_standard_footer_html_generation');

        $deleting = array_values(array_filter($manager->get_callbacks_for_hook(before_course_deleted::class), $isours));
        $this->assertCount(1, $deleting);
        $this->assertSame(hook_callbacks::class . '::before_course_deleted', $deleting[0]['callback']);
    }
}
