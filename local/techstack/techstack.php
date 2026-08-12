<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/formslib.php');
require_once(__DIR__ . '/techstack_form.php');

// Instantiate and display the form.
$PAGE->set_url(new moodle_url('/local/techstack/techstack_form.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('techstackform', 'local_techstack'));
$PAGE->set_heading(get_string('techstackform', 'local_techstack'));

require_login();

$mform = new techstack_form();

if ($mform->is_cancelled()) {
    // Handle form cancel operation.
    redirect(new moodle_url('/'));
} else if ($data = $mform->get_data()) {
    // Handle form submission.
    global $DB;

        $record = new stdClass();
        $record->tech_category = $data->tech_category;
        $record->manager = implode(',', $data->user_list);

        $DB->insert_record('techstack', $record);

    redirect(new moodle_url('/local/techstack/techstack.php'), get_string('formsubmitted', 'local_techstack'));
}

// Display the form.
echo $OUTPUT->header();
$mform->display();
echo $OUTPUT->footer();