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

use tool_courseproctoring\local\http\http_client;
use tool_courseproctoring\local\http\http_result;

/**
 * The Techversant proctoring service.
 *
 * ALL vendor-specific behaviour is in this class. Every constant and mapping below is taken from the public widget
 * bundle and from how existing integrations call this vendor's service; none of it is confirmed by vendor
 * documentation. See docs/VENDOR_QUESTIONS.md before enabling.
 *
 * @package    tool_courseproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class techversant_client implements vendor_client {
    /** @var string Widget bundle entry point. UNVERIFIED as a stable contract. */
    public const WIDGET_ENTRY_URL = 'https://dedylcfq70vm0.cloudfront.net/';

    /** @var string Close-session endpoint. UNVERIFIED as a stable contract. */
    public const CLOSE_SESSION_URL = 'https://proctoring.api.techversantinfotech.com/proctor-client/session/close';

    /** @var http_client */
    private http_client $http;

    /**
     * Constructor.
     *
     * @param http_client|null $http
     */
    public function __construct(?http_client $http = null) {
        $this->http = $http ?? new http_client();
    }

    #[\Override]
    public function get_widget_script_url(string $appkey, widget_context $context): string {
        // Without JSON_UNESCAPED_UNICODE, json_encode() writes every non-ASCII character as \uXXXX, so the
        // base64 input is pure ASCII. The bundle decodes with atob() + JSON.parse(), which only works for
        // that; a client-side btoa(JSON.stringify()) throws for names with characters above U+00FF.
        $encoded = base64_encode(json_encode($this->build_widget_params($appkey, $context)));

        // The value is percent-encoded because the bundle reads it with URLSearchParams, which turns a literal
        // "+" into a space.
        return self::WIDGET_ENTRY_URL . '?data=' . rawurlencode($encoded);
    }

    /**
     * The widget parameters, with exactly the names and string types that existing integrations of the widget send.
     *
     * @param string $appkey
     * @param widget_context $context
     * @return array
     */
    public function build_widget_params(string $appkey, widget_context $context): array {
        return [
            'key_id' => $context->keyid,
            'session_id' => $context->sessionid,
            'app_key' => $appkey,
            // The real Moodle course id. The vendor's exact meaning of course_id is UNVERIFIED.
            'course_id' => $context->courseid,
            'course_name' => $context->coursename,
            'user_id' => $context->userid,
            'first_name' => $context->firstname,
            'last_name' => $context->lastname,
            'email_id' => $context->email,
            'redirect_url' => $context->redirecturl,
            'user_name' => $context->username,
        ];
    }

    #[\Override]
    public function close_session(string $appkey, string $keyid, string $sessionid): close_result {
        $result = $this->http->post_json(
            self::CLOSE_SESSION_URL,
            [
                'session_id_or_external' => $sessionid,
                'app_key' => $appkey,
                'key_id' => $keyid,
            ],
            ['x-api-key: ' . $appkey],
        );
        return $this->classify($result);
    }

    /**
     * Map an HTTP result to a vendor-neutral outcome.
     *
     * UNVERIFIED and deliberately conservative: only 2xx is success; network errors, 408, 425, 429 and
     * 5xx are retried; every other status (404 and 409 included) is permanent. No status is assumed to
     * mean "already closed" until the vendor says so.
     *
     * @param http_result $result
     * @return close_result
     */
    public function classify(http_result $result): close_result {
        if ($result->is_success()) {
            return close_result::success();
        }
        if ($result->is_transport_failure() || in_array($result->status, [408, 425, 429], true) || $result->status >= 500) {
            return close_result::retryable($result->describe());
        }
        return close_result::permanent($result->describe());
    }
}
