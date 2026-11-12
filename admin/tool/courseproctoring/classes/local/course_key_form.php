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

use tool_courseproctoring\event\course_key_updated;

/**
 * The "Proctor Key" field in the course settings form (course/edit.php).
 *
 * Moodle calls the hook callbacks in {@see \tool_courseproctoring\hook_callbacks} while it builds, validates and saves
 * the course form; each of them delegates to one method here. Everything is restricted to site administrators
 * (moodle/site:config, the same as the plugin's administration page): for anybody else the field is not shown, and a
 * saved Key ID is never touched by their form submissions.
 *
 * @package    tool_courseproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_key_form {
    /** @var string Name of the form field. */
    public const FIELD = 'tool_courseproctoring_keyid';
    /** @var string Name of the form section header. */
    public const HEADER = 'tool_courseproctoring_header';

    /**
     * Whether the current user may see and change course Key IDs.
     *
     * @return bool
     */
    public static function can_manage(): bool {
        return has_capability('moodle/site:config', \context_system::instance());
    }

    /**
     * Add the "Course Proctoring Key Setting" section to the course form.
     *
     * @param object $formwrapper The course_edit_form being built (only get_course() is used).
     * @param \MoodleQuickForm $mform
     */
    public static function add_elements(object $formwrapper, \MoodleQuickForm $mform): void {
        if (!self::can_manage()) {
            return;
        }

        $mform->addElement('header', self::HEADER, get_string('coursekeyheader', 'tool_courseproctoring'));
        $mform->setExpanded(self::HEADER, true);
        $mform->addElement(
            'text',
            self::FIELD,
            get_string('coursekey', 'tool_courseproctoring'),
            ['maxlength' => course_key_repository::MAXLENGTH, 'size' => 40, 'autocomplete' => 'off', 'spellcheck' => 'false']
        );
        $mform->setType(self::FIELD, PARAM_RAW_TRIMMED);
        $mform->addHelpButton(self::FIELD, 'coursekey', 'tool_courseproctoring');

        // Show the saved Key ID when an existing course is edited. A problem reading it must not break the form.
        $courseid = (int) ($formwrapper->get_course()->id ?? 0);
        if ($courseid > 0) {
            try {
                $saved = (new course_key_repository())->get($courseid);
            } catch (\Throwable $e) {
                debugging('tool_courseproctoring: could not read the course Key ID: ' . $e->getMessage(), DEBUG_DEVELOPER);
                $saved = '';
            }
            if ($saved !== '') {
                $mform->setDefault(self::FIELD, $saved);
            }
        }
    }

    /**
     * Validate the submitted Key ID of the course form.
     *
     * @param array $data Submitted data.
     * @return array field name => error message
     */
    public static function validate(array $data): array {
        if (!self::can_manage()) {
            return [];
        }
        $keyid = trim((string) ($data[self::FIELD] ?? ''));
        if ($keyid !== '' && !course_key_repository::is_valid($keyid)) {
            return [self::FIELD => get_string('invalidkeyid', 'tool_courseproctoring')];
        }
        return [];
    }

    /**
     * Save the submitted Key ID after a course was created or updated.
     *
     * Nothing happens unless the form really carried the field, so a course saved by anybody or anything that did not
     * show the field (a teacher, a web service, an import) can never blank a Key ID. An empty value removes the course's
     * Key ID, which means the course is no longer proctored.
     *
     * @param \stdClass $data The submitted data of the course, with the course id set (also for a new course).
     */
    public static function save(\stdClass $data): void {
        $courseid = (int) ($data->id ?? 0);
        // SITEID is the string '1' in Moodle, so it is compared as a number.
        if ($courseid <= 0 || $courseid === (int) SITEID) {
            return;
        }
        if (!property_exists($data, self::FIELD) || !self::can_manage()) {
            return;
        }

        $keyid = trim((string) $data->{self::FIELD});
        if ($keyid !== '' && !course_key_repository::is_valid($keyid)) {
            // The form validation already refused this; a value that arrives some other way is not stored.
            debugging('tool_courseproctoring: an invalid Key ID was ignored', DEBUG_DEVELOPER);
            return;
        }

        $action = (new course_key_repository())->set($courseid, $keyid);
        if ($action !== course_key_repository::UNCHANGED) {
            course_key_updated::create([
                'context' => \context_course::instance($courseid),
                'other' => ['action' => $action],
            ])->trigger();
        }
    }
}
