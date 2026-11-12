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
 * A course's proctoring Key ID was created, changed or removed in the course settings.
 *
 * The Key ID value is deliberately not recorded in the log.
 *
 * @package    tool_courseproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_key_updated extends \core\event\base {
    #[\Override]
    protected function init(): void {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    #[\Override]
    public static function get_name(): string {
        return get_string('event_coursekeyupdated', 'tool_courseproctoring');
    }

    #[\Override]
    public function get_description(): string {
        return "The user with id '{$this->userid}' {$this->other['action']} the course proctoring Key ID " .
            "of the course with id '{$this->courseid}'.";
    }

    #[\Override]
    public function get_url(): \moodle_url {
        return new \moodle_url('/course/edit.php', ['id' => $this->courseid]);
    }

    #[\Override]
    protected function validate_data(): void {
        parent::validate_data();
        if (!isset($this->other['action'])) {
            throw new \coding_exception('The \'action\' value must be set in other.');
        }
        if ($this->contextlevel != CONTEXT_COURSE) {
            throw new \coding_exception('The event context must be a course context.');
        }
    }
}
