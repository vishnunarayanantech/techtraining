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
 * Strings for component 'tool_courseproctoring', language 'en'.
 *
 * @package    tool_courseproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['appkey'] = 'App Key';
$string['appkey_help'] = 'The proctoring App Key for courses. It is the same for the whole site. It is stored encrypted and is never shown again after saving.

Note: the proctoring widget runs in the learner\'s browser and needs this key, so it is sent to the browser on the pages of proctored courses.';
$string['appkeyclearconflict'] = 'Enter a new App Key or choose to remove the saved one, not both.';
$string['appkeynew'] = 'New App Key';
$string['appkeynotset'] = 'No App Key saved.';
$string['appkeysaved'] = 'An App Key is saved ({$a}). Leave the field below empty to keep it.';
$string['appkeyunreadable'] = 'An App Key is stored but cannot be read (for example the site\'s encryption key was replaced). Enter the App Key again.';
$string['clearappkey'] = 'Remove the saved App Key';
$string['event_settingsupdated'] = 'Course proctoring settings updated';
$string['invalidappkey'] = 'The App Key may only contain printable characters without spaces (up to 255).';
$string['pluginname'] = 'Course proctoring';
$string['settingssavedoff'] = 'Settings saved. The vendor integration is still OFF, so no course is being proctored. Tick "Enable vendor integration" below and save to start.';
$string['settingssavedon'] = 'Settings saved. The vendor integration is ON.';
$string['settingssavedonwithoutkey'] = 'Settings saved, but the vendor integration is ON without a usable App Key, so nothing can be proctored. Enter the App Key.';
$string['sitesettings'] = 'Site-wide settings';
$string['statusoff'] = 'The vendor integration is OFF: no course is being proctored by this plugin, whatever App Key and Proctor Keys are saved. Tick "Enable vendor integration" below and save to start. Read docs/VENDOR_QUESTIONS.md before you do.';
$string['statuson'] = 'The vendor integration is ON: courses that have a Proctor Key are proctored.';
$string['statusonwithoutkey'] = 'The vendor integration is ON but no usable App Key is saved, so nothing can be proctored. Enter the App Key below.';
$string['vendorenabled'] = 'Enable vendor integration';
$string['vendorenabled_desc'] = 'Start and close proctoring sessions with the external proctoring service. Leave off until the open questions in docs/VENDOR_QUESTIONS.md are answered.';
$string['vendorenablednokey'] = 'Save an App Key before enabling the vendor integration.';
