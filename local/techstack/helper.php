<?php


defined('MOODLE_INTERNAL') || die();

// DISABLED 2026-11-12 at the site owner's request: local_techstack no longer does any proctoring (course or quiz).
// Proctoring is now done only by tool_quizproctoring (quizzes) and tool_courseproctoring (courses).
// Nothing is deleted: the original code is kept below as comments, so it can be read, or switched on again.
// This file read and wrote the course Proctor Key (table local_techstack_course). The keys already saved stay in
// the database; nothing is deleted there.
// Where the old code switched PHP off or on (its closing and opening tags), a space was added inside the tag in these comments, so that PHP does not leave the comment.
//
//
//
// /**
//  * Get custom text for a course.
//  *
//  * @param int $courseid
//  * @return string
//  */
// function local_techstack_get_course_customtext($courseid) {
//
//     global $DB;
//
//     $customtext = $DB->get_field(
//         'local_techstack_course',
//         'customtext',
//         ['courseid' => $courseid]
//     );
//
//     if ($customtext === false) {
//         return '';
//     }
//
//     return $customtext;
// }
//
//
// /**
//  * Save custom text for a course.
//  *
//  * @param int $courseid
//  * @param string $customtext
//  * @return void
//  */
// function local_techstack_save_course_customtext(
//     $courseid,
//     $customtext
// ) {
//
//     global $DB;
//
//     $record = $DB->get_record(
//         'local_techstack_course',
//         ['courseid' => $courseid]
//     );
//
//     if ($record) {
//
//         $record->customtext = $customtext;
//
//         $DB->update_record(
//             'local_techstack_course',
//             $record
//         );
//
//     } else {
//
//         $record = new stdClass();
//
//         $record->courseid = $courseid;
//         $record->customtext = $customtext;
//
//         $DB->insert_record(
//             'local_techstack_course',
//             $record
//         );
//     }
// }
//
//
