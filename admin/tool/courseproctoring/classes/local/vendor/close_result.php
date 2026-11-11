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

namespace tool_courseproctoring\local\vendor;

/**
 * The vendor-neutral outcome of a close-session request.
 *
 * The rest of the plugin only ever sees these three outcomes; which HTTP statuses map to which one
 * is vendor-specific knowledge and lives in the vendor_client implementation.
 *
 * @package    tool_courseproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class close_result {
    /** @var string The session is closed. */
    public const SUCCESS = 'success';
    /** @var string The request failed in a way that may succeed later (network, timeout, server error). */
    public const RETRYABLE = 'retryable';
    /** @var string The request failed and retrying will not help. */
    public const PERMANENT = 'permanent';

    /**
     * Constructor.
     *
     * @param string $outcome One of the class constants.
     * @param string $detail Short, secret-free description (e.g. "HTTP 503").
     */
    private function __construct(
        /** @var string One of the outcome constants. */
        public readonly string $outcome,
        /** @var string Short, secret-free description. */
        public readonly string $detail = '',
    ) {
    }

    /**
     * Session closed.
     *
     * @return self
     */
    public static function success(): self {
        return new self(self::SUCCESS);
    }

    /**
     * Temporary failure.
     *
     * @param string $detail
     * @return self
     */
    public static function retryable(string $detail): self {
        return new self(self::RETRYABLE, $detail);
    }

    /**
     * Permanent failure.
     *
     * @param string $detail
     * @return self
     */
    public static function permanent(string $detail): self {
        return new self(self::PERMANENT, $detail);
    }
}
