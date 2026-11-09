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

use core\hook\output\before_footer_html_generation;
use core\hook\output\before_standard_footer_html_generation;
use tool_quizproctoring\local\config;
use tool_quizproctoring\local\quiz_key_repository;
use tool_quizproctoring\local\session_repository;
use tool_quizproctoring\local\vendor\vendor_factory;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/attempt_helper_trait.php');

/**
 * Tests for the footer hook: when the widget JavaScript is queued on a quiz attempt page, and when it is not.
 *
 * @package    tool_quizproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_quizproctoring\hook_callbacks
 * @covers     \tool_quizproctoring\local\widget_injector
 */
final class hook_callbacks_test extends \advanced_testcase {
    use attempt_helper_trait;

    /** @var \stdClass&object The fake vendor client. */
    private $vendor;

    #[\Override]
    protected function tearDown(): void {
        global $DB;
        vendor_factory::set_override(null);
        $dbman = $DB->get_manager();
        foreach (['local_techstack_course', 'local_techstack'] as $name) {
            if ($dbman->table_exists($name)) {
                $dbman->drop_table(new \xmldb_table($name));
            }
        }
        parent::tearDown();
    }

    /**
     * A fully configured site and a student's started attempt, with the page set up as mod/quiz/attempt.php would.
     *
     * @param array $options 'key' => false to leave the quiz without a Key ID; 'pagetype', 'layout'
     * @return \stdClass see create_quiz_attempt()
     */
    private function setup_attempt_page(array $options = []): \stdClass {
        global $PAGE;
        $this->resetAfterTest();
        $this->enable_integration();

        $this->vendor = $this->create_fake_vendor();
        vendor_factory::set_override($this->vendor);

        $data = $this->create_quiz_attempt();
        if ($options['key'] ?? true) {
            (new quiz_key_repository())->set((int) $data->cm->id, 'KEY-QUIZ');
        }

        $this->setUser($data->user);
        $PAGE->set_url('/mod/quiz/attempt.php', ['attempt' => $data->attempt->id, 'cmid' => $data->cm->id]);
        $PAGE->set_pagetype($options['pagetype'] ?? 'mod-quiz-attempt');
        $PAGE->set_pagelayout($options['layout'] ?? 'incourse');
        return $data;
    }

    /**
     * Run the callback as core_renderer::footer() would.
     */
    private function run_hook(): void {
        global $OUTPUT;
        hook_callbacks::before_footer_html_generation(new before_footer_html_generation($OUTPUT));
    }

    /**
     * The JavaScript calls queued on the page so far (js_call_amd() stores them in a protected array).
     *
     * @return string[]
     */
    private function queued_js(): array {
        global $PAGE;
        $property = new \ReflectionProperty($PAGE->requires, 'amdjscode');
        return array_values(array_filter($property->getValue($PAGE->requires)));
    }

    /**
     * Queued calls for this plugin's module.
     *
     * @return string[]
     */
    private function widget_calls(): array {
        return array_values(array_filter(
            $this->queued_js(),
            static fn(string $code): bool => str_contains($code, 'tool_quizproctoring/proctor_widget')
        ));
    }

    /**
     * The happy path: the widget is queued with the vendor URL, and a ledger row records the session.
     */
    public function test_widget_is_added_and_the_session_recorded(): void {
        global $CFG;
        $data = $this->setup_attempt_page();

        $this->run_hook();

        $calls = $this->widget_calls();
        $this->assertCount(1, $calls);
        $this->assertStringContainsString('amd.init(', $calls[0]);
        $this->assertStringContainsString('vendor.example', $calls[0]);

        $session = (new session_repository())->get_by_attempt($data->attempt->id);
        $this->assertNotNull($session);
        $this->assertSame('KEY-QUIZ', $session->keyid);
        $this->assertEquals($data->user->id, $session->userid);
        $this->assertEquals($data->course->id, $session->courseid);
        $this->assertEquals($data->cm->id, $session->cmid);
        $this->assertEquals($data->quiz->id, $session->quizid);
        $this->assertEquals(session_repository::STATUS_ACTIVE, $session->status);

        // What the vendor client was given.
        $context = $this->vendor->contexts[0];
        $this->assertSame('KEY-QUIZ', $context->keyid);
        $this->assertSame((string) $data->attempt->id, $context->sessionid, 'the session id is the attempt id');
        $this->assertSame((string) $data->quiz->id, $context->quizid);
        $this->assertSame((string) $data->user->id, $context->userid);
        $this->assertSame($data->user->firstname, $context->firstname);
        $this->assertSame($data->user->lastname, $context->lastname);
        $this->assertSame($data->user->email, $context->email);
        $this->assertSame($data->user->username, $context->username);
        $this->assertSame($data->course->fullname, $context->coursename);
        $this->assertSame($CFG->wwwroot . '/', $context->redirecturl);
        $this->assertSame('test-app-key-0123456789', $this->vendor->appkeys[0]);
    }

