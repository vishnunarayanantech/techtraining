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

        ?>
        <script>
 window.proctorClient.endSession("proctor-widget-root");
</script>
        <?php
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
	  global $COURSE, $CFG;
	  require_once($CFG->dirroot . '/local/techstack/helper.php');
	  //global $COURSE, $CFG;
	  $customtext = local_techstack_get_course_customtext($COURSE->id);  
if(!empty($customtext)){
         $proctorclosesessionparams = [
        'session_id_or_external' => $sessionid,
        'app_key' => 'bee923f2-9ceb-4dec-9d30-b937ca6bdf6f',
        'key_id' => $customtext,
    ];
    $proctorcurl = new \curl();
	 $proctorcurl->setHeader('Content-Type: application/json');
	 $proctorcurl->setHeader('x-api-key: bee923f2-9ceb-4dec-9d30-b937ca6bdf6f');
    $proctorcurl->post('https://proctoring.api.techversantinfotech.com/proctor-client/session/close',
	    json_encode($proctorclosesessionparams));
    ?>

<?php
                                                     
}        
    }
}
