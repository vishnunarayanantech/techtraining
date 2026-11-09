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

/**
 * Tests for the site-wide configuration.
 *
 * @package    tool_quizproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_quizproctoring\local\config
 */
final class config_test extends \advanced_testcase {
    /**
     * Nothing is configured and the integration is off by default.
     */
    public function test_defaults(): void {
        $this->resetAfterTest();
        $this->assertFalse(config::is_vendor_enabled());
        $this->assertFalse(config::has_app_key());
        $this->assertSame('', config::get_app_key());
    }

    /**
     * The App Key is encrypted at rest and decrypts back to the original.
     */
    public function test_app_key_is_encrypted_at_rest(): void {
        $this->resetAfterTest();
        config::set_app_key('  abc-DEF_0123456789  ');

        $stored = get_config('tool_quizproctoring', 'appkey');
        $this->assertNotEmpty($stored);
        $this->assertStringNotContainsString('abc-DEF_0123456789', $stored);
        $this->assertTrue(config::has_app_key());
        $this->assertSame('abc-DEF_0123456789', config::get_app_key(), 'surrounding spaces are trimmed');
    }

    /**
     * An empty value removes the saved key.
     */
    public function test_empty_value_clears_the_key(): void {
        $this->resetAfterTest();
        config::set_app_key('abc-DEF_0123456789');
        config::set_app_key('');
        $this->assertFalse(config::has_app_key());
        $this->assertFalse(get_config('tool_quizproctoring', 'appkey'));
    }

    /**
     * Malformed keys are rejected and nothing is saved.
     *
     * @dataProvider invalid_app_keys
     * @param string $value
     */
    public function test_invalid_app_key_is_rejected(string $value): void {
        $this->resetAfterTest();
        $this->assertFalse(config::is_valid_app_key($value));
        $this->expectException(\invalid_parameter_exception::class);
        config::set_app_key($value);
    }

    /**
     * Values that are not plausible keys.
     *
     * @return array
     */
    public static function invalid_app_keys(): array {
        return [
            'inner space' => ['abc def'],
            'tab' => ["abc\tdef"],
            'newline' => ["abc\ndef"],
            'non-ASCII' => ['clé-été-1234'],
            'too long' => [str_repeat('a', 256)],
            'control character' => ["abc\x01def"],
        ];
    }

    /**
     * Plausible keys are accepted, including UUIDs and the maximum length.
     */
    public function test_valid_app_keys(): void {
        $this->assertTrue(config::is_valid_app_key('bee923f2-9ceb-4dec-9d30-b937ca6bdf6f'));
        $this->assertTrue(config::is_valid_app_key('a.b_c-D~9'));
        $this->assertTrue(config::is_valid_app_key(str_repeat('a', 255)));
    }

    /**
     * The mask never reveals a short key and shows at most the last four characters of a long one.
     */
    public function test_mask(): void {
        $this->assertStringNotContainsString('abcd', config::mask('abcd'));
        $this->assertStringNotContainsString('abcdefghijk', config::mask('abcdefghijk'));
        $masked = config::mask('abcdefgh12345678');
        $this->assertStringEndsWith('5678', $masked);
        $this->assertStringNotContainsString('1234', $masked);
        $this->assertStringNotContainsString('abcdefgh', $masked);
    }

    /**
     * A stored value that cannot be decrypted behaves like "no key" instead of breaking the site.
     */
    public function test_undecryptable_value_is_treated_as_no_key(): void {
        $this->resetAfterTest();
        set_config('appkey', 'not-an-encrypted-value', 'tool_quizproctoring');
        $this->assertSame('', config::get_app_key());
        $this->assertDebuggingCalled(null, DEBUG_DEVELOPER);
    }

    /**
     * The integration state, as the administration page reports it.
     */
    public function test_integration_status(): void {
        $this->resetAfterTest();
        $this->assertSame(config::STATUS_OFF, config::integration_status(), 'default');

        config::set_app_key('some-saved-key-0123');
        $this->assertSame(config::STATUS_OFF, config::integration_status(), 'a saved App Key alone does not start anything');

        config::set_vendor_enabled(true);
        $this->assertSame(config::STATUS_ON, config::integration_status());

        config::set_app_key('');
        $this->assertSame(config::STATUS_ON_WITHOUT_KEY, config::integration_status(), 'switch on, no key');

        config::set_vendor_enabled(false);
        $this->assertSame(config::STATUS_OFF, config::integration_status());
    }

    /**
     * "Usable" means decryptable: a key that is stored but unreadable does not count as a key.
     */
    public function test_integration_status_with_an_unreadable_key(): void {
        $this->resetAfterTest();
        set_config('appkey', 'not-an-encrypted-value', 'tool_quizproctoring');
        config::set_vendor_enabled(true);

        $this->assertTrue(config::has_app_key(), 'something is stored');
        $this->assertSame(config::STATUS_ON_WITHOUT_KEY, config::integration_status());
        $this->assertDebuggingCalled(null, DEBUG_DEVELOPER);
    }

    /**
     * The switch round-trips.
     */
    public function test_vendor_switch(): void {
        $this->resetAfterTest();
        config::set_vendor_enabled(true);
        $this->assertTrue(config::is_vendor_enabled());
        config::set_vendor_enabled(false);
        $this->assertFalse(config::is_vendor_enabled());
    }
}
