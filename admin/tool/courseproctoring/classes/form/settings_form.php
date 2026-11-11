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

namespace tool_courseproctoring\form;

use tool_courseproctoring\local\config;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

/**
 * The site-wide settings form: App Key and the vendor-integration switch.
 *
 * The saved App Key is never shown. Leave the field empty to keep it, type a new value to replace it, or tick
 * "Remove" to delete it. moodleform checks the session key on submission.
 *
 * @package    tool_courseproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class settings_form extends \moodleform {
    #[\Override]
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('header', 'sitesettings', get_string('sitesettings', 'tool_courseproctoring'));

        $hasappkey = config::has_app_key();
        $appkey = $hasappkey ? config::get_app_key() : '';
        if ($appkey !== '') {
            $status = get_string('appkeysaved', 'tool_courseproctoring', config::mask($appkey));
        } else if ($hasappkey) {
            // Something is stored but cannot be decrypted (for example the site's encryption key was replaced).
            $status = get_string('appkeyunreadable', 'tool_courseproctoring');
        } else {
            $status = get_string('appkeynotset', 'tool_courseproctoring');
        }
        $mform->addElement('static', 'appkeystatus', get_string('appkey', 'tool_courseproctoring'), $status);

        $mform->addElement('passwordunmask', 'appkey', get_string('appkeynew', 'tool_courseproctoring'), [
            'autocomplete' => 'off',
            'maxlength' => 255,
        ]);
        $mform->setType('appkey', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('appkey', 'appkey', 'tool_courseproctoring');

        if ($hasappkey) {
            $mform->addElement('advcheckbox', 'clearappkey', '', get_string('clearappkey', 'tool_courseproctoring'));
            $mform->setType('clearappkey', PARAM_BOOL);
        }

        $mform->addElement(
            'advcheckbox',
            'vendorenabled',
            get_string('vendorenabled', 'tool_courseproctoring'),
            get_string('vendorenabled_desc', 'tool_courseproctoring')
        );
        $mform->setType('vendorenabled', PARAM_BOOL);
        $mform->setDefault('vendorenabled', (int) config::is_vendor_enabled());

        $this->add_action_buttons(false, get_string('savechanges'));
    }

    #[\Override]
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        $newkey = trim((string) ($data['appkey'] ?? ''));
        $clear = !empty($data['clearappkey']);

        if ($newkey !== '' && !config::is_valid_app_key($newkey)) {
            $errors['appkey'] = get_string('invalidappkey', 'tool_courseproctoring');
        }
        if ($newkey !== '' && $clear) {
            $errors['clearappkey'] = get_string('appkeyclearconflict', 'tool_courseproctoring');
        }

        $willhavekey = $newkey !== '' || (config::has_app_key() && !$clear);
        if (!empty($data['vendorenabled']) && !$willhavekey) {
            $errors['vendorenabled'] = get_string('vendorenablednokey', 'tool_courseproctoring');
        }
        return $errors;
    }
}
