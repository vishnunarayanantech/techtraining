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
 * Strings for component 'tool_quizproctoring', language 'en'.
 *
 * @package    tool_quizproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['appkey'] = 'App Key';
$string['appkey_help'] = 'The proctoring App Key. It is the same for the whole site. It is stored encrypted and is never shown again after saving.

Note: the proctoring widget runs in the learner\'s browser and needs this key, so it is sent to the browser on quiz attempt pages of proctored courses.';
$string['appkeyclearconflict'] = 'Enter a new App Key or choose to remove the saved one, not both.';
$string['appkeynew'] = 'New App Key';
$string['appkeynotset'] = 'No App Key saved.';
$string['appkeysaved'] = 'An App Key is saved ({$a}). Leave the field below empty to keep it.';
$string['appkeyunreadable'] = 'An App Key is stored but cannot be read (for example the site\'s encryption key was replaced). Enter the App Key again.';
$string['clearappkey'] = 'Remove the saved App Key';
$string['closeretry'] = 'Closing the proctoring session of quiz attempt {$a} failed; it will be retried.';
$string['event_quizkeyupdated'] = 'Quiz proctoring Key ID of a quiz updated';
$string['event_settingsupdated'] = 'Quiz proctoring settings updated';
$string['invalidappkey'] = 'The App Key may only contain printable characters without spaces (up to 255).';
$string['invalidkeyid'] = 'A Key ID may only contain letters, numbers, hyphens and underscores (up to 255 characters).';
$string['pluginname'] = 'Quiz proctoring';
$string['privacy:metadata:session'] = 'The proctoring sessions this plugin started for quiz attempts, so that each can be closed when the attempt ends.';
$string['privacy:metadata:session:attemptid'] = 'The quiz attempt, which is also the proctoring session id.';
$string['privacy:metadata:session:closeattempts'] = 'How many times closing the session failed.';
$string['privacy:metadata:session:cmid'] = 'The quiz activity.';
$string['privacy:metadata:session:courseid'] = 'The course.';
$string['privacy:metadata:session:keyid'] = 'The quiz\'s proctoring Key ID the session was started with.';
$string['privacy:metadata:session:lasterror'] = 'A short description of the last failure to close the session.';
$string['privacy:metadata:session:quizid'] = 'The quiz.';
$string['privacy:metadata:session:status'] = 'Whether the session is active, closed or failed.';
$string['privacy:metadata:session:timeclosed'] = 'When the session was closed.';
$string['privacy:metadata:session:timecreated'] = 'When the session was started.';
$string['privacy:metadata:session:timemodified'] = 'When the record last changed.';
$string['privacy:metadata:session:userid'] = 'The learner the session belongs to.';
$string['privacy:metadata:vendor'] = 'To proctor a quiz attempt, the learner\'s details are sent to the external proctoring service. The proctoring widget that runs in the learner\'s browser may collect further data (for example camera, screen or behaviour data) under the service\'s own privacy notice; Moodle privacy requests cannot reach that data.';
$string['privacy:metadata:vendor:attemptid'] = 'The quiz attempt, used as the proctoring session id.';
$string['privacy:metadata:vendor:coursename'] = 'The full name of the course.';
$string['privacy:metadata:vendor:email'] = 'The learner\'s e-mail address.';
$string['privacy:metadata:vendor:firstname'] = 'The learner\'s first name.';
$string['privacy:metadata:vendor:keyid'] = 'The quiz\'s proctoring Key ID.';
$string['privacy:metadata:vendor:lastname'] = 'The learner\'s last name.';
$string['privacy:metadata:vendor:quizid'] = 'The quiz id.';
$string['privacy:metadata:vendor:userid'] = 'The learner\'s Moodle user id.';
$string['privacy:metadata:vendor:username'] = 'The learner\'s user name.';
$string['privacy:path'] = 'Quiz proctoring sessions';
$string['quizkey'] = 'Proctor Key';
$string['quizkey_help'] = 'The proctoring Key ID of this quiz. Only quizzes that have a Key ID here are proctored; leave it empty for no proctoring. Letters, numbers, hyphens and underscores only.

Proctoring also needs the App Key and the "Enable vendor integration" switch on the Quiz proctoring administration page. Only site administrators can see and change this field.';
$string['quizkeyheader'] = 'Proctor Key Settings';
$string['settingssavedoff'] = 'Settings saved. The vendor integration is still OFF, so no quiz is being proctored. Tick "Enable vendor integration" below and save to start.';
$string['settingssavedon'] = 'Settings saved. The vendor integration is ON.';
$string['settingssavedonwithoutkey'] = 'Settings saved, but the vendor integration is ON without a usable App Key, so nothing can be proctored. Enter the App Key.';
$string['sitesettings'] = 'Site-wide settings';
$string['status_active'] = 'Active';
$string['status_closed'] = 'Closed';
$string['status_failed'] = 'Failed';
$string['statusoff'] = 'The vendor integration is OFF: no quiz is being proctored by this plugin, whatever App Key and Key IDs are saved. Tick "Enable vendor integration" below and save to start. Read docs/VENDOR_QUESTIONS.md before you do.';
$string['statuson'] = 'The vendor integration is ON: quizzes in courses that have a Key ID are proctored.';
$string['statusonwithoutkey'] = 'The vendor integration is ON but no usable App Key is saved, so nothing can be proctored. Enter the App Key below.';
$string['task_closesession'] = 'Close the proctoring session of a quiz attempt';
$string['vendorenabled'] = 'Enable vendor integration';
$string['vendorenabled_desc'] = 'Start and close proctoring sessions with the external proctoring service. Leave off until the open questions in docs/VENDOR_QUESTIONS.md are answered.';
$string['vendorenablednokey'] = 'Save an App Key before enabling the vendor integration.';
