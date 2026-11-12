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
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use tool_courseproctoring\local\course_gate;

/**
 * Tells the page of a learner whose proctoring widget is running whether proctoring is over for them in the course.
 *
 * The page script asks it right after the learner marks an activity as done. If the answer is yes (the learner has just
 * completed the course, and the server has closed their vendor session), the script stops the widget with the vendor's
 * own endSession(), so that the widget does not keep talking to a session that no longer exists.
 *
 * It answers only about the person who is asking, and only from this plugin's own ledger and Moodle's own course
 * completion. It changes nothing.
 *
 * @package    tool_courseproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class is_proctoring_over extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'The course the widget is running in'),
        ]);
    }

    /**
     * Is proctoring over for the current user in the course?
     *
     * @param int $courseid
     * @return array ['over' => bool]
     */
    public static function execute(int $courseid): array {
        global $USER;

        ['courseid' => $courseid] = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);
        self::validate_context(\context_course::instance($courseid));

        return ['over' => (new course_gate())->is_over((int) $USER->id, $courseid)];
    }

    /**
     * Return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'over' => new external_value(PARAM_BOOL, 'True if the session has ended or the course is completed'),
        ]);
    }
}
