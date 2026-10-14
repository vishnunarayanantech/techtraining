<?php

defined('MOODLE_INTERNAL') || die();

$callbacks = [

    [
        'hook' =>
            \core\hook\output\before_standard_footer_html_generation::class,

        'callback' =>
            [
                \local_techstack\hook\output\before_standard_footer_html_generation::class,
                'callback'
            ],
    ],

    [
        'hook' => \core_course\hook\after_form_definition::class,
        'callback' => '\local_techstack\hook_callbacks::after_form_definition',
    ],
    [
        'hook' => \core_course\hook\after_form_definition_after_data::class,
        'callback' => '\local_techstack\hook_callbacks::after_form_definition_after_data',
    ],
    [
        'hook' => \core_course\hook\after_form_submission::class,
        'callback' => '\local_techstack\hook_callbacks::after_form_submission',
    ],

];
