<?php

namespace local_courseproctor\hook\output;

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
        $params = [

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

        /*
         * Load AMD module.
         */
        $PAGE->requires->js_call_amd(
            'local_courseproctor/proctor',
            'init',
            [$params]
        );
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
