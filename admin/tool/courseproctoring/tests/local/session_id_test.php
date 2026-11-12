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
 * Tests for the vendor session id.
 *
 * @package    tool_courseproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_courseproctoring\local\session_id
 */
final class session_id_test extends \basic_testcase {
    /**
     * An id is "course_" and 20 lowercase hexadecimal characters: 27 characters, nothing else.
     */
    public function test_format(): void {
        $id = session_id::generate();

        $this->assertSame(27, strlen($id));
        $this->assertMatchesRegularExpression('/^course_[0-9a-f]{20}$/', $id);
        $this->assertStringStartsWith(session_id::PREFIX, $id);
        $this->assertTrue(session_id::is_valid($id));
    }

    /**
     * Ids do not repeat: thousands of them are all different, and none of them is calculated from anything.
     */
    public function test_ids_are_all_different(): void {
        $ids = [];
        for ($i = 0; $i < 5000; $i++) {
            $ids[session_id::generate()] = true;
        }
        $this->assertCount(5000, $ids);
    }

    /**
     * The check accepts what was made and nothing else: no old-style id, no other case, no other length, no spaces,
     * no trailing newline.
     *
     * @dataProvider invalid_ids
     * @param string $id
     */
    public function test_invalid_ids_are_rejected(string $id): void {
        $this->assertFalse(session_id::is_valid($id));
    }

    /**
     * Values that are not ids.
     *
     * @return array
     */
    public static function invalid_ids(): array {
        return [
            'empty' => [''],
            'a plain number' => ['321'],
            'calculated from a user and a course' => ['moodle_5_9'],
            'capital letters' => ['Course_0123456789abcdef0123'],
            'upper case hexadecimal' => ['course_0123456789ABCDEF0123'],
            'too short' => ['course_0123456789abcdef012'],
            'too long' => ['course_0123456789abcdef01234'],
            'not hexadecimal' => ['course_0123456789abcdef012g'],
            'wrong prefix' => ['session_0123456789abcdef0123'],
            'no prefix' => ['0123456789abcdef0123'],
            'a space at the end' => ['course_0123456789abcdef0123 '],
            'a newline at the end' => ["course_0123456789abcdef0123\n"],
            'a space at the start' => [' course_0123456789abcdef0123'],
            'two ids' => ['course_0123456789abcdef0123course_0123456789abcdef0123'],
        ];
    }
}
