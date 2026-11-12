<?php

defined('MOODLE_INTERNAL') || die();

$callbacks = [

    // DISABLED 2026-11-12 at the site owner's request: local_techstack no longer does any proctoring (course or quiz).
    // This was the page-footer hook of the old course proctoring (see classes/hook/output/before_standard_footer_html_generation.php).
    // Proctoring is now done only by tool_quizproctoring (quizzes) and tool_courseproctoring (courses).
    // Nothing is deleted: the entry is kept below as comments, so it can be read, or switched on again.
    // The note from 2026-11-10 further down says this footer hook is not changed: that was true then, it is not true any more.
    //
    //     [
    //         'hook' =>
    //             \core\hook\output\before_standard_footer_html_generation::class,
    //
    //         'callback' =>
    //             [
    //                 \local_techstack\hook\output\before_standard_footer_html_generation::class,
    //                 'callback'
    //             ],
    //     ],

    // DISABLED 2026-11-10 at the site owner's request: the "Proctor Key Settings" / "Proctor Key" block of the COURSE
    // settings page (course/edit.php) is switched off, so that the Proctor Key can be entered only in the QUIZ settings
    // form, which is provided by tool_quizproctoring (admin/tool/quizproctoring/lib.php).
    // The three callbacks below work together, so they are switched off together: the saving callback would otherwise write
    // an empty value over the stored course key every time a course is saved without the field.
    // Nothing is deleted: the keys already saved stay in the local_techstack table and the footer widget hook above is not
    // changed. To switch the course block on again, remove the leading "// " from the three entries.
    //
    // [
    //     'hook' => \core_course\hook\after_form_definition::class,
    //     'callback' => '\local_techstack\hook_callbacks::after_form_definition',
    // ],
    // [
    //     'hook' => \core_course\hook\after_form_definition_after_data::class,
    //     'callback' => '\local_techstack\hook_callbacks::after_form_definition_after_data',
    // ],
    // [
    //     'hook' => \core_course\hook\after_form_submission::class,
    //     'callback' => '\local_techstack\hook_callbacks::after_form_submission',
    // ],

];
