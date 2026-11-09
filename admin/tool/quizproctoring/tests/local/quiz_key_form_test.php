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

/**
 * Tests for the "Proctor Key" field of the quiz settings form.
 *
 * @package    tool_quizproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_quizproctoring\local\quiz_key_form
 * @covers     ::tool_quizproctoring_coursemodule_standard_elements
 * @covers     ::tool_quizproctoring_coursemodule_validation
 * @covers     ::tool_quizproctoring_coursemodule_edit_post_actions
 */
final class quiz_key_form_test extends \advanced_testcase {
    /** @var bool Whether the page has been set up for the real quiz form in this test. */
    private bool $pageready = false;

    /**
     * The settings form of an existing quiz, built the way course/modedit.php builds it.
     *
     * @param \stdClass $course
     * @param \stdClass $quiz
     * @return \mod_quiz_mod_form
     */
    private function real_quiz_form(\stdClass $course, \stdClass $quiz): \mod_quiz_mod_form {
        global $CFG, $PAGE;
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/quiz/mod_form.php');

        $cm = get_coursemodule_from_id('quiz', $quiz->cmid, 0, false, MUST_EXIST);
        [, , , $data, $cw] = get_moduleinfo_data($cm, $course);
        // The page (and so the theme) can only be set up once per test.
        if (!$this->pageready) {
            $PAGE->set_course($course);
            $PAGE->set_url('/course/modedit.php', ['update' => $cm->id]);
            $PAGE->set_context(\context_module::instance($cm->id));
            $this->pageready = true;
        }
        return new \mod_quiz_mod_form($data, $cw->section, $cm, $course);
    }

    /**
     * A bare stand-in for a form of the given activity type (only get_current() is used).
     *
     * @param string $modulename
     * @param int $cmid
     * @return object
     */
    private function wrapper(string $modulename, int $cmid = 0): object {
        return new class ((object) ['modulename' => $modulename, 'coursemodule' => $cmid]) {
            /**
             * Constructor.
             *
             * @param \stdClass $current
             */
            public function __construct(
                /** @var \stdClass The form's current data. */
                private \stdClass $current
            ) {
            }

            /**
             * The form's current data.
             *
             * @return \stdClass
             */
            public function get_current(): \stdClass {
                return $this->current;
            }
        };
    }

    /**
     * An administrator sees the "Proctor Key Settings" section in the real quiz settings form, with the saved Key ID.
     */
    public function test_administrator_sees_the_field_in_the_real_quiz_form(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance(['course' => $course->id]);

        $form = $this->real_quiz_form($course, $quiz);
        $html = $form->render();
        $this->assertStringContainsString('name="' . quiz_key_form::FIELD . '"', $html);
        $this->assertStringContainsString('Proctor Key Settings', $html);
        $this->assertStringContainsString('Proctor Key', $html);
        $this->assertStringNotContainsString('value="KEY-SAVED"', $html);

        (new quiz_key_repository())->set((int) $quiz->cmid, 'KEY-SAVED');
        $html = $this->real_quiz_form($course, $quiz)->render();
        $this->assertMatchesRegularExpression('/name="' . quiz_key_form::FIELD . '"[^>]*value="KEY-SAVED"/', $html);
    }

    /**
     * Somebody who is not a site administrator (a teacher) does not get the field.
     */
    public function test_teacher_does_not_see_the_field(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $quiz = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance(['course' => $course->id]);
        $this->setUser($teacher);

        $html = $this->real_quiz_form($course, $quiz)->render();

        $this->assertStringNotContainsString(quiz_key_form::FIELD, $html);
        $this->assertStringNotContainsString('Proctor Key', $html);
    }

    /**
     * The field is added to quizzes only.
     */
    public function test_other_activities_do_not_get_the_field(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $mform = new \MoodleQuickForm('t', 'post', '');

        quiz_key_form::add_elements($this->wrapper('assign'), $mform);
        quiz_key_form::add_elements($this->wrapper('page'), $mform);
        $this->assertFalse($mform->elementExists(quiz_key_form::FIELD));

        quiz_key_form::add_elements($this->wrapper('quiz'), $mform);
        $this->assertTrue($mform->elementExists(quiz_key_form::FIELD));
    }

    /**
     * Moodle finds this plugin's callbacks (lib.php) for the three extension points of the activity form.
     */
    public function test_moodle_finds_the_callbacks(): void {
        foreach (['coursemodule_standard_elements', 'coursemodule_validation', 'coursemodule_edit_post_actions'] as $name) {
            $found = get_plugins_with_function($name, 'lib.php', true);
            $this->assertArrayHasKey('quizproctoring', $found['tool'] ?? [], $name);
            $this->assertSame('tool_quizproctoring_' . $name, $found['tool']['quizproctoring'], $name);
        }
    }

