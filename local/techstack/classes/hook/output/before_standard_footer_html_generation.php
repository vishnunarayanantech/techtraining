<?php

namespace local_techstack\hook\output;

defined('MOODLE_INTERNAL') || die();

class before_standard_footer_html_generation {

    /**
     * Add course proctoring JavaScript.
     *
     * This runs for Moodle pages before the standard footer.
     */
    public static function callback(
        \core\hook\output\before_standard_footer_html_generation $hook
    ): void {

        global $PAGE, $COURSE, $USER;

        /*
         * User must be logged in.
         */
        if (!isloggedin() || isguestuser()) {
            return;
        }

        /*
         * We need a course.
         */
        if (empty($COURSE->id)) {
            return;
	}
//echo $COURSE->id;exit;
	if ($COURSE->id == 1) {
    		return;
         }


        /*
         * Don't run on Site Home.
         */
	if ((int)$COURSE->id === SITEID) {
            return;
        }

        $courseid = (int)$COURSE->id;

        $userid = (int)$USER->id;

        /*
         * Check whether this user has already
         * completed this course.
         *
         * If completed, don't load proctoring.
	 */
	if($USER->id == 2)
	{
		return;
	}
        if (self::is_course_completed(
            $userid,
            $courseid
        )) {
            return;
	}

        /*
         * SAME session ID for:
         *
         * userid + courseid
         *
         * Example:
         *
         * moodle_4237176_42893
         */
        $sessionid =
            'moodle_' .
            $userid .
            '_' .
            $courseid;

        /*
         * Prepare parameters for AMD JS.
         */
  /*      $params = [

            'courseid' =>
                $courseid,

            'userid' =>
                $userid,

            'sessionid' =>
                $sessionid,

            'course_name' =>
                $COURSE->fullname,

            'firstname' =>
                $USER->firstname,

            'lastname' =>
                $USER->lastname,

            'email' =>
                $USER->email,

            'username' =>
                $USER->username,
        ];

        
        $PAGE->requires->js_call_amd(
            'local_courseproctor/proctor',
            'init',
            [$params]
	);
*/

global $USER, $COURSE, $CFG;

//global $COURSE, $CFG;

require_once($CFG->dirroot . '/local/techstack/helper.php');

$customtext = local_techstack_get_course_customtext($COURSE->id);

//echo $customtext;exit;

if(!empty($customtext)){
//$cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
//$quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
//$ses_key1 = $attemptobj->get_attempt()->id;
//$quiz_id1  = $quiz->id;
$course_name1 = format_string($COURSE->fullname);
$user_id1 = $USER->id;
$first_name1 = $USER->firstname;
$last_name1 = $USER->lastname;
$email1 = $USER->email;
$user_name1 = $USER->username;

 //'key_id' => 'KEY-5E02852',

$proctorwidgetparams = [
    'key_id' => $customtext,
    'session_id' => $sessionid,
    'organization_id' => 46,
    'course_id' => $courseid,
    'course_name' => $course_name1,
    'user_id' => $user_id1,
    'first_name' => $first_name1,
    'last_name' => $last_name1,
    'email_id' => $email1,
    'redirect_url' => 'https://tech-training.demoserver.work/',
    'user_name' => $user_name1,
];
//print_r($attemptobj);exit;
//$PAGE->requires->js_call_amd('local_techstack/proctor_widget', 'init', [$proctorwidgetparams]);


}



    }


    /**
     * Check Moodle course completion.
     */
    private static function is_course_completed(
        int $userid,
        int $courseid
    ): bool {

        global $CFG;

        require_once(
            $CFG->libdir . '/completionlib.php'
        );

        try {

            $course =
                get_course($courseid);

            $completion =
                new \completion_info($course);

            return $completion->is_course_complete(
                $userid
            );

        } catch (\Throwable $e) {

            debugging(
                'Course proctoring completion check failed: ' .
                $e->getMessage(),
                DEBUG_DEVELOPER
            );

            /*
             * If completion cannot be checked,
             * don't accidentally stop proctoring.
             */
            return false;
        }
    }
}
