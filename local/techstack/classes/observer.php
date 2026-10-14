<?php

namespace local_techstack;

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
         *
         * relateduserid is the user who completed the course. userid is
         * whoever triggered the action, which can be a teacher/admin, or
         * 0 when completion is aggregated by cron.
         */
        $userid =
            (int)$event->relateduserid;


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
            $sessionid,
            $courseid
        );
    }


    /**
     * Call external proctoring close API.
     *
     * This runs inside the request that completed the course, which can be
     * an AJAX call (e.g. "Mark as done") whose response is JSON. So it must
     * never output anything, and a proctoring API failure must never break
     * course completion.
     */
    private static function close_external_session(
        string $sessionid,
        int $courseid
    ): void {
        global $CFG;

        require_once($CFG->libdir . '/filelib.php');
        require_once($CFG->dirroot . '/local/techstack/helper.php');

        $customtext = local_techstack_get_course_customtext($courseid);

        /*
         * Course has no proctor key, nothing to close.
         */
        if (empty($customtext)) {
            return;
        }

        $proctorclosesessionparams = [
            'session_id_or_external' => $sessionid,
            'app_key' => 'bee923f2-9ceb-4dec-9d30-b937ca6bdf6f',
            'key_id' => $customtext,
        ];

        try {
            $proctorcurl = new \curl();
            $proctorcurl->setHeader('Content-Type: application/json');
            $proctorcurl->setHeader('x-api-key: bee923f2-9ceb-4dec-9d30-b937ca6bdf6f');

            /*
             * Short timeouts: this is a blocking call inside the
             * completion request.
             */
            $response = $proctorcurl->post(
                'https://proctoring.api.techversantinfotech.com/proctor-client/session/close',
                json_encode($proctorclosesessionparams),
                [
                    'CURLOPT_CONNECTTIMEOUT' => 3,
                    'CURLOPT_TIMEOUT' => 5,
                ]
            );

            /*
             * curl::post() does not throw, it returns the error text
             * instead, so check the result explicitly.
             */
            $info = $proctorcurl->get_info();
            $httpcode = (int)($info['http_code'] ?? 0);

            if ($proctorcurl->get_errno() || $httpcode < 200 || $httpcode >= 300) {
                error_log(
                    'local_techstack: closing proctor session ' . $sessionid .
                    ' failed (curl errno ' . $proctorcurl->get_errno() .
                    ', HTTP ' . $httpcode . '): ' . substr((string)$response, 0, 200)
                );
            }
        } catch (\Throwable $e) {
            error_log(
                'local_techstack: closing proctor session ' . $sessionid .
                ' threw: ' . $e->getMessage()
            );
        }
    }
}
