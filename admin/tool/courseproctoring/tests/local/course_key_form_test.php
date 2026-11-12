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

use core_course\hook\after_form_definition;
use core_course\hook\after_form_submission;
use core_course\hook\after_form_validation;

/**
 * Tests for the "Proctor Key" field of the course settings form.
 *
 * @package    tool_courseproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_courseproctoring\local\course_key_form
 * @covers     \tool_courseproctoring\hook_callbacks
 * @covers     \tool_courseproctoring\event\course_key_updated
 */
final class course_key_form_test extends \advanced_testcase {
    /** @var bool Whether the page has been set up for a real course form in this test. */
    private bool $pageready = false;

    /**
     * The course settings form, built the way course/edit.php builds it.
     *
     * @param \stdClass|null $course An existing course, or null for the form that creates a new one.
     * @return \course_edit_form
     */
    private function real_course_form(?\stdClass $course): \course_edit_form {
        global $CFG, $DB, $PAGE;
        require_once($CFG->dirroot . '/course/edit_form.php');
        require_once($CFG->dirroot . '/course/lib.php');

        $editoroptions = ['maxfiles' => EDITOR_UNLIMITED_FILES, 'maxbytes' => $CFG->maxbytes, 'trusttext' => false,
            'noclean' => true, 'subdirs' => 0];
        if ($course) {
            $context = \context_course::instance($course->id);
            $editoroptions['context'] = $context;
            $url = new \moodle_url('/course/edit.php', ['id' => $course->id]);
            $returnurl = new \moodle_url('/course/view.php', ['id' => $course->id]);
        } else {
            $context = \context_system::instance();
            $course = (object) ['category' => $DB->get_field('course_categories', 'id', [], IGNORE_MULTIPLE)];
            $editoroptions['context'] = \context_coursecat::instance($course->category);
            $url = new \moodle_url('/course/edit.php', ['category' => $course->category]);
            $returnurl = new \moodle_url('/course/management.php');
        }
        $category = $DB->get_record('course_categories', ['id' => $course->category], '*', MUST_EXIST);
        // The page (and so the theme) can only be set up once per test.
        if (!$this->pageready) {
            $PAGE->set_url($url);
            $PAGE->set_context($context);
            $this->pageready = true;
        }
        return new \course_edit_form(null, [
            'course' => $course,
            'category' => $category,
            'editoroptions' => $editoroptions,
            'returnto' => 0,
            'returnurl' => $returnurl,
        ]);
    }

