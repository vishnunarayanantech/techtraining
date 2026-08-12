<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/formslib.php');

class mapcourses_form extends moodleform {

    public function definition() {
        $mform = $this->_form;

        // Hidden field for tech category ID.
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        // Header.
        // $mform->addElement('header', 'general', 'Map Courses to Tech Category');

        // Multi-select dropdown of courses.
        $courses = $this->get_active_courses();

        $mform->addElement(
            'autocomplete',
            'course_list',
            'Select Courses',
            $courses,
            [
                'multiple' => true,
                'placeholder' => 'Choose courses'
            ]
        );

        $mform->setType('course_list', PARAM_SEQUENCE);

        // Action buttons.
        $this->add_action_buttons(true, 'Save Mapping');
    }

    private function get_active_courses() {
        global $DB;

        $records = $DB->get_records_sql("
            SELECT id, fullname
            FROM {course}
            WHERE id > 1 AND visible = 1
            ORDER BY fullname
        ");

        $list = [];
        foreach ($records as $c) {
            $list[$c->id] = $c->fullname;
        }

        return $list;
    }
}
