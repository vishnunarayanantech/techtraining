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

use mod_quiz\quiz_attempt;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/../attempt_helper_trait.php');

/**
 * Tests for the decision whether to proctor an attempt page.
 *
 * @package    tool_quizproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_quizproctoring\local\attempt_gate
 * @covers     \tool_quizproctoring\local\decision
 */
final class attempt_gate_test extends \advanced_testcase {
    use \tool_quizproctoring\attempt_helper_trait;

    /** @var int The viewer, who owns the attempt. */
    private const USER = 42;
    /** @var int The attempt. */
    private const ATTEMPT = 900;

    /**
     * Facts for a normal in-progress attempt of USER at the given quiz.
     *
     * @param \stdClass $quiz A quiz created by {@see configured_quiz()} (has course and cmid).
     * @param array $override Constructor argument overrides.
     * @return attempt_facts
     */
    private function facts(\stdClass $quiz, array $override = []): attempt_facts {
        $args = array_merge([
            'attemptid' => self::ATTEMPT, 'userid' => self::USER, 'courseid' => (int) $quiz->course, 'cmid' => (int) $quiz->cmid,
            'quizid' => (int) $quiz->id, 'state' => quiz_attempt::IN_PROGRESS, 'preview' => false, 'coursefullname' => 'Course',
        ], $override);
        return new attempt_facts(...$args);
    }

    /**
     * A site where everything needed is configured, and a quiz with a Key ID entered in its settings.
     *
     * @param string $keyid The quiz's Key ID ('' for none).
     * @return \stdClass the quiz (with course and cmid)
     */
    private function configured_quiz(string $keyid = 'KEY-QUIZ'): \stdClass {
        $this->resetAfterTest();
        $this->enable_integration();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance(['course' => $course->id]);
        (new quiz_key_repository())->set((int) $quiz->cmid, $keyid);
        return $quiz;
    }

    /**
     * The happy path: a student's in-progress attempt at a quiz with a Key ID.
     */
    public function test_proctored(): void {
        $quiz = $this->configured_quiz();
        $decision = (new attempt_gate())->evaluate($this->facts($quiz), self::USER);

        $this->assertTrue($decision->proctored);
        $this->assertSame(attempt_gate::PROCTORED, $decision->reason);
        $this->assertSame('KEY-QUIZ', $decision->keyid);
    }

    /**
     * No Key ID in the quiz's settings: not proctored. This is the core requirement.
     */
    public function test_quiz_without_key_is_not_proctored(): void {
        $quiz = $this->configured_quiz('');
        $decision = (new attempt_gate())->evaluate($this->facts($quiz), self::USER);

        $this->assertFalse($decision->proctored);
        $this->assertSame(attempt_gate::NO_QUIZ_KEY, $decision->reason);
        $this->assertSame('', $decision->keyid);
    }

    /**
     * The key of another quiz is never used, not even a quiz of the same course.
     */
    public function test_key_of_another_quiz_is_not_used(): void {
        $quiz = $this->configured_quiz();
        $sibling = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance(['course' => $quiz->course]);

        $decision = (new attempt_gate())->evaluate($this->facts($sibling), self::USER);

        $this->assertFalse($decision->proctored);
        $this->assertSame(attempt_gate::NO_QUIZ_KEY, $decision->reason);
    }

    /**
     * Key IDs that were saved per course by an earlier version of this plugin are never used: only a Key ID entered in
     * the quiz's own settings counts.
     */
    public function test_former_course_level_keys_are_ignored(): void {
        global $DB;
        $quiz = $this->configured_quiz('');
        $DB->insert_record('tool_quizproctoring_course', [
            'courseid' => $quiz->course, 'keyid' => 'KEY-OLD-COURSE', 'timecreated' => time(), 'timemodified' => time(),
        ]);

        $decision = (new attempt_gate())->evaluate($this->facts($quiz), self::USER);

        $this->assertFalse($decision->proctored);
        $this->assertSame(attempt_gate::NO_QUIZ_KEY, $decision->reason);
    }

    /**
     * Integration switched off: nothing is proctored, whatever else is configured.
     */
    public function test_vendor_integration_off(): void {
        $quiz = $this->configured_quiz();
        config::set_vendor_enabled(false);
        $decision = (new attempt_gate())->evaluate($this->facts($quiz), self::USER);
        $this->assertSame(attempt_gate::VENDOR_DISABLED, $decision->reason);
    }

    /**
     * A key that is stored but cannot be decrypted is the same as no key: the widget must never be given an empty App
     * Key.
     */
    public function test_unreadable_app_key_is_treated_as_no_key(): void {
        $quiz = $this->configured_quiz();
        set_config('appkey', 'not-an-encrypted-value', 'tool_quizproctoring');

        $decision = (new attempt_gate())->evaluate($this->facts($quiz), self::USER);

        $this->assertFalse($decision->proctored);
        $this->assertSame(attempt_gate::NO_APP_KEY, $decision->reason);
        $this->assertDebuggingCalled(null, DEBUG_DEVELOPER);
    }

