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

namespace tool_quizproctoring\task;

use tool_quizproctoring\local\close_service;

/**
 * Adhoc task that retries closing the vendor session of one quiz attempt.
 *
 * Queued by the observer when the first, synchronous attempt fails in a retryable way. Moodle retries
 * a failing adhoc task with exponential back-off (60 s doubling to 24 h, 12 attempts by default). On the
 * last allowed attempt a retryable failure is recorded as final in the session ledger instead of
 * throwing, so a session never stays "active" forever. Requires Moodle cron to be running.
 *
 * Only the attempt id is stored in the task; the App Key and Key ID are read from configuration and the
 * session ledger when the task runs.
 *
 * @package    tool_quizproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class close_session extends \core\task\adhoc_task {
    /**
     * Queue a retry for an attempt (no duplicate if one is already queued).
     *
     * @param int $attemptid
     */
    public static function queue(int $attemptid): void {
        $task = new self();
        $task->set_component('tool_quizproctoring');
        $task->set_custom_data(['attemptid' => $attemptid]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    #[\Override]
    public function get_name(): string {
        return get_string('task_closesession', 'tool_quizproctoring');
    }

    #[\Override]
    public function execute(): void {
        $data = $this->get_custom_data();
        $attemptid = (int) ($data->attemptid ?? 0);
        if ($attemptid <= 0) {
            return;
        }

        // The last allowed run records a retryable failure as final rather than throwing again.
        $lastchance = $this->get_attempts_available() <= 1;
        $outcome = (new close_service())->close_attempt($attemptid, $lastchance);

        if ($outcome === close_service::RETRY) {
            // Throwing makes Moodle reschedule this task with back-off.
            throw new \moodle_exception('closeretry', 'tool_quizproctoring', '', $attemptid);
        }
    }
}
