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

/**
 * Tests for the facts collected from a real Moodle page and the person looking at it.
 *
 * @package    tool_courseproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_courseproctoring\local\course_facts
 */
final class course_facts_test extends \advanced_testcase {
    /**
     * A page object the way a script of Moodle would set it up.
     *
     * @param \stdClass $course
     * @param string $layout
     * @param string $pagetype
     * @param \stdClass|null $module An activity of the course when the page is an activity page.
     * @return \moodle_page
     */
    private function page(\stdClass $course, string $layout, string $pagetype, ?\stdClass $module = null): \moodle_page {
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
     * A learner on an activity page of a course.
     */
    public function test_a_learner_on_an_activity_page(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $module = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $this->setUser($student);

        $facts = course_facts::from_page($this->page($course, 'incourse', 'mod-page-view', $module));

        $this->assertSame((int) $course->id, $facts->courseid);
        $this->assertSame('incourse', $facts->layout);
        $this->assertSame('mod-page-view', $facts->pagetype);
        $this->assertSame('page', $facts->activitytype);
        $this->assertSame((int) $student->id, $facts->userid);
        $this->assertTrue($facts->loggedin);
        $this->assertFalse($facts->loggedinas);
        $this->assertFalse($facts->roleswitched);
        $this->assertFalse($facts->siteadmin);
    }

    /**
     * A page of a quiz tells its activity type, and the course page has none.
     */
    public function test_activity_type(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'student'));

        $this->assertSame('quiz', course_facts::from_page($this->page($course, 'incourse', 'mod-quiz-view', $quiz))->activitytype);
        $this->assertSame('', course_facts::from_page($this->page($course, 'course', 'course-view-topics'))->activitytype);
    }

    /**
     * Visitors and guests are not logged-in users.
     */
    public function test_visitors_and_guests(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->page($course, 'incourse', 'mod-page-view');

        $this->setUser(0);
        $facts = course_facts::from_page($page);
        $this->assertFalse($facts->loggedin);
        $this->assertSame(0, $facts->userid);

        $this->setGuestUser();
        $facts = course_facts::from_page($page);
        $this->assertFalse($facts->loggedin);
        $this->assertSame(0, $facts->userid);
        $this->assertFalse($facts->siteadmin);
    }

    /**
     * A site administrator is recognised.
     */
    public function test_site_administrator(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->setAdminUser();

        $facts = course_facts::from_page($this->page($course, 'incourse', 'mod-page-view'));

        $this->assertTrue($facts->loggedin);
        $this->assertTrue($facts->siteadmin);
    }

    /**
     * An administrator "logged in as" a learner is seen as that, not as the learner.
     */
    public function test_logged_in_as(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setAdminUser();
        $page = $this->page($course, 'incourse', 'mod-page-view');

        \core\session\manager::loginas($student->id, \context_system::instance());
        $facts = course_facts::from_page($page);

        $this->assertSame((int) $student->id, $facts->userid, 'the page belongs to the learner');
        $this->assertTrue($facts->loggedinas);
        $this->assertFalse($facts->siteadmin);
    }

    /**
     * A person who switched to another role in the course is recognised.
     */
    public function test_role_switched(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);
        $page = $this->page($course, 'incourse', 'mod-page-view');
        $this->assertFalse(course_facts::from_page($page)->roleswitched);

        $studentrole = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        role_switch($studentrole, \context_course::instance($course->id));

        $this->assertTrue(course_facts::from_page($page)->roleswitched);
    }

    /**
     * The site home page is the site course.
     */
    public function test_site_home(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $page = new \moodle_page();
        $page->set_url('/');
        $page->set_context(\context_system::instance());
        $page->set_pagelayout('frontpage');

        $facts = course_facts::from_page($page);

        $this->assertSame((int) SITEID, $facts->courseid);
        $this->assertSame('frontpage', $facts->layout);
    }
}
