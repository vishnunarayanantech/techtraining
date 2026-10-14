<?php
require_once(__DIR__ . '/config.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

global $DB, $PAGE, $OUTPUT, $USER;

$PAGE->set_url('/loginscript.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('OAuth2 Login Update Script');
$PAGE->set_heading('OAuth2 Login Update Script');

echo $OUTPUT->header();
echo "<pre>";

try {

    $transaction = $DB->start_delegated_transaction();
    $time = time();

    $users = $DB->get_records_select(
        'user',
        "id NOT IN (?, ?)",
        [1, 2],
        '',
        'id, email'
    );

    foreach ($users as $user) {

        if (empty($user->email)) {
            echo "Skipping user {$user->id} (No email)\n";
            continue;
        }

        // -----------------------------
        // Update mdl_user
        // -----------------------------
        $updateuser = new stdClass();
        $updateuser->id       = $user->id;
        $updateuser->auth     = 'oauth2';
        $updateuser->username = $user->email;

        $DB->update_record('user', $updateuser);

        // -----------------------------
        // Check if oauth record exists
        // -----------------------------
        $oauthrecord = $DB->get_record(
            'auth_oauth2_linked_login',
            ['userid' => $user->id]
        );

        if ($oauthrecord) {

            // Update existing record
            $oauthrecord->confirmtoken = '';
            $oauthrecord->confirmtokenexpires = 0;
            $oauthrecord->timemodified = $time;

            $DB->update_record('auth_oauth2_linked_login', $oauthrecord);

            echo "Updated OAuth record for user {$user->id}\n";

        } else {

            // Insert new record
            $newoauth = new stdClass();
            $newoauth->timecreated = $time;
            $newoauth->timemodified = $time;
            $newoauth->usermodified = $USER->id;
            $newoauth->userid = $user->id;
            $newoauth->issuerid = 1;
            $newoauth->username = $user->email;
            $newoauth->email = $user->email;
            $newoauth->confirmtoken = '';
            $newoauth->confirmtokenexpires = 0;

            $DB->insert_record('auth_oauth2_linked_login', $newoauth);

            echo "Inserted OAuth record for user {$user->id}\n";
        }
    }

    $transaction->allow_commit();
    echo "\nDONE SUCCESSFULLY\n";

} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage();
}

echo "</pre>";
echo $OUTPUT->footer();
