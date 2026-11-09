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

use tool_quizproctoring\event\quiz_key_updated;

/**
 * The "Proctor Key" field in the settings form of a quiz (course/modedit.php).
 *
 * Moodle calls the plugin callbacks in lib.php while it builds, validates and saves an activity form; each of them
 * delegates to one method here. Everything is restricted to quizzes (no other activity gets the field) and to site
 * administrators (moodle/site:config, the same as the plugin's administration page): for anybody else the field is not
 * shown, and a saved Key ID is never touched by their form submissions.
 *
 * @package    tool_quizproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_key_form {
    /** @var string Name of the form field. */
    public const FIELD = 'tool_quizproctoring_keyid';
    /** @var string Name of the form section header. */
    public const HEADER = 'tool_quizproctoring_header';

    /**
     * Whether the form being built is a quiz form.
     *
     * @param object $formwrapper The moodleform_mod being built or validated (only get_current() is used).
     * @return bool
     */
    private static function is_quiz_form(object $formwrapper): bool {
        $current = $formwrapper->get_current();
        return !empty($current->modulename) && $current->modulename === 'quiz';
    }

    /**
     * Whether the current user may see and change quiz Key IDs.
     *
     * @return bool
     */
    public static function can_manage(): bool {
        return has_capability('moodle/site:config', \context_system::instance());
    }

    /**
     * Add the "Proctor Key Settings" section to a quiz form.
     *
     * @param object $formwrapper The moodleform_mod being built.
     * @param \MoodleQuickForm $mform
     */
    public static function add_elements(object $formwrapper, \MoodleQuickForm $mform): void {
        if (!self::is_quiz_form($formwrapper) || !self::can_manage()) {
            return;
        }

        $mform->addElement('header', self::HEADER, get_string('quizkeyheader', 'tool_quizproctoring'));
        $mform->setExpanded(self::HEADER, true);
        $mform->addElement(
            'text',
            self::FIELD,
            get_string('quizkey', 'tool_quizproctoring'),
            ['maxlength' => quiz_key_repository::MAXLENGTH, 'size' => 40, 'autocomplete' => 'off', 'spellcheck' => 'false']
        );
        $mform->setType(self::FIELD, PARAM_RAW_TRIMMED);
        $mform->addHelpButton(self::FIELD, 'quizkey', 'tool_quizproctoring');

        // Show the saved Key ID when an existing quiz is edited. A problem reading it must not break the form.
        $current = $formwrapper->get_current();
        if (!empty($current->coursemodule)) {
            try {
                $saved = (new quiz_key_repository())->get((int) $current->coursemodule);
            } catch (\Throwable $e) {
                debugging('tool_quizproctoring: could not read the quiz Key ID: ' . $e->getMessage(), DEBUG_DEVELOPER);
                $saved = '';
            }
            if ($saved !== '') {
                $mform->setDefault(self::FIELD, $saved);
            }
        }
    }

    /**
     * Validate the submitted Key ID of a quiz form.
     *
     * @param object $formwrapper The moodleform_mod being validated.
     * @param array $data Submitted data.
     * @return array field name => error message
     */
    public static function validate(object $formwrapper, array $data): array {
        if (!self::is_quiz_form($formwrapper) || !self::can_manage()) {
            return [];
        }
        $keyid = trim((string) ($data[self::FIELD] ?? ''));
        if ($keyid !== '' && !quiz_key_repository::is_valid($keyid)) {
            return [self::FIELD => get_string('invalidkeyid', 'tool_quizproctoring')];
        }
        return [];
    }

    /**
     * Save the submitted Key ID after a quiz was created or updated.
     *
     * Nothing happens unless the form really carried the field, so a user who could not see it can never blank a Key ID.
     * An empty value removes the quiz's Key ID, which means the quiz is no longer proctored.
     *
     * @param \stdClass $moduleinfo The submitted form data, completed by Moodle (coursemodule, modulename, ...).
     */
    public static function save(\stdClass $moduleinfo): void {
        if (($moduleinfo->modulename ?? '') !== 'quiz' || empty($moduleinfo->coursemodule)) {
            return;
        }
        if (!property_exists($moduleinfo, self::FIELD) || !self::can_manage()) {
            return;
        }

        $cmid = (int) $moduleinfo->coursemodule;
        $keyid = trim((string) $moduleinfo->{self::FIELD});
        if ($keyid !== '' && !quiz_key_repository::is_valid($keyid)) {
            // The form validation already refused this; a value that arrives some other way is not stored.
            debugging('tool_quizproctoring: an invalid Key ID was ignored', DEBUG_DEVELOPER);
            return;
        }

        $action = (new quiz_key_repository())->set($cmid, $keyid);
        if ($action !== quiz_key_repository::UNCHANGED) {
            quiz_key_updated::create([
                'context' => \context_module::instance($cmid),
                'other' => ['action' => $action],
            ])->trigger();
        }
    }
}
