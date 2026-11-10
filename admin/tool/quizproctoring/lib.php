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

/**
 * Callbacks Moodle calls while an activity settings form is built, validated and saved (course/modedit.php).
 *
 * They add the "Proctor Key" field to quiz forms only; the work is done in {@see \tool_quizproctoring\local\quiz_key_form}.
 *
 * @package    tool_quizproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Add the "Quiz Proctoring Key Setting" section to the quiz settings form.
 *
 * @param moodleform_mod $formwrapper The activity form being built.
 * @param MoodleQuickForm $mform The form's quick form.
 */
function tool_quizproctoring_coursemodule_standard_elements($formwrapper, $mform) {
    \tool_quizproctoring\local\quiz_key_form::add_elements($formwrapper, $mform);
}

/**
 * Validate the Proctor Key of the quiz settings form.
 *
 * @param moodleform_mod $formwrapper The activity form being validated.
 * @param array $data Submitted data.
 * @return array field name => error message
 */
function tool_quizproctoring_coursemodule_validation($formwrapper, $data) {
    return \tool_quizproctoring\local\quiz_key_form::validate($formwrapper, (array) $data);
}

/**
 * Save the Proctor Key after a quiz was created or updated.
 *
 * @param stdClass $moduleinfo The submitted data, completed by Moodle.
 * @param stdClass $course The course.
 * @return stdClass The module information (Moodle expects it back).
 */
function tool_quizproctoring_coursemodule_edit_post_actions($moduleinfo, $course) {
    \tool_quizproctoring\local\quiz_key_form::save($moduleinfo);
    return $moduleinfo;
}
