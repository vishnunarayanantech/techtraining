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

namespace tool_courseproctoring;

use core\hook\output\before_footer_html_generation;
use core_course\hook\after_form_definition;
use core_course\hook\after_form_submission;
use core_course\hook\after_form_validation;
use core_course\hook\before_course_deleted;
use tool_courseproctoring\local\config;
use tool_courseproctoring\local\course_key_form;
use tool_courseproctoring\local\widget_injector;

/**
 * Callbacks for Moodle's hooks (registered in db/hooks.php).
 *
 * The three course form callbacks only pass their hook on to {@see course_key_form}.
 *
 * @package    tool_courseproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * The course settings form is being built: add the Proctor Key section (site administrators only).
     *
     * @param after_form_definition $hook
     */
    public static function after_form_definition(after_form_definition $hook): void {
        course_key_form::add_elements($hook->formwrapper, $hook->mform);
    }

    /**
     * The course settings form is being validated: refuse a malformed Proctor Key.
     *
     * @param after_form_validation $hook
     */
    public static function after_form_validation(after_form_validation $hook): void {
        $errors = course_key_form::validate($hook->get_data());
        if ($errors) {
            $hook->add_errors($errors);
        }
    }

    /**
     * A course was created or updated from its settings: save the Proctor Key if the form carried it.
     *
     * @param after_form_submission $hook
     */
    public static function after_form_submission(after_form_submission $hook): void {
        course_key_form::save($hook->get_data());
    }

    /**
     * A page is being finished: start the proctoring widget on it if the rules say so.
     *
     * Runs on every page of the site, so the first line is the cheapest possible check: while the integration switch is
     * off nothing else is looked at. It must never break a page: any failure is swallowed (and shown only in developer
     * debugging).
     *
     * @param before_footer_html_generation $hook
     */
    public static function before_footer_html_generation(before_footer_html_generation $hook): void {
        global $PAGE;

        if (!config::is_vendor_enabled()) {
            return;
        }
        try {
            (new widget_injector())->inject($PAGE);
        } catch (\Throwable $e) {
            debugging('tool_courseproctoring: could not start proctoring: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * A course is about to be deleted: note it, so that un-enrolling its learners does not close their sessions one by one.
     *
     * @param before_course_deleted $hook
     */
    public static function before_course_deleted(before_course_deleted $hook): void {
        observer::note_course_deletion((int) $hook->course->id);
    }
}
