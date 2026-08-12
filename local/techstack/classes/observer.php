<?php

namespace local_courseproctor;

defined('MOODLE_INTERNAL') || die();

class observer {

    /**
     * Called when a user completes a course.
     */
    public static function course_completed(
        \core\event\course_completed $event
    ): void {

        /*
         * User who completed course.
         */
        $userid =
            (int)$event->userid;


        /*
         * Course that was completed.
         */
        $courseid =
            (int)$event->courseid;


        /*
         * Build EXACT SAME session ID
         * that JavaScript used.
         */
        $sessionid =
            'moodle_' .
            $userid .
            '_' .
            $courseid;


        /*
         * Close external proctor session.
         */
        self::close_external_session(
            $sessionid
        );
    }


    /**
     * Call external proctoring close API.
     */
    private static function close_external_session(
        string $sessionid
    ): void {

         $proctorclosesessionparams = [
        'session_id_or_external' => $sessionid,
        'organization_id' => 44,
        'key_id' => '',
    ];
    $proctorcurl = new curl();
    $proctorcurl->setHeader('Content-Type: application/json');
    $proctorcurl->post('https://proctoring.api.techversantinfotech.com/proctor-client/session/close',
            json_encode($proctorclosesessionparams));

             
    }
}
