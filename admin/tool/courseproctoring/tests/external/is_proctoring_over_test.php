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

namespace tool_courseproctoring\external;

use core_external\external_api;
use tool_courseproctoring\local\session_repository;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/../course_helper_trait.php');

/**
 * Tests for the external function that tells a learner's page whether proctoring is over.
 *
 * @package    tool_courseproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_courseproctoring\external\is_proctoring_over
 */
final class is_proctoring_over_test extends \advanced_testcase {
    use \tool_courseproctoring\course_helper_trait;

    /** @var string The function's name, as the page script calls it. */
    private const FUNCTION = 'tool_courseproctoring_is_proctoring_over';

    /**
     * A switched-on site, a proctored course and its learner, who is the current user.
     *
     * @return \stdClass
     */
    private function setup_course(): \stdClass {
        $this->resetAfterTest();
        $this->enable_integration();
        $data = $this->create_proctored_course();
        $this->setUser($data->user);
        return $data;
    }

    /**
     * Call the function the way the browser does: through Moodle's AJAX entry (only functions allowed from AJAX work).
     *
     * @param array $arguments Arguments by name.
     * @return array ['error' => false, 'data' => ...] or ['error' => true, 'exception' => ...]
     */
    private function call(array $arguments): array {
        // The AJAX entry insists on the session key, which the browser sends with every call.
        $_POST['sesskey'] = sesskey();
        return external_api::call_external_function(self::FUNCTION, $arguments, true);
    }

    /**
     * Ask whether proctoring is over for the current user in a course.
     *
     * @param int $courseid
     * @return array see call()
     */
    private function ask(int $courseid): array {
        return $this->call(['courseid' => $courseid]);
    }

    /**
     * It is registered, for the page script (AJAX) only, as a read-only function that does not lock the session.
     */
    public function test_it_is_registered_for_the_page_script(): void {
        $this->resetAfterTest();

        $info = external_api::external_function_info(self::FUNCTION);

        $this->assertSame('tool_courseproctoring', $info->component);
        $this->assertTrue($info->allowed_from_ajax, 'callable from the page');
        $this->assertTrue($info->loginrequired, 'only for people who are logged in');
        $this->assertTrue($info->readonlysession, 'it only reads');
        $this->assertSame([], $info->services ?? [], 'it is in no web service');
    }

    /**
     * A learner whose session is running, in a course they have not completed, is still being proctored.
     */
    public function test_a_running_session_is_not_over(): void {
        $data = $this->setup_course();

        $before = $this->ask((int) $data->course->id);
        (new session_repository())->ensure_started($data->user->id, $data->course->id, 'KEY-COURSE');
        $after = $this->ask((int) $data->course->id);

        $this->assertFalse($before['error']);
        $this->assertSame(['over' => false], $before['data']);
        $this->assertSame(['over' => false], $after['data']);
    }

    /**
     * Once the learner has completed the course, proctoring is over, whether or not their session has been closed yet.
     */
    public function test_a_completed_course_is_over(): void {
        $data = $this->setup_course();
        $session = (new session_repository())->ensure_started($data->user->id, $data->course->id, 'KEY-COURSE');

        $this->complete_course($data->user->id, $data->course->id);
        $this->assertSame(['over' => true], $this->ask((int) $data->course->id)['data'], 'the session is still open');

        (new session_repository())->mark_closed($session->id);
        $this->assertSame(['over' => true], $this->ask((int) $data->course->id)['data'], 'the session is closed');
    }

    /**
     * A session that has been closed, or given up on, is over, even in a course without completion tracking.
     */
    public function test_a_finished_session_is_over(): void {
        $data = $this->setup_course();
        $repo = new session_repository();
        $session = $repo->ensure_started($data->user->id, $data->course->id, 'KEY-COURSE');

        $repo->mark_closed($session->id);
        $this->assertSame(['over' => true], $this->ask((int) $data->course->id)['data']);

        $other = $this->getDataGenerator()->create_and_enrol($data->course, 'student');
        $second = $repo->ensure_started($other->id, $data->course->id, 'KEY-COURSE');
        $repo->mark_failed($second->id, 'HTTP 400');
        $this->setUser($other);
        $this->assertSame(['over' => true], $this->ask((int) $data->course->id)['data']);
    }

