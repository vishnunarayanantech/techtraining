<?php

namespace local_techstack;

defined('MOODLE_INTERNAL') || die();


class hook_callbacks {

    /**
     * Add custom field to Course settings form.
     *
     * @param \core_course\hook\after_form_definition $hook
     * @return void
     */
    public static function after_form_definition(
        \core_course\hook\after_form_definition $hook
    ): void {

        $mform = $hook->mform;

        $mform->addElement(
            'header',
            'local_techstack_course_settings',
            'Proctor Key Settings'
        );

        $mform->addElement(
            'text',
            'localtechstackcoursetext',
            'Proctor Key'
        );

        $mform->setType(
            'localtechstackcoursetext',
            PARAM_TEXT
        );
    }


    /**
     * Load existing Course custom field.
     *
     * @param \core_course\hook\after_form_definition_after_data $hook
     * @return void
     */
    public static function after_form_definition_after_data(
        \core_course\hook\after_form_definition_after_data $hook
    ): void {

        global $DB;

        $course = $hook->formwrapper->get_course();

        if (empty($course->id)) {
            return;
        }

        $customtext = $DB->get_field(
            'local_techstack_course',
            'customtext',
            ['courseid' => $course->id]
        );

        if ($customtext !== false) {

            $hook->mform->setDefault(
                'localtechstackcoursetext',
                $customtext
            );
        }
    }


    /**
     * Save Course custom field.
     *
     * @param \core_course\hook\after_form_submission $hook
     * @return void
     */
    public static function after_form_submission(
        \core_course\hook\after_form_submission $hook
    ): void {

        require_once(__DIR__ . '/../helper.php');

        // Moodle 4.5 provides submitted data through get_data().
        $data = $hook->get_data();

        if (empty($data->id)) {
            return;
        }

        $courseid = $data->id;

        $customtext = '';

        if (isset($data->localtechstackcoursetext)) {

            $customtext = trim(
                $data->localtechstackcoursetext
            );
        }

        local_techstack_save_course_customtext(
            $courseid,
            $customtext
        );
    }
}
