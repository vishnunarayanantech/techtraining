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
 * Everything the plugin needs from the proctoring vendor, behind one interface.
 *
 * Nothing outside this namespace knows a vendor URL, header name, parameter name or status code.
 * See docs/VENDOR_QUESTIONS.md for what is still unverified.
 *
 * @package    tool_courseproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface vendor_client {
    /**
     * The URL of the script that starts the proctoring widget in the learner's browser.
     *
     * The URL carries the session parameters, including personal data and the App Key, because the
     * current vendor bundle can only read them from its own script URL.
     *
     * @param string $appkey
     * @param widget_context $context
     * @return string
     */
    public function get_widget_script_url(string $appkey, widget_context $context): string;

    /**
     * Ask the vendor to close a session. Must not throw for network or HTTP errors.
     *
     * @param string $appkey
     * @param string $keyid Key ID the session was started with.
     * @param string $sessionid The vendor session id, exactly as it was stored when the session started.
     * @return close_result
     */
    public function close_session(string $appkey, string $keyid, string $sessionid): close_result;
}
