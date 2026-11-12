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
 * The observers that call the proctoring service use 'internal' => false: Moodle then runs them only after the database
 * transaction that raised the event has committed, so a slow or failing call can never hold a transaction open or roll
 * it back (course completion, un-enrolling and deleting a user can all happen inside one).
 *
 * @package    tool_courseproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\course_completed',
        'callback' => '\tool_courseproctoring\observer::course_completed',
        'internal' => false,
    ],
    [
        'eventname' => '\core\event\user_enrolment_deleted',
        'callback' => '\tool_courseproctoring\observer::user_enrolment_changed',
        'internal' => false,
    ],
    [
        'eventname' => '\core\event\user_enrolment_updated',
        'callback' => '\tool_courseproctoring\observer::user_enrolment_changed',
        'internal' => false,
    ],
    [
        'eventname' => '\core\event\user_deleted',
        'callback' => '\tool_courseproctoring\observer::user_deleted',
        'internal' => false,
    ],
    [
        'eventname' => '\core\event\course_deleted',
        'callback' => '\tool_courseproctoring\observer::course_deleted',
    ],
];