    /**
     * Run the course through Moodle's own update with the given Proctor Key in the submitted data.
     *
     * @param \stdClass $course
     * @param string|null $keyid Null leaves the field out, as a form that did not show it would.
     */
    private function submit_update(\stdClass $course, ?string $keyid): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        $data = clone get_course($course->id);
        if ($keyid !== null) {
            $data->{course_key_form::FIELD} = $keyid;
        }
        update_course($data);
    }

    /**
     * An administrator sees the "Course Proctoring Key Setting" section once, with the saved Key ID.
     */
    public function test_administrator_sees_the_section_once_in_the_real_course_form(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();

        $html = $this->real_course_form($course)->render();
        $this->assertSame(1, substr_count($html, 'name="' . course_key_form::FIELD . '"'));
        $this->assertSame(1, substr_count($html, 'id="id_' . course_key_form::HEADER . '"'), 'one section');
        $this->assertStringContainsString('Course Proctoring Key Setting', $html);
        $this->assertStringContainsString('Proctor Key', $html);
        $this->assertStringContainsString('name="fullname"', $html, 'The real course form was built');
        $this->assertStringNotContainsString('value="KEY-SAVED"', $html);

        (new course_key_repository())->set((int) $course->id, 'KEY-SAVED');
        $html = $this->real_course_form($course)->render();
        $this->assertMatchesRegularExpression('/name="' . course_key_form::FIELD . '"[^>]*value="KEY-SAVED"/', $html);
    }

    /**
     * The form that creates a new course shows an empty field to an administrator.
     */
    public function test_new_course_form_has_an_empty_field(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->real_course_form(null)->render();

        $this->assertSame(1, substr_count($html, 'name="' . course_key_form::FIELD . '"'));
        $this->assertSame(1, substr_count($html, 'id="id_' . course_key_form::HEADER . '"'), 'one section');
        $this->assertDoesNotMatchRegularExpression('/name="' . course_key_form::FIELD . '"[^>]*value="[^"]/', $html);
    }

    /**
     * A teacher, a manager and the learner never get the section, whatever is saved.
     */
    public function test_nobody_but_a_site_administrator_sees_the_section(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        (new course_key_repository())->set((int) $course->id, 'KEY-SECRET');
        $generator = $this->getDataGenerator();

        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $manager = $generator->create_user();
        $managerrole = $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        role_assign($managerrole, $manager->id, \context_system::instance());

        foreach (['teacher' => $teacher, 'manager' => $manager] as $who => $user) {
            $this->setUser($user);
            $html = $this->real_course_form($course)->render();
            $this->assertStringContainsString('name="fullname"', $html, $who . ': the form was built');
            $this->assertStringNotContainsString(course_key_form::FIELD, $html, $who);
            $this->assertStringNotContainsString('Course Proctoring Key Setting', $html, $who);
            $this->assertStringNotContainsString('KEY-SECRET', $html, $who . ' must not even see the saved key');
            $this->pageready = false;
        }
    }

    /**
     * The real form's validation reports a malformed Key ID on the field, and accepts an empty or valid one.
     */
    public function test_validation_in_the_real_form(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $form = $this->real_course_form($course);
        $field = course_key_form::FIELD;
        $data = (array) get_course($course->id);

        $errors = $form->validation($data + [$field => 'KEY BAD'], []);
        $this->assertArrayHasKey($field, $errors);
        $this->assertSame(get_string('invalidkeyid', 'tool_courseproctoring'), $errors[$field]);
        $this->assertArrayNotHasKey($field, $form->validation($data + [$field => 'KEY-OK'], []));
        $this->assertArrayNotHasKey($field, $form->validation($data + [$field => '   '], []));
        $this->assertArrayNotHasKey($field, $form->validation($data, []), 'a form without the field');
    }

    /**
     * Validation does nothing for somebody who cannot see the field.
     */
    public function test_validation_ignores_the_field_for_a_teacher(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'editingteacher'));

        $this->assertSame([], course_key_form::validate([course_key_form::FIELD => 'KEY BAD']));
    }

    /**
     * Saving through Moodle's own course update: creates, changes and removes the Key ID, and logs it without the value.
     */
    public function test_saving_through_moodles_course_update(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $repo = new course_key_repository();
        $sink = $this->redirectEvents();

        $this->submit_update($course, ' KEY-NEW ');
        $this->assertSame('KEY-NEW', $repo->get((int) $course->id));
        $this->submit_update($course, 'KEY-NEW');
        $this->submit_update($course, 'KEY-NEXT');
        $this->assertSame('KEY-NEXT', $repo->get((int) $course->id));
        $this->submit_update($course, '');
        $this->assertSame('', $repo->get((int) $course->id), 'an empty field removes the key');

        $events = array_values(array_filter(
            $sink->get_events(),
            static fn($event) => $event instanceof \tool_courseproctoring\event\course_key_updated
        ));
        $this->assertSame(['created', 'updated', 'deleted'], array_map(static fn($event) => $event->other['action'], $events));
        $this->assertEquals($course->id, $events[0]->courseid);
        $this->assertStringEndsWith('/course/edit.php?id=' . $course->id, $events[0]->get_url()->out(false));
        $this->assertNotEmpty($events[0]->get_name());
        foreach ($events as $event) {
            $this->assertStringNotContainsString('KEY-N', json_encode($event->get_data()));
            $this->assertStringNotContainsString('KEY-N', $event->get_description());
        }
    }

    /**
     * A new course gets its key from the same hook: Moodle has set the new course id by then.
     */
    public function test_a_new_course_gets_its_key(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = create_course((object) [
            'fullname' => 'A proctored course',
            'shortname' => 'proctored1',
            'category' => $DB->get_field('course_categories', 'id', [], IGNORE_MULTIPLE),
            course_key_form::FIELD => 'KEY-FROM-THE-FORM',
        ]);

        $this->assertSame('KEY-FROM-THE-FORM', (new course_key_repository())->get((int) $course->id));
    }

    /**
     * A course that is saved without the field keeps its key. This is what a teacher's form, a web service or an import
     * does, and is the mistake the older code made (it wrote an empty key over the saved one).
     */
    public function test_a_course_saved_without_the_field_keeps_its_key(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $repo = new course_key_repository();
        $repo->set((int) $course->id, 'KEY-KEEP');
        $sink = $this->redirectEvents();

        $this->submit_update($course, null);
        $this->assertSame('KEY-KEEP', $repo->get((int) $course->id), 'an administrator saving without the field');

        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'editingteacher'));
        $this->submit_update($course, null);
        $this->assertSame('KEY-KEEP', $repo->get((int) $course->id), 'a teacher saving the course');

        $this->assertSame([], array_filter(
            $sink->get_events(),
            static fn($event) => $event instanceof \tool_courseproctoring\event\course_key_updated
        ), 'nothing changed, so nothing is logged');
    }

    /**
     * A teacher (or anybody else without site configuration rights) cannot change a key by sending the field anyway.
     */
    public function test_a_forged_field_from_a_non_administrator_changes_nothing(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $repo = new course_key_repository();
        $repo->set((int) $course->id, 'KEY-KEEP');
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'editingteacher'));

        $this->submit_update($course, 'KEY-FORGED');
        $this->assertSame('KEY-KEEP', $repo->get((int) $course->id));
        $this->submit_update($course, '');
        $this->assertSame('KEY-KEEP', $repo->get((int) $course->id), 'they cannot remove it either');

        // Nor can they give a brand new course a key.
        $new = create_course((object) [
            'fullname' => 'Another course',
            'shortname' => 'another1',
            'category' => $DB->get_field('course_categories', 'id', [], IGNORE_MULTIPLE),
            course_key_form::FIELD => 'KEY-FORGED',
        ]);
        $this->assertSame('', $repo->get((int) $new->id));
    }

    /**
     * A value that is not a valid Key ID is not stored if it reaches the save step anyway, and the old key stays.
     */
    public function test_an_invalid_value_is_not_stored(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $repo = new course_key_repository();
        $repo->set((int) $course->id, 'KEY-GOOD');

        $this->submit_update($course, 'KEY BAD');

        $this->assertSame('KEY-GOOD', $repo->get((int) $course->id));
        $this->assertDebuggingCalled(null, DEBUG_DEVELOPER);
    }

    /**
     * The site home page has no key, even if something sends one.
     */
    public function test_the_site_home_never_gets_a_key(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        course_key_form::save((object) ['id' => SITEID, course_key_form::FIELD => 'KEY-X']);
        course_key_form::save((object) ['id' => 0, course_key_form::FIELD => 'KEY-X']);
        course_key_form::save((object) [course_key_form::FIELD => 'KEY-X']);

        $this->assertEquals(0, $DB->count_records('tool_courseproctoring_course'));
    }

    /**
     * Copying a course (duplicate) does not copy its Proctor Key; the copy must be given its own.
     */
    public function test_a_duplicate_does_not_get_the_key(): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/externallib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $repo = new course_key_repository();
        $repo->set((int) $course->id, 'KEY-ORIGINAL');

        $copy = \core_course_external::duplicate_course($course->id, 'Copy of the course', 'copy1', $course->category, 1);

        $this->assertNotEquals($course->id, $copy['id']);
        $this->assertSame('', $repo->get((int) $copy['id']));
        $this->assertSame('KEY-ORIGINAL', $repo->get((int) $course->id));
    }

    /**
     * Moodle finds this plugin's callbacks (db/hooks.php) for the three extension points of the course form.
     */
    public function test_moodle_finds_the_callbacks(): void {
        $manager = \core\di::get(\core\hook\manager::class);
        $expected = [
            after_form_definition::class => 'after_form_definition',
            after_form_validation::class => 'after_form_validation',
            after_form_submission::class => 'after_form_submission',
        ];
        foreach ($expected as $hook => $method) {
            $ours = array_values(array_filter(
                $manager->get_callbacks_for_hook($hook),
                static fn($definition) => $definition['component'] === 'tool_courseproctoring'
            ));
            $this->assertCount(1, $ours, $hook);
            $this->assertSame(\tool_courseproctoring\hook_callbacks::class . '::' . $method, $ours[0]['callback'], $hook);
        }
    }
}
