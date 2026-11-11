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

namespace tool_courseproctoring\local;

use tool_courseproctoring\local\http\http_client;
use tool_courseproctoring\local\http\http_result;

/**
 * Tests for the vendor-independent HTTP transport, including a real local server for timeouts and redirects.
 *
 * @package    tool_courseproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_courseproctoring\local\http\http_client
 * @covers     \tool_courseproctoring\local\http\http_result
 */
final class http_client_test extends \advanced_testcase {
    /** @var resource|null The built-in web server process. */
    private $server = null;
    /** @var int Its port. */
    private int $port = 0;

    #[\Override]
    protected function tearDown(): void {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        parent::tearDown();
    }

    /**
     * Pick a free local port and let Moodle's curl talk to it, optionally with PHP's built-in web server listening there.
     *
     * @param bool $listen False to leave the port closed.
     */
    private function start_server(bool $listen = true): void {
        global $CFG;
        $this->resetAfterTest();

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        $this->port = (int) substr(strrchr($name, ':'), 1);

        // Moodle blocks requests to localhost and to unusual ports by default (SSRF protection); open just this one.
        $CFG->curlsecurityblockedhosts = '';
        $CFG->curlsecurityallowedport = "80\n443\n" . $this->port;

        if (!$listen) {
            return;
        }
        // A single-process server: one request at a time, and terminating it ends it completely.
        $this->server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, __DIR__ . '/../fixtures/http_router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        $connection = false;
        for ($i = 0; $i < 50 && !$connection; $i++) {
            $connection = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.2);
            if (!$connection) {
                usleep(100000);
            }
        }
        if (!$connection) {
            $this->markTestSkipped('The local test web server could not be started.');
        }
        fclose($connection);
    }

    /**
     * Every option that matters is set explicitly: bounded time, TLS verification, no redirects.
     */
    public function test_curl_options(): void {
        $options = http_client::curl_options();
        $this->assertSame(3, $options['CURLOPT_CONNECTTIMEOUT']);
        $this->assertSame(6, $options['CURLOPT_TIMEOUT']);
        $this->assertGreaterThan($options['CURLOPT_CONNECTTIMEOUT'], $options['CURLOPT_TIMEOUT']);
        $this->assertSame(1, $options['CURLOPT_SSL_VERIFYPEER']);
        $this->assertSame(2, $options['CURLOPT_SSL_VERIFYHOST']);
        $this->assertSame(0, $options['CURLOPT_FOLLOWLOCATION']);
        $this->assertSame(0, $options['CURLOPT_MAXREDIRS']);
    }

    /**
     * The request carries a JSON body and the content-type header; the executor's result is returned untouched.
     */
    public function test_post_json_builds_the_request(): void {
        $captured = [];
        $client = new http_client(function (string $url, string $body, array $headers) use (&$captured): http_result {
            $captured = compact('url', 'body', 'headers');
            return new http_result(204);
        });

        $result = $client->post_json('https://api.example/close', ['a' => 'b/c', 'n' => 1], ['x-api-key: K']);

        $this->assertSame('https://api.example/close', $captured['url']);
        $this->assertSame('{"a":"b/c","n":1}', $captured['body']);
        $this->assertContains('Content-Type: application/json', $captured['headers']);
        $this->assertContains('x-api-key: K', $captured['headers']);
        $this->assertSame(204, $result->status);
        $this->assertTrue($result->is_success());
    }

    /**
     * Success, HTTP errors and transport failures are told apart.
     */
    public function test_result_classification(): void {
        $this->assertTrue((new http_result(200))->is_success());
        $this->assertTrue((new http_result(299))->is_success());
        $this->assertFalse((new http_result(301))->is_success());
        $this->assertFalse((new http_result(404))->is_success());
        $this->assertFalse((new http_result(500))->is_transport_failure(), 'an HTTP error is a response, not a transport failure');
        $this->assertTrue((new http_result(0))->is_transport_failure());
        $this->assertTrue((new http_result(200, 28, 'timeout'))->is_transport_failure(), 'a cURL error wins over a status');
        $this->assertFalse((new http_result(200, 28, 'timeout'))->is_success());

        $this->assertSame('HTTP 503', (new http_result(503))->describe());
        $this->assertSame('network error 28', (new http_result(0, 28, 'secret details'))->describe());
    }

    /**
     * Real request: method, JSON body, headers and a 2xx response.
     */
    public function test_real_request_succeeds(): void {
        $this->start_server();
        $result = (new http_client())->post_json(
            'http://127.0.0.1:' . $this->port . '/ok',
            ['session_id_or_external' => 'course_0123456789abcdef0123'],
            ['x-api-key: abc']
        );

        $this->assertTrue($result->is_success(), $result->describe());
        $echo = json_decode($result->body, true);
        $this->assertSame('POST', $echo['method']);
        $this->assertSame('{"session_id_or_external":"course_0123456789abcdef0123"}', $echo['body']);
        $this->assertSame('application/json', $echo['contenttype']);
        $this->assertSame('abc', $echo['apikey']);
    }

    /**
     * A 5xx response is returned as an HTTP error with its status.
     */
    public function test_real_http_error_is_a_response(): void {
        $this->start_server();
        $result = (new http_client())->post_json('http://127.0.0.1:' . $this->port . '/error', []);
        $this->assertSame(503, $result->status);
        $this->assertFalse($result->is_success());
        $this->assertFalse($result->is_transport_failure());
    }

    /**
     * Redirects are not followed, so the API key header cannot be forwarded to another URL.
     */
    public function test_real_redirect_is_not_followed(): void {
        $this->start_server();
        $result = (new http_client())->post_json('http://127.0.0.1:' . $this->port . '/redirect', []);
        $this->assertSame(302, $result->status);
        $this->assertFalse($result->is_success());
    }

    /**
     * A server that accepts the connection but never answers cannot hold the caller for longer than the total timeout.
     */
    public function test_real_total_timeout_is_bounded(): void {
        $this->start_server();
        $start = microtime(true);
        $result = (new http_client())->post_json('http://127.0.0.1:' . $this->port . '/slow', []);
        $elapsed = microtime(true) - $start;

        $this->assertTrue($result->is_transport_failure());
        $this->assertSame(CURLE_OPERATION_TIMEDOUT, $result->errno);
        $this->assertGreaterThanOrEqual(http_client::TOTAL_TIMEOUT - 1, $elapsed);
        $this->assertLessThan(http_client::TOTAL_TIMEOUT + 3, $elapsed, 'returned long before the 20 second response');
    }

    /**
     * A refused connection fails fast and does not throw.
     */
    public function test_real_connection_refused(): void {
        $this->start_server(false);

        $start = microtime(true);
        $result = (new http_client())->post_json('http://127.0.0.1:' . $this->port . '/ok', []);
        $this->assertTrue($result->is_transport_failure());
        $this->assertNotSame(0, $result->errno);
        $this->assertLessThan(http_client::CONNECT_TIMEOUT + 2, microtime(true) - $start);
    }

    /**
     * Moodle's SSRF protection stays on: with the default blocklist a local address is refused, not contacted.
     */
    public function test_moodle_security_blocklist_still_applies(): void {
        $this->start_server();
        global $CFG;
        $CFG->curlsecurityblockedhosts = "127.0.0.0/8\nlocalhost";

        $result = (new http_client())->post_json('http://127.0.0.1:' . $this->port . '/ok', []);
        $this->assertFalse($result->is_success());
        $this->assertTrue($result->is_transport_failure());
        // Moodle reports the blocked URL through debugging() and a url_blocked event.
        $this->assertDebuggingCalled();
    }
}
