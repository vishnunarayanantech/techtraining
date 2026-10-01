<?php


defined('MOODLE_INTERNAL') || die();


/**
 * Get custom text for a course.
 *
 * @param int $courseid
 * @return string
 */
function local_techstack_get_course_customtext($courseid) {

    global $DB;

    $customtext = $DB->get_field(
        'local_techstack_course',
        'customtext',
        ['courseid' => $courseid]
    );

    if ($customtext === false) {
        return '';
    }

    return $customtext;
}


/**
 * Save custom text for a course.
 *
 * @param int $courseid
 * @param string $customtext
 * @return void
 */
function local_techstack_save_course_customtext(
    $courseid,
    $customtext
) {

    global $DB;

    $record = $DB->get_record(
        'local_techstack_course',
        ['courseid' => $courseid]
    );

    if ($record) {

        $record->customtext = $customtext;

        $DB->update_record(
            'local_techstack_course',
            $record
        );

    } else {

        $record = new stdClass();

        $record->courseid = $courseid;
        $record->customtext = $customtext;

        $DB->insert_record(
            'local_techstack_course',
            $record
        );
    }
}


