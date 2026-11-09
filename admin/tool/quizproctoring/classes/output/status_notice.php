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
 * The message that tells the administrator, in plain words, whether any quiz is being proctored right now.
 *
 * The plugin deliberately does nothing while the integration switch is off, so the page must never leave any doubt
 * about that state (an administrator who saved an App Key and Key IDs but did not tick the switch otherwise sees
 * nothing wrong, and no quiz is proctored).
 *
 * @package    tool_quizproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class status_notice {
    /**
     * The message for the current state, shown at the top of the page.
     *
     * @return array [string message, string notification type]
     */
    public static function current(): array {
        return self::for_status(config::integration_status(), false);
    }

    /**
     * The message shown right after the settings were saved.
     *
     * @return array [string message, string notification type]
     */
    public static function after_save(): array {
        return self::for_status(config::integration_status(), true);
    }

    /**
     * Message and notification type for a state.
     *
     * @param string $status A config::STATUS_* constant.
     * @param bool $justsaved Whether the settings were just saved.
     * @return array [string message, string notification type]
     */
    public static function for_status(string $status, bool $justsaved): array {
        $component = 'tool_quizproctoring';
        switch ($status) {
            case config::STATUS_ON:
                return [
                    get_string($justsaved ? 'settingssavedon' : 'statuson', $component),
                    notification::NOTIFY_SUCCESS,
                ];
            case config::STATUS_ON_WITHOUT_KEY:
                return [
                    get_string($justsaved ? 'settingssavedonwithoutkey' : 'statusonwithoutkey', $component),
                    notification::NOTIFY_ERROR,
                ];
            default:
                return [
                    get_string($justsaved ? 'settingssavedoff' : 'statusoff', $component),
                    notification::NOTIFY_WARNING,
                ];
        }
    }
}
