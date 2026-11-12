<?php

defined('MOODLE_INTERNAL') || die();

// DISABLED 2026-11-12 at the site owner's request: local_techstack no longer does any proctoring (course or quiz).
// Proctoring is now done only by tool_quizproctoring (quizzes) and tool_courseproctoring (courses).
// Nothing is deleted: the original code is kept below as comments, so it can be read, or switched on again.
// This was the only event observer of the plugin: when a course was completed it ran
// \local_techstack\observer::course_completed (see classes/observer.php for what that did).
// The course proctoring session is now closed by tool_courseproctoring.
// Where the old code switched PHP off or on (its closing and opening tags), a space was added inside the tag in these comments, so that PHP does not leave the comment.

$observers = [];

// $observers = [
//
//     [
//         'eventname' =>
//             '\core\event\course_completed',
//
//         'callback' =>
//             '\local_techstack\observer::course_completed',
//
//     ],
//
// ];
