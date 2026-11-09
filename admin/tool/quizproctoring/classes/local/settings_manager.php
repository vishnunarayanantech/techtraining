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

use tool_quizproctoring\event\settings_updated;

/**
 * Saves the site-wide settings submitted from the administration form and records an audit event.
 *
 * @package    tool_quizproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class settings_manager {
    /** @var string The App Key was replaced. */
    public const APPKEY_SET = 'set';
    /** @var string The App Key was removed. */
    public const APPKEY_CLEARED = 'cleared';
    /** @var string The App Key was left as it was. */
    public const APPKEY_UNCHANGED = 'unchanged';

    /**
     * Save the settings.
     *
     * @param string $newappkey New App Key; empty keeps the saved one.
     * @param bool $clearappkey Remove the saved App Key (ignored if a new one is given).
     * @param bool $vendorenabled Whether the vendor integration should be on.
     * @return string One of the APPKEY_* constants, describing what happened to the App Key.
     * @throws \invalid_parameter_exception if the new App Key is malformed or the integration would be on without an App Key.
     */
    public function save(string $newappkey, bool $clearappkey, bool $vendorenabled): string {
        $newappkey = trim($newappkey);

        if ($newappkey !== '') {
            config::set_app_key($newappkey);
            $appkey = self::APPKEY_SET;
        } else if ($clearappkey && config::has_app_key()) {
            config::set_app_key('');
            $appkey = self::APPKEY_CLEARED;
        } else {
            $appkey = self::APPKEY_UNCHANGED;
        }

        if ($vendorenabled && !config::has_app_key()) {
            throw new \invalid_parameter_exception('The vendor integration cannot be enabled without an App Key');
        }
        config::set_vendor_enabled($vendorenabled);

        settings_updated::create([
            'context' => \context_system::instance(),
            'other' => ['appkey' => $appkey, 'vendorenabled' => $vendorenabled],
        ])->trigger();

        return $appkey;
    }
}
