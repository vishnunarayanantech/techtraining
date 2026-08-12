<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/formslib.php');

class edit_techstack_form extends moodleform {

    public function definition() {
        $mform = $this->_form;

        // Hidden field (tech category id).
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        // Header.
        // $mform->addElement('header', 'general', get_string('edittechcategory', 'local_techstack'));

        // Tech Category name.
        $mform->addElement('text', 'tech_category', get_string('techcategory', 'local_techstack'));
        $mform->setType('tech_category', PARAM_TEXT);
        $mform->addRule('tech_category', null, 'required', null, 'client');

        // User list (multi-select).
        $users = $this->get_active_users();
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

        // Save/Cancel buttons.
        $this->add_action_buttons(true, get_string('savechanges'));
    }

    private function get_active_users() {
        global $DB;

        $records = $DB->get_records_sql("
            SELECT id, CONCAT(firstname, ' ', lastname) AS fullname
            FROM {user}
            WHERE suspended = 0 AND deleted = 0
            ORDER BY fullname
        ");

        $users = [];
        foreach ($records as $u) {
            $users[$u->id] = $u->fullname;
        }
        return $users;
    }
}
