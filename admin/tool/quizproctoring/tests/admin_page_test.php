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

namespace tool_quizproctoring;

/**
 * Tests for the administration menu entry: where the page is listed and who may open it.
 *
 * @package    tool_quizproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class admin_page_test extends \advanced_testcase {
    /**
     * The page is in the administration tree, restricted to site administrators.
     */
    public function test_menu_entry_and_capability(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/adminlib.php');
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        // The admin tree is built per user: settings.php only registers the page for holders of moodle/site:config.
        $this->setAdminUser();
        $root = \admin_get_root(true, true);
        $page = $root->locate('tool_quizproctoring');
        $this->assertInstanceOf(\admin_externalpage::class, $page);
        $this->assertSame(['moodle/site:config'], $page->req_capability);
        $this->assertStringEndsWith('/admin/tool/quizproctoring/index.php', $page->url->out(false));
        $this->assertSame('Quiz proctoring', $page->visiblename);
        $tools = $root->locate('tools');
        $this->assertInstanceOf(\admin_category::class, $tools);
        $this->assertNotNull($tools->locate('tool_quizproctoring'), 'listed under Plugins > Admin tools');
        $this->assertTrue($page->check_access());

        // The same page object checked against other users (this is what admin_externalpage_setup() does).
        $manager = $generator->create_user();
        $managerrole = $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        role_assign($managerrole, $manager->id, \context_system::instance());
        $this->setUser($manager);
        $this->assertFalse($page->check_access(), 'a site manager is not enough');
        $this->assertNull(\admin_get_root(true, true)->locate('tool_quizproctoring'), 'not even listed for a manager');

        $this->setUser($generator->create_user());
        $this->assertFalse($page->check_access(), 'an ordinary user');
        $this->assertNull(\admin_get_root(true, true)->locate('tool_quizproctoring'));

        $this->setGuestUser();
        $this->assertFalse($page->check_access());
    }
}
