<?php

namespace local_techstack;

defined('MOODLE_INTERNAL') || die();

// DISABLED 2026-11-12 at the site owner's request: local_techstack no longer does any proctoring (course or quiz).
// Proctoring is now done only by tool_quizproctoring (quizzes) and tool_courseproctoring (courses).
// Nothing is deleted: the original code is kept below as comments, so it can be read, or switched on again.
// This file held the "Proctor Key Settings" fields of the COURSE settings form (show, load, save).
// Their registration in db/hooks.php was already switched off on 2026-11-10.
// Where the old code switched PHP off or on (its closing and opening tags), a space was added inside the tag in these comments, so that PHP does not leave the comment.
//
//
//
// class hook_callbacks {
//
//     /**
//      * Add custom field to Course settings form.
//      *
//      * @param \core_course\hook\after_form_definition $hook
//      * @return void
//      */
//     public static function after_form_definition(
//         \core_course\hook\after_form_definition $hook
//     ): void {
//
//         $mform = $hook->mform;
//
//         $mform->addElement(
//             'header',
//             'local_techstack_course_settings',
//             'Proctor Key Settings'
//         );
//
//         $mform->addElement(
//             'text',
//             'localtechstackcoursetext',
//             'Proctor Key'
//         );
//
//         $mform->setType(
//             'localtechstackcoursetext',
//             PARAM_TEXT
//         );
//     }
//
//
//     /**
//      * Load existing Course custom field.
//      *
//      * @param \core_course\hook\after_form_definition_after_data $hook
//      * @return void
//      */
//     public static function after_form_definition_after_data(
//         \core_course\hook\after_form_definition_after_data $hook
//     ): void {
//
//         global $DB;
//
//         $course = $hook->formwrapper->get_course();
//
//         if (empty($course->id)) {
//             return;
//         }
//
//         $customtext = $DB->get_field(
//             'local_techstack_course',
//             'customtext',
//             ['courseid' => $course->id]
//         );
//
//         if ($customtext !== false) {
//
//             $hook->mform->setDefault(
//                 'localtechstackcoursetext',
//                 $customtext
//             );
//         }
//     }
//
//
//     /**
//      * Save Course custom field.
//      *
//      * @param \core_course\hook\after_form_submission $hook
//      * @return void
//      */
//     public static function after_form_submission(
//         \core_course\hook\after_form_submission $hook
//     ): void {
//
//         require_once(__DIR__ . '/../helper.php');
//
//         // Moodle 4.5 provides submitted data through get_data().
//         $data = $hook->get_data();
//
//         if (empty($data->id)) {
//             return;
//         }
//
//         $courseid = $data->id;
//
//         $customtext = '';
//
//         if (isset($data->localtechstackcoursetext)) {
//
//             $customtext = trim(
//                 $data->localtechstackcoursetext
//             );
//         }
//
//         local_techstack_save_course_customtext(
//             $courseid,
//             $customtext
//         );
//     }
// }
