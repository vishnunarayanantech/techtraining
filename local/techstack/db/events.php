<?php

defined('MOODLE_INTERNAL') || die();

$observers = [

    [
        'eventname' =>
            '\core\event\course_completed',

        'callback' =>
            '\local_techstack\observer::course_completed',

    ],

];