    /**
     * A course without completion tracking is never completed: proctoring there ends only with the session.
     */
    public function test_no_completion_tracking(): void {
        $this->resetAfterTest();
        $this->enable_integration();
        $data = $this->create_proctored_course(['completion' => false]);
        $this->setUser($data->user);
        (new session_repository())->ensure_started($data->user->id, $data->course->id, 'KEY-COURSE');
        $this->complete_course($data->user->id, $data->course->id);

        $this->assertSame(['over' => false], $this->ask((int) $data->course->id)['data']);
    }

    /**
     * It answers only about the person who asks: another learner's completion changes nothing for this one, and there is
     * no way to name another person.
     */
    public function test_it_answers_only_about_the_asking_user(): void {
        $data = $this->setup_course();
        $other = $this->getDataGenerator()->create_and_enrol($data->course, 'student');
        $this->complete_course($other->id, $data->course->id);

        $this->assertSame(['over' => false], $this->ask((int) $data->course->id)['data'], 'the other learner completed');
        $this->setUser($other);
        $this->assertSame(['over' => true], $this->ask((int) $data->course->id)['data']);

        $parameters = array_keys(is_proctoring_over::execute_parameters()->keys);
        $this->assertSame(['courseid'], $parameters, 'no user can be named');
    }

    /**
     * It reads only: no session is started, none is closed, nothing is written to the ledger.
     */
    public function test_it_changes_nothing(): void {
        global $DB;
        $data = $this->setup_course();
        $session = (new session_repository())->ensure_started($data->user->id, $data->course->id, 'KEY-COURSE');
        $this->complete_course($data->user->id, $data->course->id);
        $before = $DB->get_records('tool_courseproctoring_session');

        $this->ask((int) $data->course->id);
        $this->ask((int) $data->course->id);

        $this->assertEquals($before, $DB->get_records('tool_courseproctoring_session'));
        $this->assertEquals(session_repository::STATUS_ACTIVE, (new session_repository())->get_by_id($session->id)->status);
        $this->assertSame(0, $DB->count_records('task_adhoc', ['component' => 'tool_courseproctoring']));
    }

    /**
     * Somebody who is not in the course, and a visitor who is not logged in, get an error, not an answer.
     */
    public function test_people_without_access_get_an_error(): void {
        $data = $this->setup_course();
        $stranger = $this->getDataGenerator()->create_user();
        $this->setUser($stranger);
        $answer = $this->ask((int) $data->course->id);
        $this->assertTrue($answer['error']);
        $this->assertArrayNotHasKey('data', $answer);
        $this->assertSame('requireloginerror', $answer['exception']->errorcode);

        $this->setUser(0);
        $this->assertTrue($this->ask((int) $data->course->id)['error']);
    }

    /**
     * A course that does not exist and a value that is not a number are refused.
     */
    public function test_bad_input_is_refused(): void {
        $data = $this->setup_course();

        $this->assertTrue($this->ask(99999999)['error']);

        $this->assertTrue($this->call(['courseid' => 'not-a-number'])['error']);
        $this->assertTrue($this->call([])['error']);
        $this->assertFalse($this->ask((int) $data->course->id)['error'], 'and a good call still works');
    }

    /**
     * The answer has the shape the page script reads, and nothing else.
     */
    public function test_the_answer_has_the_expected_shape(): void {
        $data = $this->setup_course();

        $answer = $this->ask((int) $data->course->id);

        $this->assertFalse($answer['error']);
        $this->assertSame(['error', 'data'], array_keys($answer));
        $this->assertSame(['over'], array_keys($answer['data']));
        $this->assertIsBool($answer['data']['over']);
    }
}
