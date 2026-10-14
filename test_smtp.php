<?php

// Enable Moodle debugging.
define('DEBUG_DEVELOPER', true);
define('DISABLE_MOODLE_DEBUG_DISPLAY', false);

require_once(__DIR__ . '/config.php');
require_once($CFG->libdir . '/moodlelib.php');

// Show PHP errors.
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

$recipient = 'moodle@techversantinfotech.com';

$subject = 'Moodle SMTP Test';

$message = "This is a test email sent using Moodle's configured SMTP settings.\n\n"
         . "From: noreply@techversantinfotech.com\n"
         . "Site: " . $CFG->wwwroot . "\n"
         . "Time: " . date('Y-m-d H:i:s') . "\n";

$from = \core_user::get_support_user();

echo "<pre>";

echo "=== Moodle SMTP Test ===\n\n";
echo "Moodle URL : " . $CFG->wwwroot . "\n";
echo "From       : " . $from->email . "\n";
echo "To         : " . $recipient . "\n\n";

echo "Attempting to send email...\n\n";

try {

    $sent = email_to_user(
        (object)[
            'id' => -1,
            'email' => $recipient,
            'firstname' => 'SMTP',
            'lastname' => 'Test',
            'maildisplay' => 1,
            'mailformat' => FORMAT_PLAIN,
        ],
        $from,
        $subject,
        $message
    );

    if ($sent) {
        echo "SUCCESS: Email was accepted by Moodle's mail system.\n";
    } else {
        echo "FAILED: Moodle's email system returned FALSE.\n";
        echo "Check the Moodle/PHP error output above or the Moodle logs.\n";
    }

} catch (Throwable $e) {

    echo "EXCEPTION:\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File   : " . $e->getFile() . "\n";
    echo "Line   : " . $e->getLine() . "\n\n";

    echo "Stack trace:\n";
    echo $e->getTraceAsString();
}

echo "</pre>";

