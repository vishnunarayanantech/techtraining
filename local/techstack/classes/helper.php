<?php

namespace local_techstack;

defined('MOODLE_INTERNAL') || die();

// DISABLED 2026-11-12 at the site owner's request: local_techstack no longer does any proctoring (course or quiz).
// Proctoring is now done only by tool_quizproctoring (quizzes) and tool_courseproctoring (courses).
// Nothing is deleted: the original code is kept below as comments, so it can be read, or switched on again.
// This file read and wrote the quiz Proctor Key (table local_quizcustomfield). The keys already saved stay in the
// database; nothing is deleted there.
// Where the old code switched PHP off or on (its closing and opening tags), a space was added inside the tag in these comments, so that PHP does not leave the comment.
//
//
// class helper {
//
//     public static function save($cmid, $text) {
//
//         global $DB;
//
//         $record = $DB->get_record(
//             'local_quizcustomfield',
//             ['cmid' => $cmid]
//         );
//
//         if ($record) {
//
//             $record->customtext = $text;
//
//             $DB->update_record(
//                 'local_quizcustomfield',
//                 $record
//             );
//
//         } else {
//
//             $record = new \stdClass();
//
//             $record->cmid = $cmid;
//             $record->customtext = $text;
//
//             $DB->insert_record(
//                 'local_quizcustomfield',
//                 $record
//             );
//         }
//     }
//
//     public static function get($cmid) {
//
//         global $DB;
//
//         return $DB->get_field(
//             'local_quizcustomfield',
//             'customtext',
//             ['cmid'=>$cmid]
//         );
//     }
// }
//
