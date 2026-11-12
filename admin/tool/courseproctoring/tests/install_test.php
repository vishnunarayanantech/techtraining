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

namespace tool_courseproctoring;

/**
 * Tests that the plugin's own tables exist after a fresh install, and after an upgrade from each earlier version.
 *
 * A fresh install builds the tables from db/install.xml; a site that already has an older version only runs
 * db/upgrade.php. Both ways must give the same tables, so the upgrade path is checked against install.xml.
 *
 * @package    tool_courseproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::xmldb_tool_courseproctoring_upgrade
 */
final class install_test extends \advanced_testcase {
    /** @var string[] The plugin's tables. */
    private const TABLES = ['tool_courseproctoring_course', 'tool_courseproctoring_session'];

    /** @var int The version of the plugin as it is now. */
    private const CURRENT = 2026111102;

    /**
     * A session id of the right shape for a test.
     *
     * @param string $character The character the 20 hexadecimal digits are made of.
     * @return string
     */
    private static function fake_session_id(string $character): string {
        return 'course_' . str_repeat($character, 20);
    }

    /**
     * The columns every table must have.
     *
     * @return array table => columns
     */
    private static function columns(): array {
        return [
            'tool_courseproctoring_course' => ['id', 'courseid', 'keyid', 'timecreated', 'timemodified'],
            'tool_courseproctoring_session' => ['id', 'userid', 'courseid', 'keyid', 'sessionid', 'status', 'closeattempts',
                'lasterror', 'timecreated', 'timemodified', 'timeclosed'],
        ];
    }

    /**
     * What is wrong with the tables, compared with install.xml (an empty list when they match).
     *
     * @return array
     */
    private function schema_errors(): array {
        global $CFG, $DB;
        $file = new \xmldb_file($CFG->dirroot . '/admin/tool/courseproctoring/db/install.xml');
        $this->assertTrue($file->loadXMLStructure());
        // The check treats the file as the whole database, so it also lists every other Moodle table as "not expected".
        $errors = $DB->get_manager()->check_database_schema($file->getStructure());
        return array_intersect_key($errors, array_flip(self::TABLES));
    }

    /**
     * Assert that both tables exist with all their columns and the unique keys the plugin relies on.
     */
    private function assert_tables_are_right(): void {
        global $DB;
        $dbman = $DB->get_manager();
        foreach (self::columns() as $name => $columns) {
            $table = new \xmldb_table($name);
            $this->assertTrue($dbman->table_exists($table), $name);
            foreach ($columns as $column) {
                $this->assertTrue($dbman->field_exists($table, new \xmldb_field($column)), "$name.$column");
            }
        }
        $this->assertSame([], $this->schema_errors(), 'the tables match install.xml');

        $course = new \xmldb_table('tool_courseproctoring_course');
        $this->assertNotFalse($dbman->find_index_name($course, new \xmldb_index('x', XMLDB_INDEX_UNIQUE, ['courseid'])));
        $session = new \xmldb_table('tool_courseproctoring_session');
        $this->assertNotFalse($dbman->find_index_name($session, new \xmldb_index('x', XMLDB_INDEX_UNIQUE, ['userid', 'courseid'])));
        $this->assertNotFalse($dbman->find_index_name($session, new \xmldb_index('x', XMLDB_INDEX_UNIQUE, ['sessionid'])));
    }

    /**
     * The ledger refuses a second row for a learner in a course and a repeated session id.
     */
    private function assert_ledger_is_unique(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $course = $generator->create_course();
        $row = static fn(int $userid, int $courseid, string $sessionid): \stdClass => (object) [
            'userid' => $userid, 'courseid' => $courseid, 'keyid' => 'KEY-1', 'sessionid' => $sessionid, 'status' => 0,
            'closeattempts' => 0, 'timecreated' => 1, 'timemodified' => 1,
        ];
        $DB->insert_record('tool_courseproctoring_session', $row($user->id, $course->id, self::fake_session_id('a')));

        $duplicates = [
            'the same learner in the same course' => $row($user->id, $course->id, self::fake_session_id('b')),
            'the same session id for somebody else' => $row($user->id + 1000, $course->id, self::fake_session_id('a')),
        ];
        foreach ($duplicates as $what => $duplicate) {
            try {
                $DB->insert_record('tool_courseproctoring_session', $duplicate);
                $this->fail("accepted: $what");
            } catch (\dml_write_exception $e) {
                $this->assertEquals(1, $DB->count_records('tool_courseproctoring_session'), $what);
            }
        }
        $DB->delete_records('tool_courseproctoring_session');
    }

    /**
     * A fresh install made both tables.
     */
    public function test_fresh_install_made_both_tables(): void {
        $this->resetAfterTest();
        $this->assert_tables_are_right();
        $this->assert_ledger_is_unique();
    }

    /**
     * A site that has version 0.1.0 (no tables) gets both tables from the upgrade, exactly as install.xml defines them.
     * This is the step the demo server goes through.
     */
    public function test_upgrade_from_0_1_0(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once(__DIR__ . '/../db/upgrade.php');
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $dbman = $DB->get_manager();

        foreach (self::TABLES as $name) {
            $dbman->drop_table(new \xmldb_table($name));
        }
        set_config('version', 2026111001, 'tool_courseproctoring');

        $this->assertTrue(xmldb_tool_courseproctoring_upgrade(2026111001));

        $this->assert_tables_are_right();
        $this->assert_ledger_is_unique();
        $this->assertEquals(self::CURRENT, get_config('tool_courseproctoring', 'version'));
    }

    /**
     * A site that has version 0.2.0 keeps its Proctor Keys and only gets the new ledger table.
     */
    public function test_upgrade_from_0_2_0_keeps_the_keys(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once(__DIR__ . '/../db/upgrade.php');
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $dbman = $DB->get_manager();
        $course = $this->getDataGenerator()->create_course();
        (new local\course_key_repository())->set((int) $course->id, 'KEY-KEPT');

        $dbman->drop_table(new \xmldb_table('tool_courseproctoring_session'));
        set_config('version', 2026111101, 'tool_courseproctoring');

        $this->assertTrue(xmldb_tool_courseproctoring_upgrade(2026111101));

        $this->assert_tables_are_right();
        $this->assertSame('KEY-KEPT', (new local\course_key_repository())->get((int) $course->id));
        $this->assertEquals(self::CURRENT, get_config('tool_courseproctoring', 'version'));
    }

    /**
     * Running an upgrade step again on a site that already has the tables changes nothing.
     */
    public function test_upgrade_is_harmless_when_the_tables_exist(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once(__DIR__ . '/../db/upgrade.php');
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $course = $this->getDataGenerator()->create_course();
        (new local\course_key_repository())->set((int) $course->id, 'KEY-KEPT');

        set_config('version', 2026111001, 'tool_courseproctoring');
        $this->assertTrue(xmldb_tool_courseproctoring_upgrade(2026111001));

        $this->assert_tables_are_right();
        $this->assertSame('KEY-KEPT', (new local\course_key_repository())->get((int) $course->id));
        $this->assertEquals(1, $DB->count_records('tool_courseproctoring_course'));
    }
}
