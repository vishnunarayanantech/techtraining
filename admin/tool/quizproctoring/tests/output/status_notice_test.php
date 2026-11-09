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

namespace tool_quizproctoring\output;

use core\output\notification;
use tool_quizproctoring\local\config;

/**
 * Tests for the plain-words state message shown on the administration page.
 *
 * @package    tool_quizproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_quizproctoring\output\status_notice
 */
final class status_notice_test extends \advanced_testcase {
    /**
     * Switch off (the default, and the state an administrator is in after saving only an App Key): a warning that says,
     * in plain words, that no quiz is proctored.
     */
    public function test_off(): void {
        $this->resetAfterTest();
        config::set_app_key('some-saved-key-0123');

        [$message, $type] = status_notice::current();
        $this->assertSame(notification::NOTIFY_WARNING, $type);
        $this->assertStringContainsString('OFF', $message);
        $this->assertStringContainsString('no quiz is being proctored', $message);
        $this->assertStringContainsString('Enable vendor integration', $message);

        [$message, $type] = status_notice::after_save();
        $this->assertSame(notification::NOTIFY_WARNING, $type, 'saving an App Key alone is not a success');
        $this->assertStringContainsString('Settings saved.', $message);
        $this->assertStringContainsString('still OFF', $message);
        $this->assertStringContainsString('no quiz is being proctored', $message);
    }

    /**
     * Switch on with a usable key.
     */
    public function test_on(): void {
        $this->resetAfterTest();
        config::set_app_key('some-saved-key-0123');
        config::set_vendor_enabled(true);

        [$message, $type] = status_notice::current();
        $this->assertSame(notification::NOTIFY_SUCCESS, $type);
        $this->assertStringContainsString('ON', $message);
        $this->assertStringContainsString('Key ID', $message, 'says what gets proctored');
        $this->assertStringNotContainsStringIgnoringCase('legacy', $message);
        $this->assertStringNotContainsString('OFF', $message);

        [$message, $type] = status_notice::after_save();
        $this->assertSame(notification::NOTIFY_SUCCESS, $type);
        $this->assertStringContainsString('Settings saved.', $message);
        $this->assertStringNotContainsString('OFF', $message);
    }

    /**
     * Switch on but the saved key cannot be read: an error, because nothing can be proctored.
     */
    public function test_on_without_a_usable_key(): void {
        $this->resetAfterTest();
        set_config('appkey', 'not-an-encrypted-value', 'tool_quizproctoring');
        config::set_vendor_enabled(true);

        [$message, $type] = status_notice::current();
        $this->assertDebuggingCalled(null, DEBUG_DEVELOPER);
        $this->assertSame(notification::NOTIFY_ERROR, $type);
        $this->assertStringContainsString('no usable App Key', $message);
        $this->assertStringContainsString('nothing can be proctored', $message);
    }

    /**
     * Every status maps to a distinct, non-empty message.
     */
    public function test_all_statuses_have_messages(): void {
        $messages = [];
        foreach ([config::STATUS_OFF, config::STATUS_ON, config::STATUS_ON_WITHOUT_KEY] as $status) {
            foreach ([false, true] as $justsaved) {
                [$message] = status_notice::for_status($status, $justsaved);
                $this->assertNotEmpty($message);
                $this->assertStringNotContainsString('[[', $message, 'every string exists');
                $messages[] = $message;
            }
        }
        $this->assertCount(6, array_unique($messages));
    }
}
