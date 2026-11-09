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
 * Tests for the per-quiz Key ID store.
 *
 * @package    tool_quizproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_quizproctoring\local\quiz_key_repository
 */
final class quiz_key_repository_test extends \advanced_testcase {
    /**
     * Create a quiz.
     *
     * @param \stdClass|null $course
     * @return \stdClass quiz with cmid and course
     */
    private function quiz(?\stdClass $course = null): \stdClass {
        $this->resetAfterTest();
        $course ??= $this->getDataGenerator()->create_course();
        return $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance(['course' => $course->id]);
    }

    /**
     * Which Key IDs are acceptable.
     *
     * @dataProvider key_formats
     * @param string $keyid
     * @param bool $valid
     */
    public function test_is_valid(string $keyid, bool $valid): void {
        $this->assertSame($valid, quiz_key_repository::is_valid($keyid));
    }

    /**
     * Key formats.
     *
     * @return array
     */
    public static function key_formats(): array {
        return [
            'typical' => ['KEY-B068689', true],
            'underscore' => ['key_1', true],
            'digits only' => ['12345', true],
            'maximum length' => [str_repeat('a', 255), true],
            'too long' => [str_repeat('a', 256), false],
            'empty' => ['', false],
            'space' => ['KEY 1', false],
            'html' => ['"><script>', false],
            'slash' => ['a/b', false],
            'unicode' => ['KEY-é', false],
        ];
    }

    /**
     * A quiz with no row has no Key ID.
     */
    public function test_get_without_key(): void {
        $quiz = $this->quiz();
        $this->assertSame('', (new quiz_key_repository())->get((int) $quiz->cmid));
        $this->assertSame('', (new quiz_key_repository())->get(987654));
    }

    /**
     * Create, change, leave alone and remove a Key ID.
     */
    public function test_set_lifecycle(): void {
        global $DB;
        $quiz = $this->quiz();
        $repo = new quiz_key_repository();
        $cmid = (int) $quiz->cmid;

        $this->assertSame(quiz_key_repository::CREATED, $repo->set($cmid, '  KEY-ONE  '));
        $this->assertSame('KEY-ONE', $repo->get($cmid), 'trimmed');
        $row = $DB->get_record('tool_quizproctoring_quiz', ['cmid' => $cmid]);
        $this->assertEquals($quiz->course, $row->courseid);

        $this->assertSame(quiz_key_repository::UNCHANGED, $repo->set($cmid, 'KEY-ONE'));
        $this->assertSame(quiz_key_repository::UPDATED, $repo->set($cmid, 'KEY-TWO'));
        $this->assertSame('KEY-TWO', $repo->get($cmid));
        $this->assertEquals(1, $DB->count_records('tool_quizproctoring_quiz'));

        $this->assertSame(quiz_key_repository::DELETED, $repo->set($cmid, ''));
        $this->assertSame('', $repo->get($cmid));
        $this->assertSame(quiz_key_repository::UNCHANGED, $repo->set($cmid, ''));
        $this->assertEquals(0, $DB->count_records('tool_quizproctoring_quiz'));
    }

    /**
     * Each quiz has its own Key ID.
     */
    public function test_keys_are_per_quiz(): void {
        $first = $this->quiz();
        $second = $this->quiz();
        $repo = new quiz_key_repository();
        $repo->set((int) $first->cmid, 'KEY-A');
        $repo->set((int) $second->cmid, 'KEY-B');

        $this->assertSame('KEY-A', $repo->get((int) $first->cmid));
        $this->assertSame('KEY-B', $repo->get((int) $second->cmid));
    }

    /**
     * Only quizzes can have a Key ID.
     */
    public function test_only_quizzes_can_have_a_key(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->resetAfterTest();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $this->expectException(\invalid_parameter_exception::class);
        (new quiz_key_repository())->set((int) $page->cmid, 'KEY-X');
    }

    /**
     * An unknown course module is refused, even for an empty value.
     */
    public function test_unknown_quiz(): void {
        $this->resetAfterTest();
        $this->expectException(\invalid_parameter_exception::class);
        (new quiz_key_repository())->set(987654, 'KEY-X');
    }

    /**
     * An invalid Key ID is refused, and the saved one is untouched.
     */
    public function test_invalid_key_is_refused(): void {
        $quiz = $this->quiz();
        $repo = new quiz_key_repository();
        $repo->set((int) $quiz->cmid, 'KEY-GOOD');

        try {
            $repo->set((int) $quiz->cmid, 'KEY BAD');
            $this->fail('an invalid Key ID must be refused');
        } catch (\invalid_parameter_exception $e) {
            $this->assertSame('KEY-GOOD', $repo->get((int) $quiz->cmid));
        }
    }

    /**
     * Quizzes are removed one by one or per course.
     */
    public function test_delete(): void {
        $course = $this->getDataGenerator()->create_course();
        $one = $this->quiz($course);
        $two = $this->quiz($course);
        $other = $this->quiz();
        $repo = new quiz_key_repository();
        foreach ([$one, $two, $other] as $quiz) {
            $repo->set((int) $quiz->cmid, 'KEY-' . $quiz->cmid);
        }

        $repo->delete_for_cm((int) $one->cmid);
        $this->assertSame('', $repo->get((int) $one->cmid));
        $this->assertSame('KEY-' . $two->cmid, $repo->get((int) $two->cmid));

        $repo->delete_for_course((int) $course->id);
        $this->assertSame('', $repo->get((int) $two->cmid));
        $this->assertSame('KEY-' . $other->cmid, $repo->get((int) $other->cmid));
    }
}
