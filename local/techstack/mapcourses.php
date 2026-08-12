<?php
require_once(__DIR__ . '/../../config.php');
require_once('mapcourses_form.php');

require_login();

$techid = required_param('id', PARAM_INT);

$PAGE->set_url(new moodle_url('/local/techstack/mapcourses.php', ['id' => $techid]));
$PAGE->set_context(context_system::instance());
$PAGE->set_title("Map Courses");
$PAGE->set_heading("Map Courses");

// Create form
$mform = new mapcourses_form(null, ['id' => $techid]);

global $DB;

// Fetch existing mapping for this category
$existing = $DB->get_record('mapcourses', ['cat_id' => $techid]);

$prefill = new stdClass();
$prefill->id = $techid;

if ($existing && !empty($existing->mapped_courses)) {
    $prefill->course_list = explode(',', $existing->mapped_courses);
}

// Set form values
$mform->set_data($prefill);

if ($mform->is_cancelled()) {
    redirect(new moodle_url('/local/techstack/techstack_listing.php'));
}
else if ($data = $mform->get_data()) {

    $mapping = new stdClass();
    $mapping->cat_id = $data->id;
    $mapping->mapped_courses = implode(',', $data->course_list);

    // If exists, update. Else insert.
    if ($existing) {
        $mapping->id = $existing->id;
        $DB->update_record('mapcourses', $mapping);
    } else {
        $DB->insert_record('mapcourses', $mapping);
    }

    redirect(
        new moodle_url('/local/techstack/techstack_listing.php'),
        "Courses mapped successfully.",
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Display form
echo $OUTPUT->header();
$mform->display();
echo $OUTPUT->footer();
