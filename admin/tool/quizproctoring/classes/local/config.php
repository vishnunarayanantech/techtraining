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

use core\encryption;

/**
 * Site-wide plugin settings: the proctoring App Key and the vendor-integration switch.
 *
 * The App Key is a credential, so it is stored encrypted at rest (core\encryption) and is never
 * shown again after saving. The integration switch is OFF by default: until an administrator
 * turns it on, this plugin neither starts nor closes any proctoring session and makes no network
 * request to the vendor, even if an App Key and Key IDs have been entered.
 *
 * @package    tool_quizproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class config {
    /** @var string Plugin component, used as the config_plugins namespace. */
    public const COMPONENT = 'tool_quizproctoring';

    /** @var string The switch is off: nothing is proctored, whatever else is configured. */
    public const STATUS_OFF = 'off';
    /** @var string The switch is on and a usable App Key is saved: courses with a Key ID are proctored. */
    public const STATUS_ON = 'on';
    /** @var string The switch is on but there is no usable App Key: nothing can be proctored. */
    public const STATUS_ON_WITHOUT_KEY = 'on_without_key';

    /**
     * The state of the integration, as far as this plugin's own settings go.
     *
     * "Usable" means the saved App Key can actually be decrypted, not just that something is stored.
     *
     * @return string One of the STATUS_* constants.
     */
    public static function integration_status(): string {
        if (!self::is_vendor_enabled()) {
            return self::STATUS_OFF;
        }
        return self::get_app_key() === '' ? self::STATUS_ON_WITHOUT_KEY : self::STATUS_ON;
    }

    /**
     * Whether the vendor integration is switched on.
     *
     * @return bool
     */
    public static function is_vendor_enabled(): bool {
        return (bool) get_config(self::COMPONENT, 'vendorenabled');
    }

    /**
     * Switch the vendor integration on or off.
     *
     * @param bool $enabled
     */
    public static function set_vendor_enabled(bool $enabled): void {
        set_config('vendorenabled', $enabled ? 1 : 0, self::COMPONENT);
    }

    /**
     * Whether an App Key has been saved (does not decrypt it).
     *
     * @return bool
     */
    public static function has_app_key(): bool {
        $stored = get_config(self::COMPONENT, 'appkey');
        return $stored !== false && $stored !== null && $stored !== '';
    }

    /**
     * The decrypted App Key, or an empty string if none is saved or it cannot be decrypted.
     *
     * @return string
     */
    public static function get_app_key(): string {
        if (!self::has_app_key()) {
            return '';
        }
        try {
            return encryption::decrypt((string) get_config(self::COMPONENT, 'appkey'));
        } catch (\Exception $e) {
            debugging('tool_quizproctoring: the saved App Key could not be decrypted: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return '';
        }
    }

    /**
     * Save the App Key (encrypted). An empty string removes it.
     *
     * @param string $appkey
     * @throws \invalid_parameter_exception if the value is not a plausible key.
     */
    public static function set_app_key(string $appkey): void {
        $appkey = trim($appkey);
        if ($appkey === '') {
            unset_config('appkey', self::COMPONENT);
            return;
        }
        if (!self::is_valid_app_key($appkey)) {
            throw new \invalid_parameter_exception('Invalid App Key format');
        }
        set_config('appkey', encryption::encrypt($appkey), self::COMPONENT);
    }

    /**
     * Whether a value looks like an App Key: 1-255 printable ASCII characters, no whitespace.
     *
     * The vendor has not documented the key format, so this is deliberately permissive.
     *
     * @param string $appkey
     * @return bool
     */
    public static function is_valid_app_key(string $appkey): bool {
        return (bool) preg_match('/^[\x21-\x7E]{1,255}$/', $appkey);
    }

    /**
     * A display-safe hint about a key: only the last four characters of a long key, otherwise nothing.
     *
     * @param string $appkey
     * @return string
     */
    public static function mask(string $appkey): string {
        $mask = "\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}";
        return strlen($appkey) >= 12 ? $mask . substr($appkey, -4) : $mask;
    }
}
