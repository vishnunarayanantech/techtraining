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
 * Moodle-side facts handed to the vendor client when a widget session starts.
 *
 * All identifiers are strings on purpose: existing integrations of this widget send database ids as strings (they
 * come from $USER / get_record()), and the vendor's expectation is unconfirmed.
 *
 * @package    tool_courseproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class widget_context {
    /**
     * Constructor.
     *
     * @param string $keyid Proctoring Key ID of the course (its "Proctor Key").
     * @param string $sessionid The vendor session id, made by this plugin: one per learner per course.
     * @param string $courseid Course id.
     * @param string $coursename Course full name.
     * @param string $userid
     * @param string $firstname
     * @param string $lastname
     * @param string $email
     * @param string $username
     * @param string $redirecturl Site URL.
     */
    public function __construct(
        /** @var string Proctoring Key ID of the course. */
        public readonly string $keyid,
        /** @var string The vendor session id, made by this plugin. */
        public readonly string $sessionid,
        /** @var string Course id. */
        public readonly string $courseid,
        /** @var string Course full name. */
        public readonly string $coursename,
        /** @var string Moodle user id. */
        public readonly string $userid,
        /** @var string First name. */
        public readonly string $firstname,
        /** @var string Last name. */
        public readonly string $lastname,
        /** @var string E-mail address. */
        public readonly string $email,
        /** @var string User name. */
        public readonly string $username,
        /** @var string Site URL. */
        public readonly string $redirecturl,
    ) {
    }
}
