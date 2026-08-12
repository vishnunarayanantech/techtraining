<?php

defined('MOODLE_INTERNAL') || die();

/**
 * Add custom fields to the course module settings form.
 *
 * This callback is called when editing/creating activities.
 *
 * @param mixed $formwrapper
 * @param MoodleQuickForm $mform
 * @return void
 */
function local_techstack_coursemodule_standard_elements($formwrapper, $mform) {

    $current = $formwrapper->get_current();

    // Only add the field to Quiz activities.
    if (empty($current->modulename) || $current->modulename !== 'quiz') {
        return;
    }

    // Add a settings section.
    $mform->addElement(
        'header',
        'local_techstack_quiz_settings',
        'Proctor Key Settings'
    );

    // Add custom text field.
    $mform->addElement(
        'text',
        'localquizcustomtext',
        'Proctor Key'
    );

    // Moodle parameter type.
    $mform->setType(
        'localquizcustomtext',
        PARAM_TEXT
    );

    // Optional help button.
    $mform->addHelpButton(
        'localquizcustomtext',
        'customquiztext',
        'local_techstack'
    );
}


/**
 * Load existing custom field value when editing a Quiz.
 *
 * @param mixed $formwrapper
 * @param MoodleQuickForm $mform
 * @return void
 */
function local_techstack_coursemodule_definition_after_data($formwrapper, $mform) {

    global $DB;

    $current = $formwrapper->get_current();

    // Only process Quiz.
    if (empty($current->modulename) || $current->modulename !== 'quiz') {
        return;
    }

    // Existing course module ID.
    if (empty($current->coursemodule)) {
        return;
    }

    $cmid = $current->coursemodule;

    // Get saved custom value.
    $customtext = $DB->get_field(
        'local_techstack',
        'customtext',
        ['cmid' => $cmid]
    );

    if ($customtext !== false) {
        $mform->setDefault(
            'localquizcustomtext',
            $customtext
        );
    }
}


/**
 * Save custom Quiz field after creating/updating the activity.
 *
 * Moodle 4.5 callback signature:
 *
 * @param stdClass $moduleinfo
 * @param stdClass $course
 * @return stdClass
 */
function local_techstack_coursemodule_edit_post_actions($moduleinfo, $course) {

    global $DB;

    // Only process Quiz.
    if (empty($moduleinfo->modulename) ||
            $moduleinfo->modulename !== 'quiz') {

        return $moduleinfo;
    }

    // Course module ID.
    if (empty($moduleinfo->coursemodule)) {
        return $moduleinfo;
    }

    $cmid = $moduleinfo->coursemodule;

    // Get submitted custom text.
    $customtext = '';

    if (isset($moduleinfo->localquizcustomtext)) {
        $customtext = trim($moduleinfo->localquizcustomtext);
    }

    // Check if record already exists.
    $record = $DB->get_record(
        'local_techstack',
        ['cmid' => $cmid]
    );

    if ($record) {

        // Update existing record.
        $record->customtext = $customtext;

        $DB->update_record(
            'local_techstack',
            $record
        );

    } else {

        // Insert new record.
        $record = new stdClass();

        $record->cmid = $cmid;
        $record->customtext = $customtext;

        $DB->insert_record(
            'local_techstack',
            $record
        );
    }

    // IMPORTANT:
    // Moodle expects the module information object back.
    return $moduleinfo;
}
