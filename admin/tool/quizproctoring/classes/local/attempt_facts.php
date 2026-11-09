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
 * The facts about a quiz attempt that the proctoring decision needs.
 *
 * Loaded with a single query so the footer hook does not build a whole quiz_attempt object on
 * every question page.
 *
 * @package    tool_quizproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class attempt_facts {
    /**
     * Constructor.
     *
     * @param int $attemptid quiz_attempts.id
     * @param int $userid The user the attempt belongs to.
     * @param int $courseid
     * @param int $cmid Course module id of the quiz.
     * @param int $quizid
     * @param string $state quiz_attempts.state (a \mod_quiz\quiz_attempt state constant)
     * @param bool $preview Whether this is a teacher preview attempt.
     * @param string $coursefullname
     */
    public function __construct(
        /** @var int quiz_attempts.id */
        public readonly int $attemptid,
        /** @var int The user the attempt belongs to. */
        public readonly int $userid,
        /** @var int The course. */
        public readonly int $courseid,
        /** @var int Course module id of the quiz. */
        public readonly int $cmid,
        /** @var int The quiz. */
        public readonly int $quizid,
        /** @var string quiz_attempts.state */
        public readonly string $state,
        /** @var bool Whether this is a teacher preview attempt. */
        public readonly bool $preview,
        /** @var string The course full name. */
        public readonly string $coursefullname,
    ) {
    }

    /**
     * Load the facts for an attempt.
     *
     * @param int $attemptid
     * @return self|null Null if the attempt (or its quiz / course module) does not exist.
     */
    public static function from_database(int $attemptid): ?self {
        global $DB;

        $sql = "SELECT qa.id AS attemptid, qa.userid, qa.state, qa.preview,
                       q.id AS quizid, q.course AS courseid, cm.id AS cmid, c.fullname
                  FROM {quiz_attempts} qa
                  JOIN {quiz} q ON q.id = qa.quiz
                  JOIN {course} c ON c.id = q.course
                  JOIN {modules} m ON m.name = :modname
                  JOIN {course_modules} cm ON cm.module = m.id AND cm.instance = q.id
                 WHERE qa.id = :attemptid";
        $record = $DB->get_record_sql($sql, ['modname' => 'quiz', 'attemptid' => $attemptid]);
        if (!$record) {
            return null;
        }
        return new self(
            (int) $record->attemptid,
            (int) $record->userid,
            (int) $record->courseid,
            (int) $record->cmid,
            (int) $record->quizid,
            (string) $record->state,
            !empty($record->preview),
            (string) $record->fullname,
        );
    }
}