    /**
     * Idempotent across question pages and reloads: every page view queues the widget (each page is a fresh
     * document) but there is still exactly one session row, with an unchanged start time.
     */
    public function test_repeated_requests_keep_one_session(): void {
        global $DB;
        $data = $this->setup_attempt_page();

        $this->run_hook();
        $first = (new session_repository())->get_by_attempt($data->attempt->id);
        $this->run_hook();
        $this->run_hook();
        $again = (new session_repository())->get_by_attempt($data->attempt->id);

        $this->assertCount(3, $this->widget_calls());
        $this->assertEquals(1, $DB->count_records('tool_quizproctoring_session'));
        $this->assertEquals($first->id, $again->id);
        $this->assertEquals($first->timecreated, $again->timecreated);
        $this->assertSame('KEY-QUIZ', $again->keyid);
    }

    /**
     * A Key ID changed during the attempt does not change the session: it continues with its own Key ID. When the quiz's
     * Key ID is removed, the quiz stops being proctored.
     */
    public function test_session_continues_with_its_original_key(): void {
        $data = $this->setup_attempt_page();
        $this->run_hook();

        (new quiz_key_repository())->set((int) $data->cm->id, 'KEY-CHANGED');
        $this->run_hook();
        $this->run_hook();

        $this->assertSame(['KEY-QUIZ', 'KEY-QUIZ', 'KEY-QUIZ'], array_map(
            static fn($context) => $context->keyid,
            $this->vendor->contexts
        ));

        (new quiz_key_repository())->set((int) $data->cm->id, '');
        $this->run_hook();
        $this->assertCount(3, $this->widget_calls(), 'no widget once the quiz has no Key ID');
    }

    /**
     * The key requirement: a quiz without a Key ID is not proctored and nothing is recorded.
     */
    public function test_quiz_without_key_is_not_proctored(): void {
        global $DB;
        $this->setup_attempt_page(['key' => false]);

        $this->run_hook();

        $this->assertSame([], $this->widget_calls());
        $this->assertEquals(0, $DB->count_records('tool_quizproctoring_session'));
        $this->assertSame([], $this->vendor->contexts, 'the vendor client was not even asked');
    }

    /**
     * Nothing happens on any other page.
     */
    public function test_other_page_types_are_ignored(): void {
        global $DB;
        foreach (['mod-quiz-view', 'mod-quiz-summary', 'mod-quiz-review', 'course-view-topics', 'my-index'] as $pagetype) {
            $this->setup_attempt_page(['pagetype' => $pagetype]);
            $this->run_hook();
            $this->assertSame([], $this->widget_calls(), $pagetype);
            $this->assertEquals(0, $DB->count_records('tool_quizproctoring_session'), $pagetype);
        }
    }

    /**
     * Not for guests or visitors who are not logged in.
     */
    public function test_guests_and_visitors_are_ignored(): void {
        $this->setup_attempt_page();

        $this->setGuestUser();
        $this->run_hook();
        $this->setUser(0);
        $this->run_hook();

        $this->assertSame([], $this->widget_calls());
    }

    /**
     * Not for somebody else looking at a student's attempt (a teacher, an admin).
     */
    public function test_attempt_of_another_user_is_ignored(): void {
        global $DB;
        $this->setup_attempt_page();
        $this->setAdminUser();

        $this->run_hook();

        $this->assertSame([], $this->widget_calls());
        $this->assertEquals(0, $DB->count_records('tool_quizproctoring_session'));
    }

    /**
     * Preview attempts and attempts that are no longer in progress are not proctored.
     */
    public function test_preview_and_finished_attempts_are_ignored(): void {
        global $DB;
        $data = $this->setup_attempt_page();

        $DB->set_field('quiz_attempts', 'preview', 1, ['id' => $data->attempt->id]);
        $this->run_hook();
        $this->assertSame([], $this->widget_calls(), 'preview');

        $DB->set_field('quiz_attempts', 'preview', 0, ['id' => $data->attempt->id]);
        $DB->set_field('quiz_attempts', 'state', 'finished', ['id' => $data->attempt->id]);
        $this->run_hook();
        $this->assertSame([], $this->widget_calls(), 'finished');
        $this->assertEquals(0, $DB->count_records('tool_quizproctoring_session'));
    }

