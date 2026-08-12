<?php
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/edit_techstack_form.php');

$id = required_param('id', PARAM_INT);

$PAGE->set_url(new moodle_url('/local/techstack/edit_techstack.php', ['id' => $id]));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Edit Tech Category');
$PAGE->set_heading('Edit Tech Category');

require_login();

global $DB;

// Fetch record for editing
$record = $DB->get_record('techstack', ['id' => $id], '*', MUST_EXIST);

// Convert comma-separated user ids into array
$record->user_list = explode(',', $record->manager);

// Load form with data
$mform = new edit_techstack_form();
$mform->set_data($record);

if ($mform->is_cancelled()) {
    redirect(new moodle_url('/local/techstack/techstack_listing.php'));
}
else if ($data = $mform->get_data()) {

    $update = new stdClass();
    $update->id = $data->id;
    $update->tech_category = $data->tech_category;
    $update->manager = implode(',', $data->user_list);

    $DB->update_record('techstack', $update);

    redirect(
        new moodle_url('/local/techstack/techstack_listing.php'),
        "Tech category updated successfully."
    );
}

echo $OUTPUT->header();
$mform->display();
echo $OUTPUT->footer();
