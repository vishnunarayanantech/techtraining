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

namespace tool_quizproctoring\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use tool_quizproctoring\local\attempt_facts;
use tool_quizproctoring\local\session_repository;

/**
 * Tests for the privacy provider.
 *
 * @package    tool_quizproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_quizproctoring\privacy\provider
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * Two users with sessions in two quizzes of one course, plus a third user with none.
     *
     * @return \stdClass users and module contexts
     */
    private function create_data(): \stdClass {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $quiz1 = $generator->create_module('quiz', ['course' => $course->id]);
        $quiz2 = $generator->create_module('quiz', ['course' => $course->id]);
        $alice = $generator->create_user();
        $bob = $generator->create_user();
        $carol = $generator->create_user();

        $repo = new session_repository();
        $start = static function (int $attemptid, $user, $quiz) use ($repo, $course): void {
            $repo->ensure_started(
                new attempt_facts($attemptid, $user->id, $course->id, $quiz->cmid, $quiz->id, 'inprogress', false, 'C'),
                'KEY-1'
            );
        };
        $start(101, $alice, $quiz1);
        $start(102, $alice, $quiz1);
        $start(103, $alice, $quiz2);
        $start(104, $bob, $quiz1);
        $repo->mark_closed(101);
        $repo->mark_failed(102, 'HTTP 400');

        return (object) [
            'alice' => $alice, 'bob' => $bob, 'carol' => $carol,
            'context1' => \context_module::instance($quiz1->cmid),
            'context2' => \context_module::instance($quiz2->cmid),
        ];
    }

    /**
     * The plugin declares the table it stores, and the external service it sends personal data to, with a
     * description for every field. This is what makes it compliant in Moodle's privacy registry.
     */
    public function test_metadata(): void {
        $collection = provider::get_metadata(new collection('tool_quizproctoring'));
        $items = $collection->get_collection();
        $this->assertCount(2, $items);

        $types = array_map(static fn($item) => get_class($item), $items);
        $this->assertContains(\core_privacy\local\metadata\types\database_table::class, $types);
        $this->assertContains(\core_privacy\local\metadata\types\external_location::class, $types);

        $strings = get_string_manager();
        foreach ($items as $item) {
            $this->assertTrue($strings->string_exists($item->get_summary(), 'tool_quizproctoring'), $item->get_summary());
            foreach ($item->get_privacy_fields() as $field => $stringid) {
                $this->assertTrue($strings->string_exists($stringid, 'tool_quizproctoring'), "$field: $stringid");
            }
        }

        $externals = array_values(array_filter(
            $items,
            static fn($item) => $item instanceof \core_privacy\local\metadata\types\external_location
        ));
        $fields = array_keys($externals[0]->get_privacy_fields());
        foreach (['userid', 'firstname', 'lastname', 'email', 'username', 'coursename', 'quizid', 'attemptid', 'keyid'] as $field) {
            $this->assertContains($field, $fields);
        }
        $this->assertNotContains('appkey', $fields, 'the App Key is not personal data');
    }

    /**
     * Moodle's privacy registry accepts the component.
     */
    public function test_component_is_compliant(): void {
        $this->assertTrue((new \core_privacy\manager())->component_is_compliant('tool_quizproctoring'));
    }

    /**
     * A user's contexts are the quizzes they have sessions in.
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
     * The users in a quiz context.
     */
    public function test_get_users_in_context(): void {
        $data = $this->create_data();

        $userlist = new userlist($data->context1, 'tool_quizproctoring');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing([$data->alice->id, $data->bob->id], $userlist->get_userids());

        $userlist = new userlist($data->context2, 'tool_quizproctoring');
        provider::get_users_in_context($userlist);
        $this->assertEquals([$data->alice->id], $userlist->get_userids());

        $userlist = new userlist(\context_system::instance(), 'tool_quizproctoring');
        provider::get_users_in_context($userlist);
        $this->assertEmpty($userlist->get_userids(), 'only module contexts hold data');
    }

    /**
     * Export: every session of the user in the context, readable, and nobody else's.
     */
    public function test_export_user_data(): void {
        $data = $this->create_data();
        $this->setUser($data->alice);

        $contextlist = new approved_contextlist(
            $data->alice,
            'tool_quizproctoring',
            [$data->context1->id, $data->context2->id]
        );
        provider::export_user_data($contextlist);

        $path = [get_string('privacy:path', 'tool_quizproctoring')];
        $first = writer::with_context($data->context1)->get_data($path);
        $this->assertCount(2, $first->sessions, 'two attempts in quiz 1');
        $this->assertEquals([101, 102], array_column($first->sessions, 'attemptid'));
        $this->assertSame('Closed', $first->sessions[0]->status);
        $this->assertSame('Failed', $first->sessions[1]->status);
        $this->assertSame('HTTP 400', $first->sessions[1]->lasterror);
        $this->assertNotEmpty($first->sessions[0]->timecreated);

        $second = writer::with_context($data->context2)->get_data($path);
        $this->assertEquals([103], array_column($second->sessions, 'attemptid'));
        $this->assertSame('Active', $second->sessions[0]->status);
        $this->assertNull($second->sessions[0]->timeclosed);

        $this->assertStringNotContainsString('104', json_encode([$first, $second]), "Bob's attempt is not exported");
    }

    /**
     * Deleting everything in a context.
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;
        $data = $this->create_data();

        provider::delete_data_for_all_users_in_context($data->context1);

        $this->assertEquals(1, $DB->count_records('tool_quizproctoring_session'));
        $this->assertTrue($DB->record_exists('tool_quizproctoring_session', ['attemptid' => 103]));

        provider::delete_data_for_all_users_in_context(\context_system::instance());
        $this->assertEquals(1, $DB->count_records('tool_quizproctoring_session'), 'other context levels are ignored');
    }

    /**
     * Deleting one user's data in the approved contexts only.
     */
    public function test_delete_data_for_user(): void {
        global $DB;
        $data = $this->create_data();

        provider::delete_data_for_user(new approved_contextlist($data->alice, 'tool_quizproctoring', [$data->context1->id]));

        $this->assertFalse($DB->record_exists('tool_quizproctoring_session', ['attemptid' => 101]));
        $this->assertFalse($DB->record_exists('tool_quizproctoring_session', ['attemptid' => 102]));
        $this->assertTrue($DB->record_exists('tool_quizproctoring_session', ['attemptid' => 103]), 'quiz 2 was not approved');
        $this->assertTrue($DB->record_exists('tool_quizproctoring_session', ['attemptid' => 104]), "Bob's data stays");
    }

    /**
     * Deleting several users' data in a context.
     */
    public function test_delete_data_for_users(): void {
        global $DB;
        $data = $this->create_data();

        $both = [$data->alice->id, $data->bob->id];
        provider::delete_data_for_users(new approved_userlist($data->context1, 'tool_quizproctoring', $both));
        $this->assertEquals(1, $DB->count_records('tool_quizproctoring_session'));
        $this->assertTrue($DB->record_exists('tool_quizproctoring_session', ['attemptid' => 103]));

        provider::delete_data_for_users(new approved_userlist($data->context2, 'tool_quizproctoring', []));
        $this->assertEquals(1, $DB->count_records('tool_quizproctoring_session'), 'an empty list deletes nothing');
    }
}
