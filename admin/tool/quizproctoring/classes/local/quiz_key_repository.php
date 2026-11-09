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
 * Reads and writes the Key ID of one quiz (the "Proctor Key" entered in the quiz's settings form).
 *
 * This is the ONLY place a Key ID is read from: a quiz without a row (or with an empty Key ID) is not proctored. The
 * class has no side effects other than the database write; the audit event is raised by {@see quiz_key_form}.
 *
 * @package    tool_quizproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_key_repository {
    /** @var string Table name. */
    private const TABLE = 'tool_quizproctoring_quiz';

    /** @var int Maximum Key ID length (the column size). */
    public const MAXLENGTH = 255;

    /** @var string Returned by {@see set()} when a new row was inserted. */
    public const CREATED = 'created';
    /** @var string Returned by {@see set()} when an existing Key ID was changed. */
    public const UPDATED = 'updated';
    /** @var string Returned by {@see set()} when the Key ID was removed. */
    public const DELETED = 'deleted';
    /** @var string Returned by {@see set()} when nothing changed. */
    public const UNCHANGED = 'unchanged';

    /**
     * Whether a (non-empty) Key ID is acceptable: letters, digits, underscore and hyphen only.
     *
     * Existing Techversant keys look like KEY-XXXXXXX, which this allows. Invalid values are
     * rejected rather than silently cleaned, so the person entering the key sees what was wrong.
     *
     * @param string $keyid
     * @return bool
     */
    public static function is_valid(string $keyid): bool {
        return $keyid !== ''
            && \core_text::strlen($keyid) <= self::MAXLENGTH
            && clean_param($keyid, PARAM_ALPHANUMEXT) === $keyid;
    }

    /**
     * The Key ID of a quiz, or an empty string if it has none.
     *
     * @param int $cmid Course module id of the quiz.
     * @return string
     */
    public function get(int $cmid): string {
        global $DB;
        $keyid = $DB->get_field(self::TABLE, 'keyid', ['cmid' => $cmid]);
        return $keyid === false ? '' : (string) $keyid;
    }

    /**
     * Set, change or (with an empty string) remove the Key ID of a quiz.
     *
     * @param int $cmid Course module id of the quiz.
     * @param string $keyid
     * @return string One of the CREATED / UPDATED / DELETED / UNCHANGED constants.
     * @throws \invalid_parameter_exception for a course module that does not exist or is not a quiz, or an invalid Key ID.
     */
    public function set(int $cmid, string $keyid): string {
        global $DB;

        $keyid = trim($keyid);
        $cm = $DB->get_record_sql(
            "SELECT cm.id, cm.course
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.id = :cmid AND m.name = :modname",
            ['cmid' => $cmid, 'modname' => 'quiz']
        );
        if (!$cm) {
            throw new \invalid_parameter_exception('Unknown quiz');
        }

        $existing = $DB->get_record(self::TABLE, ['cmid' => $cmid]);

        if ($keyid === '') {
            if (!$existing) {
                return self::UNCHANGED;
            }
            $DB->delete_records(self::TABLE, ['id' => $existing->id]);
            return self::DELETED;
        }

        if (!self::is_valid($keyid)) {
            throw new \invalid_parameter_exception('Invalid Key ID');
        }

        $now = time();
        if (!$existing) {
            $DB->insert_record(self::TABLE, (object) [
                'cmid' => $cmid,
                'courseid' => (int) $cm->course,
                'keyid' => $keyid,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
            return self::CREATED;
        }
        if ($existing->keyid === $keyid) {
            return self::UNCHANGED;
        }
        $existing->keyid = $keyid;
        $existing->courseid = (int) $cm->course;
        $existing->timemodified = $now;
        $DB->update_record(self::TABLE, $existing);
        return self::UPDATED;
    }

    /**
     * Remove a quiz's Key ID (used when the quiz is deleted).
     *
     * @param int $cmid
     */
    public function delete_for_cm(int $cmid): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['cmid' => $cmid]);
    }

    /**
     * Remove the Key IDs of all quizzes of a course (used when the course is deleted).
     *
     * @param int $courseid
     */
    public function delete_for_course(int $courseid): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['courseid' => $courseid]);
    }
}
