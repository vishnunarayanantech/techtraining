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
 * A small, vendor-independent JSON-over-HTTP transport with bounded time and TLS verification.
 *
 * Why not just `new curl()`: Moodle's curl class sets a 30 second connect timeout, no total
 * timeout at all, and CURLOPT_SSL_VERIFYPEER = 0 by default (lib/filelib.php). Every option that
 * matters is therefore set explicitly here. Moodle's own SSRF protection (curlsecurityblockedhosts)
 * is left switched on.
 *
 * @package    tool_courseproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class http_client {
    /** @var int Seconds allowed to establish the connection. */
    public const CONNECT_TIMEOUT = 3;
    /** @var int Seconds allowed for the whole request, including connecting. */
    public const TOTAL_TIMEOUT = 6;

    /** @var callable|null Replacement for the real request (tests): fn(string $url, string $body, string[] $headers): http_result. */
    private $executor;

    /**
     * Constructor.
     *
     * @param callable|null $executor Test double for the network call.
     */
    public function __construct(?callable $executor = null) {
        $this->executor = $executor;
    }

    /**
     * The cURL options applied to every request.
     *
     * @return array
     */
    public static function curl_options(): array {
        return [
            'CURLOPT_CONNECTTIMEOUT' => self::CONNECT_TIMEOUT,
            'CURLOPT_TIMEOUT' => self::TOTAL_TIMEOUT,
            'CURLOPT_SSL_VERIFYPEER' => 1,
            'CURLOPT_SSL_VERIFYHOST' => 2,
            // Never follow redirects: the request carries an API key header.
            'CURLOPT_FOLLOWLOCATION' => 0,
            'CURLOPT_MAXREDIRS' => 0,
            'CURLOPT_RETURNTRANSFER' => 1,
        ];
    }

    /**
     * POST a JSON document.
     *
     * @param string $url
     * @param array $payload Encoded as JSON.
     * @param string[] $headers Extra header lines such as "x-api-key: value".
     * @return http_result Never throws for network or HTTP errors.
     */
    public function post_json(string $url, array $payload, array $headers = []): http_result {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $headers = array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers);

        if ($this->executor !== null) {
            return ($this->executor)($url, $body, $headers);
        }
        return $this->execute_with_curl($url, $body, $headers);
    }

    /**
     * Perform the request with Moodle's curl wrapper.
     *
     * @param string $url
     * @param string $body
     * @param string[] $headers
     * @return http_result
     */
    private function execute_with_curl(string $url, string $body, array $headers): http_result {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $curl = new \curl();
        $curl->setHeader($headers);
        $response = $curl->post($url, $body, self::curl_options());

        $info = $curl->get_info();
        return new http_result(
            (int) ($info['http_code'] ?? 0),
            (int) $curl->get_errno(),
            (string) $curl->error,
            is_string($response) ? $response : '',
        );
    }
}
