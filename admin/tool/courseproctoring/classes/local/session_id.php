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
 * The vendor session id: the one place that knows its shape.
 *
 * The id is `course_` followed by 20 random lowercase hexadecimal characters (80 random bits from PHP's secure random
 * generator): 27 characters, for example course_0123456789abcdef0123. It is made once, when a learner's session for a
 * course is recorded in the ledger, and stored there; it is never calculated again from anything. It is random because
 * the widget address that reaches the browser contains it (and the vendor's design also sends the App Key to the
 * browser), so an id that could be worked out from a user id and a course id could be guessed.
 *
 * @package    tool_courseproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class session_id {
    /** @var string What every id starts with. */
    public const PREFIX = 'course_';

    /** @var int Random bytes in an id (two hexadecimal characters each). */
    public const RANDOM_BYTES = 10;

    /** @var string What a valid id looks like. The D modifier makes $ mean the very end, not "before a final newline". */
    private const PATTERN = '/^course_[0-9a-f]{20}$/D';

    /**
     * Make a new, random session id.
     *
     * @return string
     */
    public static function generate(): string {
        return self::PREFIX . bin2hex(random_bytes(self::RANDOM_BYTES));
    }

    /**
     * Whether a value has the shape of an id made by {@see generate()}.
     *
     * The widget address and the close request are only ever built from ids that pass this check.
     *
     * @param string $id
     * @return bool
     */
    public static function is_valid(string $id): bool {
        return (bool) preg_match(self::PATTERN, $id);
    }
}
