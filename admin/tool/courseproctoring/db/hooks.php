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
 * Hook callbacks.
 *
 * The three course-settings-form hooks add the "Course Proctoring Key Setting" section, check what was typed in it and
 * save it. A new course is saved by after_form_submission too: Moodle sets the new course id before it dispatches that
 * hook (checked in Moodle 4.4.0, 4.5.0 and 4.5.3).
 *
 * before_footer_html_generation is dispatched from core_renderer::footer() for every page layout, before the page's
 * JavaScript is finalised; it starts the proctoring widget on the pages of a proctored course. before_course_deleted only
 * notes which course is being deleted, so that closing sessions does not slow the deletion down.
 *
 * @package    tool_courseproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        'hook' => \core_course\hook\after_form_definition::class,
        'callback' => [\tool_courseproctoring\hook_callbacks::class, 'after_form_definition'],
    ],
    [
        'hook' => \core_course\hook\after_form_validation::class,
        'callback' => [\tool_courseproctoring\hook_callbacks::class, 'after_form_validation'],
    ],
    [
        'hook' => \core_course\hook\after_form_submission::class,
        'callback' => [\tool_courseproctoring\hook_callbacks::class, 'after_form_submission'],
    ],
    [
        'hook' => \core\hook\output\before_footer_html_generation::class,
        'callback' => [\tool_courseproctoring\hook_callbacks::class, 'before_footer_html_generation'],
    ],
    [
        'hook' => \core_course\hook\before_course_deleted::class,
        'callback' => [\tool_courseproctoring\hook_callbacks::class, 'before_course_deleted'],
    ],
];
