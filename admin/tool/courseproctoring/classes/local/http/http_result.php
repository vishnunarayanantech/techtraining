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

namespace tool_courseproctoring\local\http;

/**
 * The result of one HTTP request. Vendor-independent.
 *
 * The body is kept in memory for the caller but is never persisted or logged by this plugin.
 *
 * @package    tool_courseproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class http_result {
    /**
     * Constructor.
     *
     * @param int $status HTTP status code, or 0 if no HTTP response was received.
     * @param int $errno cURL error number, 0 if none (28 = timeout).
     * @param string $error cURL error message, empty if none.
     * @param string $body Response body.
     */
    public function __construct(
        /** @var int HTTP status code, 0 if there was no HTTP response. */
        public readonly int $status,
        /** @var int cURL error number, 0 if none. */
        public readonly int $errno = 0,
        /** @var string cURL error message. */
        public readonly string $error = '',
        /** @var string Response body. */
        public readonly string $body = '',
    ) {
    }

    /**
     * Whether no HTTP response was obtained (DNS, connect, TLS, timeout...).
     *
     * @return bool
     */
    public function is_transport_failure(): bool {
        return $this->errno !== 0 || $this->status === 0;
    }

    /**
     * Whether a 2xx response was received.
     *
     * @return bool
     */
    public function is_success(): bool {
        return !$this->is_transport_failure() && $this->status >= 200 && $this->status < 300;
    }

    /**
     * A short, secret-free description for the session ledger and logs.
     *
     * @return string
     */
    public function describe(): string {
        if ($this->is_transport_failure()) {
            return 'network error ' . $this->errno;
        }
        return 'HTTP ' . $this->status;
    }
}