    /**
     * Without an App Key nothing is proctored.
     */
    public function test_no_app_key(): void {
        $quiz = $this->configured_quiz();
        config::set_app_key('');
        $decision = (new attempt_gate())->evaluate($this->facts($quiz), self::USER);
        $this->assertSame(attempt_gate::NO_APP_KEY, $decision->reason);
    }

    /**
     * Somebody else's attempt (a teacher looking, an admin, another student) is not proctored.
     */
    public function test_attempt_of_another_user(): void {
        $quiz = $this->configured_quiz();
        $decision = (new attempt_gate())->evaluate($this->facts($quiz), self::USER + 1);
        $this->assertFalse($decision->proctored);
        $this->assertSame(attempt_gate::NOT_OWNER, $decision->reason);
    }

    /**
     * Teacher preview attempts are never proctored: mod_quiz fires no attempt_submitted for them, so the session
     * could not be closed.
     */
    public function test_preview_attempt(): void {
        $quiz = $this->configured_quiz();
        $decision = (new attempt_gate())->evaluate($this->facts($quiz, ['preview' => true]), self::USER);
        $this->assertSame(attempt_gate::PREVIEW, $decision->reason);
    }

    /**
     * Only attempts that are in progress are proctored.
     *
     * @dataProvider other_states
     * @param string $state
     */
    public function test_attempt_not_in_progress(string $state): void {
        $quiz = $this->configured_quiz();
        $decision = (new attempt_gate())->evaluate($this->facts($quiz, ['state' => $state]), self::USER);
        $this->assertSame(attempt_gate::NOT_IN_PROGRESS, $decision->reason);
    }

    /**
     * States other than in progress.
     *
     * @return array
     */
    public static function other_states(): array {
        return [
            'overdue' => [quiz_attempt::OVERDUE],
            'finished' => [quiz_attempt::FINISHED],
            'abandoned' => [quiz_attempt::ABANDONED],
        ];
    }

    /**
     * The decision depends on this plugin's own settings and the attempt and nothing else. Every user id (including 2,
     * the first admin) at a quiz with a Key ID is proctored on the same terms, so no attempt is left unproctored by
     * something outside this plugin.
     */
    public function test_no_other_conditions_apply(): void {
        $quiz = $this->configured_quiz();
        $gate = new attempt_gate();

        foreach ([2, 3, self::USER, 5000] as $userid) {
            $decision = $gate->evaluate($this->facts($quiz, ['userid' => $userid]), $userid);
            $this->assertTrue($decision->proctored, "user $userid");
            $this->assertSame('KEY-QUIZ', $decision->keyid);
        }
    }

    /**
     * A session this plugin already started continues with the Key ID it started with when the quiz's Key ID is
     * changed in the middle of the attempt, and stops being proctored when the quiz's Key ID is removed (the session
     * row stays, so it can still be closed).
     */
    public function test_session_keeps_its_original_key_until_the_quiz_key_is_removed(): void {
        $quiz = $this->configured_quiz();
        $facts = $this->facts($quiz);
        (new session_repository())->ensure_started($facts, 'KEY-QUIZ');
        $keys = new quiz_key_repository();
        $keys->set((int) $quiz->cmid, 'KEY-CHANGED');

        $gate = new attempt_gate();
        $decision = $gate->evaluate($facts, self::USER);
        $this->assertTrue($decision->proctored);
        $this->assertSame(attempt_gate::CONTINUING, $decision->reason);
        $this->assertSame('KEY-QUIZ', $decision->keyid);

        $keys->set((int) $quiz->cmid, '');
        $decision = $gate->evaluate($facts, self::USER);
        $this->assertFalse($decision->proctored, 'no Key ID in the quiz settings, no proctoring');
        $this->assertSame(attempt_gate::NO_QUIZ_KEY, $decision->reason);
        $this->assertNotNull((new session_repository())->get_by_attempt(self::ATTEMPT));
    }

    /**
     * A closed or failed session is never restarted (for example when a teacher re-opens an attempt): the vendor's
     * behaviour for a re-used session id is unknown. Documented gap.
     */
    public function test_documented_gap_ended_session_is_not_restarted(): void {
        $quiz = $this->configured_quiz();
        $facts = $this->facts($quiz);
        $sessions = new session_repository();
        $sessions->ensure_started($facts, 'KEY-QUIZ');
        $sessions->mark_closed($facts->attemptid);

        $decision = (new attempt_gate())->evaluate($facts, self::USER);
        $this->assertFalse($decision->proctored);
        $this->assertSame(attempt_gate::SESSION_ENDED, $decision->reason);
    }
}
