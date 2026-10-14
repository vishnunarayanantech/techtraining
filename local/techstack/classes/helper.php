<?php

namespace local_techstack;

defined('MOODLE_INTERNAL') || die();

class helper {

    public static function save($cmid, $text) {

        global $DB;

        $record = $DB->get_record(
            'local_quizcustomfield',
            ['cmid' => $cmid]
        );

        if ($record) {

            $record->customtext = $text;

            $DB->update_record(
                'local_quizcustomfield',
                $record
            );

        } else {

            $record = new \stdClass();

            $record->cmid = $cmid;
            $record->customtext = $text;

            $DB->insert_record(
                'local_quizcustomfield',
                $record
            );
        }
    }

    public static function get($cmid) {

        global $DB;

        return $DB->get_field(
            'local_quizcustomfield',
            'customtext',
            ['cmid'=>$cmid]
        );
    }
}

