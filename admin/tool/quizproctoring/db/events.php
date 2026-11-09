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
 * Event observers.
 *
 * mod_quiz fires attempt_submitted / attempt_abandoned inside a database transaction
 * (quiz_attempt::process_finish() and process_abandon()). 'internal' => false defers these
 * observers until after that transaction has committed, so a slow or failing proctoring call
 * can never hold the quiz transaction open or roll it back.
 *
 * @package    tool_quizproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\mod_quiz\event\attempt_submitted',
        'callback' => '\tool_quizproctoring\observer::attempt_ended',
        'internal' => false,
    ],
    [
        'eventname' => '\mod_quiz\event\attempt_abandoned',
        'callback' => '\tool_quizproctoring\observer::attempt_ended',
        'internal' => false,
    ],
    [
        'eventname' => '\mod_quiz\event\attempt_deleted',
        'callback' => '\tool_quizproctoring\observer::attempt_deleted',
    ],
    [
        'eventname' => '\core\event\course_deleted',
        'callback' => '\tool_quizproctoring\observer::course_deleted',
    ],
    [
        'eventname' => '\core\event\course_module_deleted',
        'callback' => '\tool_quizproctoring\observer::course_module_deleted',
    ],
];
