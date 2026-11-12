<?php

namespace local_techstack;

defined('MOODLE_INTERNAL') || die();

// DISABLED 2026-11-12 at the site owner's request: local_techstack no longer does any proctoring (course or quiz).
// Proctoring is now done only by tool_quizproctoring (quizzes) and tool_courseproctoring (courses).
// Nothing is deleted: the original code is kept below as comments, so it can be read, or switched on again.
// This file held the course_completed observer of the old course proctoring. It printed a <script> block into the
// answer of the request that completed a course. That broke the "Mark as done" button, whose answer must be JSON.
// It also held the old call that closed a course proctoring session (with its keys).
// Its registration in db/events.php is switched off too.
// Where the old code switched PHP off or on (its closing and opening tags), a space was added inside the tag in these comments, so that PHP does not leave the comment.
//
//
// class observer {
//
//     /**
//      * Called when a user completes a course.
//      */
//     public static function course_completed(
//         \core\event\course_completed $event
//     ): void {
//
//         /*
//          * User who completed course.
//          *
//          * relateduserid is the user who completed the course. userid is
//          * whoever triggered the action, which can be a teacher/admin, or
//          * 0 when completion is aggregated by cron.
//          */
//         $userid =
//             (int)$event->relateduserid;
//
//
//         /*
//          * Course that was completed.
//          */
//         $courseid =
//             (int)$event->courseid;
//
//
//         /*
//          * Build EXACT SAME session ID
//          * that JavaScript used.
//          */
//  $sessionid =
//             'moodle_' .
//             $userid .
//             '_' .
//             $courseid;
//
//
//         /*
//          * Close external proctor session.
//          */
//       /*  self::close_external_session(
//             $sessionid,
//             $courseid
//       );*/
//
//         ? >
//         <script>
//  //window.proctorClient.endSession("proctor-widget-root");
// </script>
//         < ?php
//     }
//
//
//
//     /**
//      * Call external proctoring close API.
//      *
//      * This runs inside the request that completed the course, which can be
//      * an AJAX call (e.g. "Mark as done") whose response is JSON. So it must
//      * never output anything, and a proctoring API failure must never break
//      * course completion.
//      */
//     private static function close_external_session(
//         string $sessionid,
//         int $courseid
//     ): void {
// 	  global $COURSE, $CFG;
// 	  require_once($CFG->dirroot . '/local/techstack/helper.php');
// 	  //global $COURSE, $CFG;
// 	  $customtext = local_techstack_get_course_customtext($COURSE->id);
// if(!empty($customtext)){
//          $proctorclosesessionparams = [
//         'session_id_or_external' => $sessionid,
//         'app_key' => 'bee923f2-9ceb-4dec-9d30-b937ca6bdf6f',
//         'key_id' => $customtext,
//     ];
//     $proctorcurl = new \curl();
// 	 $proctorcurl->setHeader('Content-Type: application/json');
// 	 $proctorcurl->setHeader('x-api-key: bee923f2-9ceb-4dec-9d30-b937ca6bdf6f');
//     $proctorcurl->post('https://proctoring.api.techversantinfotech.com/proctor-client/session/close',
// 	    json_encode($proctorclosesessionparams));
//     ? >
//
// < ?php
//
// }
//     }
// }
