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
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use tool_courseproctoring\local\session_repository;

/**
 * Tests for the privacy provider.
 *
 * @package    tool_courseproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_courseproctoring\privacy\provider
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * Two users with sessions in two courses, plus a third user with none.
     *
     * @return \stdClass users, courses, their contexts and the sessions
     */
    private function create_data(): \stdClass {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course1 = $generator->create_course();
        $course2 = $generator->create_course();
        $alice = $generator->create_user();
        $bob = $generator->create_user();
        $carol = $generator->create_user();

        $repo = new session_repository();
        $aliceone = $repo->ensure_started($alice->id, $course1->id, 'KEY-1');
        $alicetwo = $repo->ensure_started($alice->id, $course2->id, 'KEY-2');
        $bobone = $repo->ensure_started($bob->id, $course1->id, 'KEY-1');
        $repo->mark_closed($aliceone->id);
        $repo->mark_failed($alicetwo->id, 'HTTP 400');

        return (object) [
            'alice' => $alice, 'bob' => $bob, 'carol' => $carol,
            'context1' => \context_course::instance($course1->id),
            'context2' => \context_course::instance($course2->id),
            'aliceone' => $aliceone, 'alicetwo' => $alicetwo, 'bobone' => $bobone,
        ];
    }

    /**
     * The plugin declares the table it stores, and the external service it sends personal data to, with a description
     * for every field. This is what makes it compliant in Moodle's privacy registry.
     */
    public function test_metadata(): void {
        $collection = provider::get_metadata(new collection('tool_courseproctoring'));
        $items = $collection->get_collection();
        $this->assertCount(2, $items);

        $types = array_map(static fn($item) => get_class($item), $items);
        $this->assertContains(\core_privacy\local\metadata\types\database_table::class, $types);
        $this->assertContains(\core_privacy\local\metadata\types\external_location::class, $types);

        $strings = get_string_manager();
        foreach ($items as $item) {
            $this->assertTrue($strings->string_exists($item->get_summary(), 'tool_courseproctoring'), $item->get_summary());
            foreach ($item->get_privacy_fields() as $field => $stringid) {
                $this->assertTrue($strings->string_exists($stringid, 'tool_courseproctoring'), "$field: $stringid");
            }
        }

        $externals = array_values(array_filter(
            $items,
            static fn($item) => $item instanceof \core_privacy\local\metadata\types\external_location
        ));
        $fields = array_keys($externals[0]->get_privacy_fields());
        $expected = ['userid', 'firstname', 'lastname', 'email', 'username', 'coursename', 'courseid', 'sessionid', 'keyid'];
        foreach ($expected as $field) {
            $this->assertContains($field, $fields);
        }
        $this->assertNotContains('appkey', $fields, 'the App Key is not personal data');

        $tables = array_values(array_filter(
            $items,
            static fn($item) => $item instanceof \core_privacy\local\metadata\types\database_table
        ));
        $this->assertSame('tool_courseproctoring_session', $tables[0]->get_name());
        $columns = array_keys($tables[0]->get_privacy_fields());
        $this->assertEqualsCanonicalizing(
            ['userid', 'courseid', 'keyid', 'sessionid', 'status', 'closeattempts', 'lasterror', 'timecreated',
                'timemodified', 'timeclosed'],
            $columns,
            'every column of the ledger is declared'
        );
    }

    /**
     * Moodle's privacy registry accepts the component.
     */
    public function test_component_is_compliant(): void {
        $this->assertTrue((new \core_privacy\manager())->component_is_compliant('tool_courseproctoring'));
    }

    /**
     * A user's contexts are the courses they have a session in.
     */
    public function test_get_contexts_for_userid(): void {
        $data = $this->create_data();

        $alice = provider::get_contexts_for_userid($data->alice->id);
        $this->assertEqualsCanonicalizing([$data->context1->id, $data->context2->id], $alice->get_contextids());
        $bob = provider::get_contexts_for_userid($data->bob->id);
        $this->assertEqualsCanonicalizing([$data->context1->id], $bob->get_contextids());
        $this->assertEmpty(provider::get_contexts_for_userid($data->carol->id)->get_contextids());
    }

    /**
     * The users in a course context.
     */
    public function test_get_users_in_context(): void {
        $data = $this->create_data();

        $userlist = new userlist($data->context1, 'tool_courseproctoring');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing([$data->alice->id, $data->bob->id], $userlist->get_userids());

        $userlist = new userlist($data->context2, 'tool_courseproctoring');
        provider::get_users_in_context($userlist);
        $this->assertEquals([$data->alice->id], $userlist->get_userids());

        $userlist = new userlist(\context_system::instance(), 'tool_courseproctoring');
        provider::get_users_in_context($userlist);
        $this->assertEmpty($userlist->get_userids(), 'only course contexts hold data');
    }

    /**
     * Export: the user's session in each course, readable, with the session id the vendor knows it by, and nobody
     * else's.
     */
    public function test_export_user_data(): void {
        $data = $this->create_data();
        $this->setUser($data->alice);

        $contextlist = new approved_contextlist(
            $data->alice,
            'tool_courseproctoring',
            [$data->context1->id, $data->context2->id]
        );
        provider::export_user_data($contextlist);

        $path = [get_string('privacy:path', 'tool_courseproctoring')];
        $first = writer::with_context($data->context1)->get_data($path);
        $this->assertSame($data->aliceone->sessionid, $first->sessionid);
        $this->assertSame('Closed', $first->status);
        $this->assertNotEmpty($first->timecreated);
        $this->assertNotEmpty($first->timeclosed);

        $second = writer::with_context($data->context2)->get_data($path);
        $this->assertSame($data->alicetwo->sessionid, $second->sessionid);
        $this->assertSame('Failed', $second->status);
        $this->assertSame('HTTP 400', $second->lasterror);
        $this->assertNull($second->timeclosed);

        $exported = json_encode([$first, $second]);
        $this->assertStringNotContainsString($data->bobone->sessionid, $exported, "Bob's session is not exported");
    }

    /**
     * Deleting everything in a course context (and nothing in other contexts).
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;
        $data = $this->create_data();

        provider::delete_data_for_all_users_in_context($data->context1);

        $this->assertEquals(1, $DB->count_records('tool_courseproctoring_session'));
        $this->assertTrue($DB->record_exists('tool_courseproctoring_session', ['id' => $data->alicetwo->id]));

        provider::delete_data_for_all_users_in_context(\context_system::instance());
        $this->assertEquals(1, $DB->count_records('tool_courseproctoring_session'), 'other context levels are ignored');
    }

    /**
     * Deleting one user's data in the approved contexts only.
     */
    public function test_delete_data_for_user(): void {
        global $DB;
        $data = $this->create_data();

        provider::delete_data_for_user(new approved_contextlist($data->alice, 'tool_courseproctoring', [$data->context1->id]));

        $this->assertFalse($DB->record_exists('tool_courseproctoring_session', ['id' => $data->aliceone->id]));
        $this->assertTrue(
            $DB->record_exists('tool_courseproctoring_session', ['id' => $data->alicetwo->id]),
            'course 2 was not approved'
        );
        $this->assertTrue($DB->record_exists('tool_courseproctoring_session', ['id' => $data->bobone->id]), "Bob's data stays");
    }

    /**
     * Deleting several users' data in a context.
     */
    public function test_delete_data_for_users(): void {
        global $DB;
        $data = $this->create_data();

        $both = [$data->alice->id, $data->bob->id];
        provider::delete_data_for_users(new approved_userlist($data->context1, 'tool_courseproctoring', $both));
        $this->assertEquals(1, $DB->count_records('tool_courseproctoring_session'));
        $this->assertTrue($DB->record_exists('tool_courseproctoring_session', ['id' => $data->alicetwo->id]));

        provider::delete_data_for_users(new approved_userlist($data->context2, 'tool_courseproctoring', []));
        $this->assertEquals(1, $DB->count_records('tool_courseproctoring_session'), 'an empty list deletes nothing');
    }
}
