<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/formslib.php');

class techstack_form extends moodleform {
    // Define the form.
    protected function definition() {
        $mform = $this->_form;

        // Text field for 'tech category'.
        $mform->addElement('text', 'tech_category', get_string('techcategory', 'local_techstack'));
        $mform->setType('tech_category', PARAM_TEXT);
        $mform->addRule('tech_category', null, 'required', null, 'client');

        // Dropdown with multi-select for unsuspended, not deleted users.
        $users = $this->get_active_users();
        // Multi-select dropdown with a label "Select users".
        $mform->addElement(
            'autocomplete',
            'user_list',
            get_string('userlist', 'local_techstack'),
            $users,
            [
                'multiple' => true,
                'placeholder' => get_string('userlist', 'local_techstack')
            ]
        );
        
        $mform->setType('user_list', PARAM_SEQUENCE);


        // Add action buttons.
        $this->add_action_buttons();

         // Add custom button to go to listing page
        $listingurl = new moodle_url('/local/techstack/techstack_listing.php');
        $mform->addElement('html', '<div style="margin-top:10px;">');
        $mform->addElement('html', '<a class="btn btn-secondary" href="' . $listingurl . '">Go to Listing Page</a>');
        $mform->addElement('html', '</div>');
    }

    // Fetch unsuspended, not deleted users.
    private function get_active_users() {
        global $DB;
        $users = $DB->get_records_sql("
            SELECT id, CONCAT(firstname, ' ', lastname) AS fullname
            FROM {user}
            WHERE suspended = 0 AND deleted = 0
            ORDER BY fullname
        ");

        $userlist = [];
        foreach ($users as $user) {
            $userlist[$user->id] = $user->fullname;
        }

        return $userlist;
    }
    
}