    /**
     * Integration off, or no App Key: nothing is proctored and the vendor is not asked for anything.
     */
    public function test_switch_off_or_no_app_key(): void {
        $this->setup_attempt_page();

        config::set_vendor_enabled(false);
        $this->run_hook();
        config::set_vendor_enabled(true);
        config::set_app_key('');
        $this->run_hook();

        $this->assertSame([], $this->widget_calls());
        $this->assertSame([], $this->vendor->contexts);
    }

    /**
     * The widget does not depend on the page layout: secure-window and Safe Exam Browser quizzes use the "secure"
     * layout, whose template never renders standard_footer_html().
     */
    public function test_works_on_the_secure_layout(): void {
        $this->setup_attempt_page(['layout' => 'secure']);
        $this->run_hook();
        $this->assertCount(1, $this->widget_calls());
    }

    /**
     * The attempt id can come from the request when the page URL is not set.
     */
    public function test_attempt_id_falls_back_to_the_request(): void {
        global $PAGE;
        $data = $this->setup_attempt_page();
        $PAGE->set_url('/mod/quiz/attempt.php');
        $_GET['attempt'] = (string) $data->attempt->id;

        $this->run_hook();
        unset($_GET['attempt']);

        $this->assertCount(1, $this->widget_calls());
    }

    /**
     * No attempt id at all: nothing happens.
     */
    public function test_missing_attempt_id(): void {
        global $PAGE;
        $this->setup_attempt_page();
        $PAGE->set_url('/mod/quiz/attempt.php');

        $this->run_hook();

        $this->assertSame([], $this->widget_calls());
    }

    /**
     * Whatever goes wrong, the quiz page itself is never broken.
     */
    public function test_a_failure_never_breaks_the_page(): void {
        global $DB;
        $data = $this->setup_attempt_page();
        $this->vendor->throw = new \RuntimeException('vendor client exploded');

        $this->run_hook();

        $this->assertSame([], $this->widget_calls());
        $this->assertDebuggingCalled(null, DEBUG_DEVELOPER);
        $this->assertNotFalse($DB->get_record('quiz_attempts', ['id' => $data->attempt->id]));
    }

    /**
     * An unknown attempt id is ignored.
     */
    public function test_unknown_attempt(): void {
        global $PAGE;
        $this->setup_attempt_page();
        $PAGE->set_url('/mod/quiz/attempt.php', ['attempt' => 999999]);

        $this->run_hook();

        $this->assertSame([], $this->widget_calls());
    }

    /**
     * The plugin does not depend on any other proctoring set-up on the site. Tables shaped like an older integration's,
     * holding a key for this very course and quiz, change nothing: the attempt is proctored exactly as without them.
     */
    public function test_other_proctoring_setups_have_no_effect(): void {
        global $DB;
        $this->preventResetByRollback();
        $data = $this->setup_attempt_page();

        $dbman = $DB->get_manager();
        foreach (['local_techstack_course' => 'courseid', 'local_techstack' => 'cmid'] as $name => $column) {
            $table = new \xmldb_table($name);
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field($column, XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('customtext', XMLDB_TYPE_TEXT);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $dbman->create_table($table);
        }
        $DB->insert_record('local_techstack_course', ['courseid' => $data->course->id, 'customtext' => 'KEY-OTHER-COURSE']);
        $DB->insert_record('local_techstack', ['cmid' => $data->cm->id, 'customtext' => 'KEY-OTHER-QUIZ']);

        $this->run_hook();

        $this->assertCount(1, $this->widget_calls(), 'proctored although the other tables hold keys for this course and quiz');
        $session = (new session_repository())->get_by_attempt($data->attempt->id);
        $this->assertNotNull($session);
        $this->assertSame('KEY-QUIZ', $session->keyid, 'this plugin\'s own Key ID is used, never the other tables\' keys');
    }

    /**
     * The callback is registered for the hook that is dispatched for every layout, and NOT for the one that only
     * some theme templates dispatch.
     */
    public function test_hook_registration(): void {
        $manager = \core\di::get(\core\hook\manager::class);
        $isours = static fn(array $callback): bool => str_contains(json_encode($callback), 'tool_quizproctoring');

        $callbacks = $manager->get_callbacks_for_hook(before_footer_html_generation::class);
        $this->assertNotEmpty(array_filter($callbacks, $isours), 'registered for before_footer_html_generation');

        $standard = $manager->get_callbacks_for_hook(before_standard_footer_html_generation::class);
        $this->assertEmpty(array_filter($standard, $isours), 'not registered for before_standard_footer_html_generation');
    }
}
