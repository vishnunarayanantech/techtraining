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
 * Tests for the per-course Key ID store.
 *
 * @package    tool_courseproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_courseproctoring\local\course_key_repository
 */
final class course_key_repository_test extends \advanced_testcase {
    /**
     * Which Key IDs are acceptable.
     *
     * @dataProvider key_formats
     * @param string $keyid
     * @param bool $valid
     */
    public function test_is_valid(string $keyid, bool $valid): void {
        $this->assertSame($valid, course_key_repository::is_valid($keyid));
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
     * A course with no row has no Key ID.
     */
    public function test_get_without_key(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->assertSame('', (new course_key_repository())->get((int) $course->id));
        $this->assertSame('', (new course_key_repository())->get(987654));
    }

    /**
     * Create, change, leave alone and remove a Key ID.
     */
    public function test_set_lifecycle(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $repo = new course_key_repository();
        $id = (int) $course->id;

        $this->assertSame(course_key_repository::CREATED, $repo->set($id, '  KEY-ONE  '));
        $this->assertSame('KEY-ONE', $repo->get($id), 'trimmed');
        $row = $DB->get_record('tool_courseproctoring_course', ['courseid' => $id]);
        $this->assertGreaterThan(0, (int) $row->timecreated);

        $this->assertSame(course_key_repository::UNCHANGED, $repo->set($id, 'KEY-ONE'));
        $this->assertSame(course_key_repository::UPDATED, $repo->set($id, 'KEY-TWO'));
        $this->assertSame('KEY-TWO', $repo->get($id));
        $this->assertEquals(1, $DB->count_records('tool_courseproctoring_course'));

        $this->assertSame(course_key_repository::DELETED, $repo->set($id, ''));
        $this->assertSame('', $repo->get($id));
        $this->assertSame(course_key_repository::UNCHANGED, $repo->set($id, ''));
        $this->assertEquals(0, $DB->count_records('tool_courseproctoring_course'));
    }

    /**
     * Each course has its own Key ID.
     */
    public function test_keys_are_per_course(): void {
        $this->resetAfterTest();
        $first = $this->getDataGenerator()->create_course();
        $second = $this->getDataGenerator()->create_course();
        $repo = new course_key_repository();
        $repo->set((int) $first->id, 'KEY-A');
        $repo->set((int) $second->id, 'KEY-B');

        $this->assertSame('KEY-A', $repo->get((int) $first->id));
        $this->assertSame('KEY-B', $repo->get((int) $second->id));
    }

    /**
     * An unknown course is refused, even for an empty value; so is the site home.
     */
    public function test_unknown_course_and_site_home_are_refused(): void {
        $this->resetAfterTest();
        $repo = new course_key_repository();
        foreach ([987654, SITEID] as $courseid) {
            try {
                $repo->set($courseid, 'KEY-X');
                $this->fail('a key was accepted for course ' . $courseid);
            } catch (\invalid_parameter_exception $e) {
                $this->assertSame('', $repo->get($courseid));
            }
        }
        $this->expectException(\invalid_parameter_exception::class);
        $repo->set(987654, '');
    }

    /**
     * An invalid Key ID is refused, and the saved one is untouched.
     */
    public function test_invalid_key_is_refused(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $repo = new course_key_repository();
        $repo->set((int) $course->id, 'KEY-GOOD');

        try {
            $repo->set((int) $course->id, 'KEY BAD');
            $this->fail('an invalid Key ID must be refused');
        } catch (\invalid_parameter_exception $e) {
            $this->assertSame('KEY-GOOD', $repo->get((int) $course->id));
        }
    }

    /**
     * Removing one course's key leaves the others.
     */
    public function test_delete_for_course(): void {
        $this->resetAfterTest();
        $one = $this->getDataGenerator()->create_course();
        $two = $this->getDataGenerator()->create_course();
        $repo = new course_key_repository();
        $repo->set((int) $one->id, 'KEY-1');
        $repo->set((int) $two->id, 'KEY-2');

        $repo->delete_for_course((int) $one->id);
        $this->assertSame('', $repo->get((int) $one->id));
        $this->assertSame('KEY-2', $repo->get((int) $two->id));
    }
}
