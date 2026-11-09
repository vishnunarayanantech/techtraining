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

namespace tool_quizproctoring\local;

use tool_quizproctoring\form\settings_form;

/**
 * Tests for saving the site-wide settings and for the settings form's validation.
 *
 * @package    tool_quizproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_quizproctoring\local\settings_manager
 * @covers     \tool_quizproctoring\form\settings_form
 * @covers     \tool_quizproctoring\event\settings_updated
 */
final class settings_manager_test extends \advanced_testcase {
    /**
     * Saving a new App Key stores it encrypted and records an event that does not contain it.
     */
    public function test_new_app_key(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $sink = $this->redirectEvents();

        $result = (new settings_manager())->save('  brand-new-key-0123  ', false, true);

        $this->assertSame(settings_manager::APPKEY_SET, $result);
        $this->assertSame('brand-new-key-0123', config::get_app_key());
        $this->assertTrue(config::is_vendor_enabled());
        $this->assertStringNotContainsString('brand-new-key', get_config('tool_quizproctoring', 'appkey'));

        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertInstanceOf(\tool_quizproctoring\event\settings_updated::class, $event);
        $this->assertSame('set', $event->other['appkey']);
        $this->assertTrue($event->other['vendorenabled']);
        $this->assertStringNotContainsString('brand-new-key', json_encode($event->get_data()));
        $this->assertStringNotContainsString('brand-new-key', $event->get_description());
        $this->assertNotEmpty($event->get_name());
    }

    /**
     * An empty field keeps the saved key.
     */
    public function test_empty_field_keeps_the_key(): void {
        $this->resetAfterTest();
        config::set_app_key('keep-this-key-0123');

        $result = (new settings_manager())->save('', false, false);

        $this->assertSame(settings_manager::APPKEY_UNCHANGED, $result);
        $this->assertSame('keep-this-key-0123', config::get_app_key());
    }

    /**
     * "Remove" deletes the key.
     */
    public function test_clear(): void {
        $this->resetAfterTest();
        config::set_app_key('remove-this-key-01');

        $result = (new settings_manager())->save('', true, false);

        $this->assertSame(settings_manager::APPKEY_CLEARED, $result);
        $this->assertFalse(config::has_app_key());
    }

    /**
     * The integration cannot be switched on without an App Key; nothing changes if it is refused.
     */
    public function test_cannot_enable_without_an_app_key(): void {
        $this->resetAfterTest();
        $sink = $this->redirectEvents();

        try {
            (new settings_manager())->save('', false, true);
            $this->fail('enabling without a key was accepted');
        } catch (\invalid_parameter_exception $e) {
            $this->assertFalse(config::is_vendor_enabled());
        }
        $this->assertCount(0, $sink->get_events());
    }

    /**
     * A malformed new key is refused and the old one stays.
     */
    public function test_malformed_key_is_refused(): void {
        $this->resetAfterTest();
        config::set_app_key('good-old-key-0123');

        $this->expectException(\invalid_parameter_exception::class);
        try {
            (new settings_manager())->save('bad key with spaces', false, false);
        } finally {
            $this->assertSame('good-old-key-0123', config::get_app_key());
        }
    }

    /**
     * Switching the integration off keeps the App Key.
     */
    public function test_switching_off_keeps_the_key(): void {
        $this->resetAfterTest();
        (new settings_manager())->save('stay-saved-key-012', false, true);
        (new settings_manager())->save('', false, false);

        $this->assertFalse(config::is_vendor_enabled());
        $this->assertSame('stay-saved-key-012', config::get_app_key());
    }

    /**
     * The form's validation rules.
     */
    public function test_form_validation(): void {
        $this->resetAfterTest();
        $form = new settings_form();

        $this->assertSame([], $form->validation(['appkey' => '', 'vendorenabled' => 0], []));
        $this->assertSame([], $form->validation(['appkey' => 'valid-key-0123456', 'vendorenabled' => 1], []));

        $errors = $form->validation(['appkey' => 'has a space', 'vendorenabled' => 0], []);
        $this->assertArrayHasKey('appkey', $errors);

        $errors = $form->validation(['appkey' => '', 'vendorenabled' => 1], []);
        $this->assertArrayHasKey('vendorenabled', $errors, 'cannot enable without a key');

        config::set_app_key('saved-key-0123456');
        $form = new settings_form();
        $this->assertSame([], $form->validation(['appkey' => '', 'vendorenabled' => 1], []), 'the saved key is enough');
        $errors = $form->validation(['appkey' => '', 'clearappkey' => 1, 'vendorenabled' => 1], []);
        $this->assertArrayHasKey('vendorenabled', $errors, 'removing the key while enabling');
        $errors = $form->validation(['appkey' => 'another-key-012345', 'clearappkey' => 1, 'vendorenabled' => 0], []);
        $this->assertArrayHasKey('clearappkey', $errors, 'new key and remove together');
    }

    /**
     * The form never reveals the saved key, only a masked hint.
     */
    public function test_form_never_shows_the_saved_key(): void {
        $this->resetAfterTest();
        config::set_app_key('abcdefgh-SECRET-wxyz');

        $form = new settings_form();
        $html = $form->render();

        $this->assertStringNotContainsString('SECRET', $html);
        $this->assertStringNotContainsString('abcdefgh', $html);
        $this->assertStringContainsString('wxyz', $html, 'only the last four characters are hinted');
        $this->assertStringContainsString('name="appkey"', $html);
        $this->assertStringContainsString('name="sesskey"', $html, 'moodleform adds the session key');
    }

    /**
     * A stored key that cannot be decrypted is reported as such, not shown as a saved key.
     */
    public function test_form_reports_an_unreadable_key(): void {
        $this->resetAfterTest();
        set_config('appkey', 'not-an-encrypted-value', 'tool_quizproctoring');

        $html = (new settings_form())->render();

        $this->assertDebuggingCalled(null, DEBUG_DEVELOPER);
        $this->assertStringContainsString('cannot be read', $html);
        $this->assertStringNotContainsString('An App Key is saved', $html);
    }

    /**
     * A POST without the session key is refused by moodleform (get_data() returns nothing).
     */
    public function test_form_rejects_a_post_without_the_session_key(): void {
        $this->resetAfterTest();
        $_POST = ['appkey' => 'sneaky-key-0123456', 'vendorenabled' => 1, '_qf__' . settings_form::class => 1];
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $form = new settings_form();
        $this->assertNull($form->get_data());
        $this->assertFalse(config::has_app_key());

        $_POST = [];
    }
}
