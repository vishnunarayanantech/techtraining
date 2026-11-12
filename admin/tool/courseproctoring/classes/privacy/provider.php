<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace tool_courseproctoring\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use tool_courseproctoring\local\session_repository;

/**
 * Privacy provider.
 *
 * Personal data held by Moodle: the proctoring session ledger (tool_courseproctoring_session), one row per learner and
 * course. Personal data sent out of Moodle: the learner's identity, sent to the proctoring service when the learner's
 * session starts. A Moodle privacy request cannot reach the vendor's copy; that is stated in the metadata, and the export
 * includes the session id so that the vendor can be asked about it. The table of Proctor Keys holds no personal data.
 *
 * @package    tool_courseproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /** @var string Session ledger table. */
    private const TABLE = 'tool_courseproctoring_session';

    #[\Override]
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(self::TABLE, [
            'userid' => 'privacy:metadata:session:userid',
            'courseid' => 'privacy:metadata:session:courseid',
            'keyid' => 'privacy:metadata:session:keyid',
            'sessionid' => 'privacy:metadata:session:sessionid',
            'status' => 'privacy:metadata:session:status',
            'closeattempts' => 'privacy:metadata:session:closeattempts',
            'lasterror' => 'privacy:metadata:session:lasterror',
            'timecreated' => 'privacy:metadata:session:timecreated',
            'timemodified' => 'privacy:metadata:session:timemodified',
            'timeclosed' => 'privacy:metadata:session:timeclosed',
        ], 'privacy:metadata:session');

        $collection->link_external_location('tool_courseproctoring_vendor', [
            'userid' => 'privacy:metadata:vendor:userid',
            'firstname' => 'privacy:metadata:vendor:firstname',
            'lastname' => 'privacy:metadata:vendor:lastname',
            'email' => 'privacy:metadata:vendor:email',
            'username' => 'privacy:metadata:vendor:username',
            'coursename' => 'privacy:metadata:vendor:coursename',
            'courseid' => 'privacy:metadata:vendor:courseid',
            'sessionid' => 'privacy:metadata:vendor:sessionid',
            'keyid' => 'privacy:metadata:vendor:keyid',
        ], 'privacy:metadata:vendor');

        return $collection;
    }

    #[\Override]
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {" . self::TABLE . "} s ON s.courseid = ctx.instanceid AND ctx.contextlevel = :courselevel
                 WHERE s.userid = :userid";
        $contextlist->add_from_sql($sql, ['courselevel' => CONTEXT_COURSE, 'userid' => $userid]);
        return $contextlist;
    }

    #[\Override]
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_COURSE) {
            return;
        }
        $userlist->add_from_sql(
            'userid',
            "SELECT userid FROM {" . self::TABLE . "} WHERE courseid = :courseid",
            ['courseid' => $context->instanceid]
        );
    }

    #[\Override]
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }
        $userid = $contextlist->get_user()->id;
        $statuses = [
            session_repository::STATUS_ACTIVE => get_string('status_active', 'tool_courseproctoring'),
            session_repository::STATUS_CLOSED => get_string('status_closed', 'tool_courseproctoring'),
            session_repository::STATUS_FAILED => get_string('status_failed', 'tool_courseproctoring'),
        ];

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_COURSE) {
                continue;
            }
            $session = $DB->get_record(self::TABLE, ['userid' => $userid, 'courseid' => $context->instanceid]);
            if (!$session) {
                continue;
            }
            writer::with_context($context)->export_data(
                [get_string('privacy:path', 'tool_courseproctoring')],
                (object) [
                    'sessionid' => $session->sessionid,
                    'status' => $statuses[(int) $session->status] ?? (string) $session->status,
                    'closeattempts' => (int) $session->closeattempts,
                    'lasterror' => $session->lasterror,
                    'timecreated' => transform::datetime($session->timecreated),
                    'timemodified' => transform::datetime($session->timemodified),
                    'timeclosed' => $session->timeclosed === null ? null : transform::datetime($session->timeclosed),
                ]
            );
        }
    }

    #[\Override]
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if ($context->contextlevel === CONTEXT_COURSE) {
            $DB->delete_records(self::TABLE, ['courseid' => $context->instanceid]);
        }
    }

    #[\Override]
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        if (empty($contextlist->count())) {
            return;
        }
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel === CONTEXT_COURSE) {
                $DB->delete_records(self::TABLE, ['userid' => $userid, 'courseid' => $context->instanceid]);
            }
        }
    }

    #[\Override]
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_COURSE) {
            return;
        }
        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }
        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params = ['courseid' => $context->instanceid] + $inparams;
        $DB->delete_records_select(self::TABLE, "courseid = :courseid AND userid $insql", $params);
    }
}
