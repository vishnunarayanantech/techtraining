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

namespace tool_courseproctoring\event;

/**
 * The site-wide course proctoring settings were saved.
 *
 * Records only WHAT changed (App Key set / cleared / unchanged, integration on or off), never the App Key.
 *
 * @package    tool_courseproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class settings_updated extends \core\event\base {
    #[\Override]
    protected function init(): void {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    #[\Override]
    public static function get_name(): string {
        return get_string('event_settingsupdated', 'tool_courseproctoring');
    }

    #[\Override]
    public function get_description(): string {
        $enabled = $this->other['vendorenabled'] ? 'on' : 'off';
        return "The user with id '{$this->userid}' saved the course proctoring settings " .
            "(App Key: {$this->other['appkey']}, vendor integration: {$enabled}).";
    }

    #[\Override]
    public function get_url(): \moodle_url {
        return new \moodle_url('/admin/tool/courseproctoring/index.php');
    }

    #[\Override]
    protected function validate_data(): void {
        parent::validate_data();
        if (!isset($this->other['appkey']) || !array_key_exists('vendorenabled', $this->other)) {
            throw new \coding_exception('The \'appkey\' and \'vendorenabled\' values must be set in other.');
        }
    }
}