    /**
     * An invalid Key ID is reported on the field; an empty or valid one is accepted; non-quizzes are not checked.
     */
    public function test_validation(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $field = quiz_key_form::FIELD;

        $errors = quiz_key_form::validate($this->wrapper('quiz'), [$field => 'KEY BAD']);
        $this->assertArrayHasKey($field, $errors);
        $this->assertSame([], quiz_key_form::validate($this->wrapper('quiz'), [$field => 'KEY-OK']));
        $this->assertSame([], quiz_key_form::validate($this->wrapper('quiz'), [$field => '   ']));
        $this->assertSame([], quiz_key_form::validate($this->wrapper('quiz'), []));
        $this->assertSame([], quiz_key_form::validate($this->wrapper('page'), [$field => 'KEY BAD']));
        // Through the function Moodle calls.
        $this->assertArrayHasKey($field, tool_quizproctoring_coursemodule_validation($this->wrapper('quiz'), [$field => '<b>']));
    }

    /**
     * Saving through Moodle's own dispatcher: creates, changes and removes the Key ID, and logs it without the value.
     */
    public function test_saving_through_moodles_post_save_dispatcher(): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/modlib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance(['course' => $course->id]);
        $repo = new quiz_key_repository();
        $moduleinfo = static fn(?string $keyid): \stdClass => (object) array_filter([
            'modulename' => 'quiz', 'coursemodule' => $quiz->cmid, quiz_key_form::FIELD => $keyid,
        ], static fn($value) => $value !== null);

        $sink = $this->redirectEvents();
        $returned = plugin_extend_coursemodule_edit_post_actions($moduleinfo(' KEY-NEW '), $course);
        $this->assertSame('quiz', $returned->modulename, 'Moodle gets the module information back');
        $this->assertSame('KEY-NEW', $repo->get((int) $quiz->cmid));

        plugin_extend_coursemodule_edit_post_actions($moduleinfo('KEY-NEW'), $course);
        plugin_extend_coursemodule_edit_post_actions($moduleinfo('KEY-NEXT'), $course);
        plugin_extend_coursemodule_edit_post_actions($moduleinfo(''), $course);
        $this->assertSame('', $repo->get((int) $quiz->cmid));

        $events = array_values(array_filter(
            $sink->get_events(),
            static fn($event) => $event instanceof \tool_quizproctoring\event\quiz_key_updated
        ));
        $this->assertSame(['created', 'updated', 'deleted'], array_map(static fn($event) => $event->other['action'], $events));
        $this->assertEquals($quiz->cmid, $events[0]->contextinstanceid);
        $this->assertStringNotContainsString('KEY-NEW', json_encode($events[0]->get_data()));
        $this->assertStringNotContainsString('KEY-NEW', $events[0]->get_description());
    }

    /**
     * A form submitted without the field (for example by a user who could not see it) never changes a Key ID.
     */
    public function test_a_submission_without_the_field_changes_nothing(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance(['course' => $course->id]);
        $repo = new quiz_key_repository();
        $repo->set((int) $quiz->cmid, 'KEY-KEEP');

        quiz_key_form::save((object) ['modulename' => 'quiz', 'coursemodule' => $quiz->cmid]);
        $this->assertSame('KEY-KEEP', $repo->get((int) $quiz->cmid));

        // A teacher whose form carried the field anyway (forged request) cannot change it either.
        $teacher = $this->getDataGenerator()->create_and_enrol(get_course($quiz->course), 'editingteacher');
        $this->setUser($teacher);
        quiz_key_form::save((object) ['modulename' => 'quiz', 'coursemodule' => $quiz->cmid, quiz_key_form::FIELD => '']);
        quiz_key_form::save((object) ['modulename' => 'quiz', 'coursemodule' => $quiz->cmid, quiz_key_form::FIELD => 'KEY-FORGED']);
        $this->assertSame('KEY-KEEP', $repo->get((int) $quiz->cmid));
    }

    /**
     * Other activities and missing course module ids are ignored; an invalid value that arrives anyway is not stored.
     */
    public function test_save_ignores_other_activities_and_invalid_values(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance(['course' => $course->id]);
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $repo = new quiz_key_repository();

        quiz_key_form::save((object) ['modulename' => 'page', 'coursemodule' => $page->cmid, quiz_key_form::FIELD => 'KEY-X']);
        quiz_key_form::save((object) ['modulename' => 'quiz', 'coursemodule' => 0, quiz_key_form::FIELD => 'KEY-X']);
        quiz_key_form::save((object) ['modulename' => 'quiz', 'coursemodule' => $quiz->cmid, quiz_key_form::FIELD => 'KEY BAD']);

        $this->assertSame('', $repo->get((int) $quiz->cmid));
        $this->assertDebuggingCalled(null, DEBUG_DEVELOPER);
    }
}
