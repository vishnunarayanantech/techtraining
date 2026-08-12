<?php
require_once('../../config.php');

$id = required_param('id', PARAM_INT); // Techstack ID

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context); // Or use a custom capability

$returnurl = new moodle_url('/local/techstack/techstack_listing.php');

// Check if record exists
$techstack = $DB->get_record('techstack', ['id' => $id], '*', MUST_EXIST);

// Confirm deletion
$confirm = optional_param('confirm', 0, PARAM_BOOL);

if ($confirm && confirm_sesskey()) {

    // Delete record
    $DB->delete_records('techstack', ['id' => $id]);

    redirect(
        $returnurl,
        "Tech Category <strong>" . format_string($techstack->tech_category) . "</strong> deleted successfully.",
        2,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Page setup
$PAGE->set_url('/local/techstack/delete_techstack.php', ['id' => $id]);
$PAGE->set_title('Delete Tech Category');
$PAGE->set_heading('Delete Tech Category');

echo $OUTPUT->header();

// Confirm box
$deleteurl = new moodle_url('/local/techstack/delete_techstack.php', [
    'id' => $id,
    'confirm' => 1,
    'sesskey' => sesskey()
]);

echo $OUTPUT->confirm(
    "Are you sure you want to delete the Tech Category <strong>" . format_string($techstack->tech_category) . "</strong>?",
    $deleteurl,
    $returnurl
);

echo $OUTPUT->footer();
