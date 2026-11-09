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

namespace tool_quizproctoring\local\vendor;

/**
 * Returns the vendor client in use. The single place to swap the vendor (or a test double).
 *
 * @package    tool_quizproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class vendor_factory {
    /** @var vendor_client|null Test double. */
    private static ?vendor_client $override = null;

    /**
     * The vendor client.
     *
     * @return vendor_client
     */
    public static function get(): vendor_client {
        return self::$override ?? new techversant_client();
    }

    /**
     * Replace the vendor client (tests only). Pass null to restore the default.
     *
     * @param vendor_client|null $client
     */
    public static function set_override(?vendor_client $client): void {
        self::$override = $client;
    }
}
