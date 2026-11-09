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
 * Quiz proctoring administration page: the site-wide App Key and the vendor-integration switch.
 *
 * Site administrators only (moodle/site:config). Every write requires a valid session key.
 *
 * @package    tool_quizproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('tool_quizproctoring');
require_capability('moodle/site:config', context_system::instance());

use tool_quizproctoring\form\settings_form;
use tool_quizproctoring\local\settings_manager;
use tool_quizproctoring\output\status_notice;

$url = new moodle_url('/admin/tool/quizproctoring/index.php');
$PAGE->set_url($url);
$PAGE->set_title(get_string('pluginname', 'tool_quizproctoring'));
$PAGE->set_heading(get_string('pluginname', 'tool_quizproctoring'));

// Site-wide settings (moodleform verifies the session key in get_data()).
$settingsform = new settings_form($url);
if ($data = $settingsform->get_data()) {
    (new settings_manager())->save((string) $data->appkey, !empty($data->clearappkey), !empty($data->vendorenabled));
    // Say what the saved state means: saving an App Key does NOT start proctoring unless the switch is ticked.
    [$message, $type] = status_notice::after_save();
    redirect($url, $message, null, $type);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'tool_quizproctoring'));
[$statusmessage, $statustype] = status_notice::current();
echo $OUTPUT->notification($statusmessage, $statustype);
$settingsform->display();
echo $OUTPUT->footer();
