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
 * The outcome of {@see course_gate::evaluate()}: proctor this page or not, and why.
 *
 * @package    tool_courseproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class decision {
    /**
     * Constructor.
     *
     * @param bool $proctored Whether the page should be proctored.
     * @param string $reason One of the course_gate reason constants.
     * @param string $keyid Key ID to use (only when proctored).
     */
    private function __construct(
        /** @var bool Whether the page should be proctored. */
        public readonly bool $proctored,
        /** @var string Why (a course_gate reason constant). */
        public readonly string $reason,
        /** @var string The Key ID to use, when proctored. */
        public readonly string $keyid = '',
    ) {
    }

    /**
     * Proctor this page.
     *
     * @param string $keyid
     * @param string $reason
     * @return self
     */
    public static function yes(string $keyid, string $reason): self {
        return new self(true, $reason, $keyid);
    }

    /**
     * Do not proctor this page.
     *
     * @param string $reason
     * @return self
     */
    public static function no(string $reason): self {
        return new self(false, $reason);
    }
}
