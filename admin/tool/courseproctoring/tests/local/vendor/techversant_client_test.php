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
 * Tests for everything vendor-specific. These tests pin what the code currently ASSUMES; they do not prove the
 * vendor agrees (see docs/VENDOR_QUESTIONS.md).
 *
 * @package    tool_courseproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_courseproctoring\local\vendor\techversant_client
 * @covers     \tool_courseproctoring\local\vendor\close_result
 * @covers     \tool_courseproctoring\local\vendor\widget_context
 */
final class techversant_client_test extends \advanced_testcase {
    /** @var string A session id of the shape this plugin makes (made up for the tests). */
    private const SESSION_ID = 'course_0123456789abcdef0123';

    /**
     * A widget context.
     *
     * @param array $override Constructor argument overrides.
     * @return widget_context
     */
    private function context(array $override = []): widget_context {
        return new widget_context(...array_merge([
            'keyid' => 'KEY-ABC1234',
            'sessionid' => self::SESSION_ID,
            'courseid' => '17',
            'coursename' => 'Intro to Moodle',
            'userid' => '5',
            'firstname' => 'Asha',
            'lastname' => 'Nair',
            'email' => 'asha@example.com',
            'username' => 'asha',
            'redirecturl' => 'https://lms.example/',
        ], $override));
    }

    /**
     * A decoded payload the way the vendor bundle reads it: URLSearchParams semantics (a literal "+" becomes a space),
     * then atob() and JSON.parse().
     *
     * @param string $url
     * @return array
     */
    private function decode_like_the_bundle(string $url): array {
        $query = parse_url($url, PHP_URL_QUERY);
        parse_str($query, $parsed);
        $json = base64_decode($parsed['data'], true);
        $this->assertNotFalse($json, 'the data parameter is valid base64 after URL decoding');
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Both vendor endpoints are HTTPS: the API key travels in a header and in the body.
     */
    public function test_endpoints_use_https(): void {
        $this->assertStringStartsWith('https://', techversant_client::WIDGET_ENTRY_URL);
        $this->assertStringStartsWith('https://', techversant_client::CLOSE_SESSION_URL);
    }

    /**
     * The widget parameters have the names and (string) types that existing integrations of the widget send, with the
     * real course id in course_id and this plugin's session id in session_id.
     */
    public function test_widget_params_have_the_names_and_string_types_the_widget_expects(): void {
        $params = (new techversant_client())->build_widget_params('APP-KEY-1', $this->context());

        $this->assertSame([
            'key_id' => 'KEY-ABC1234',
            'session_id' => self::SESSION_ID,
            'app_key' => 'APP-KEY-1',
            'course_id' => '17',
            'course_name' => 'Intro to Moodle',
            'user_id' => '5',
            'first_name' => 'Asha',
            'last_name' => 'Nair',
            'email_id' => 'asha@example.com',
            'redirect_url' => 'https://lms.example/',
            'user_name' => 'asha',
        ], $params);
    }

    /**
     * The widget URL is the bundle entry point plus the base64 JSON parameters.
     */
    public function test_widget_url(): void {
        $client = new techversant_client();
        $url = $client->get_widget_script_url('APP-KEY-1', $this->context());

        $this->assertStringStartsWith(techversant_client::WIDGET_ENTRY_URL . '?data=', $url);
        $this->assertSame($client->build_widget_params('APP-KEY-1', $this->context()), $this->decode_like_the_bundle($url));
    }

    /**
     * Names with characters outside Latin-1 (which make a browser's btoa() throw) and emoji arrive intact.
     */
    public function test_widget_url_survives_non_latin_names(): void {
        $context = $this->context([
            'firstname' => 'ജോൺ',
            'lastname' => 'Łukasz Ünal 🙂',
            'coursename' => 'पायथन <Basics> & "more"',
        ]);
        $url = (new techversant_client())->get_widget_script_url('APP-KEY-1', $context);

        $decoded = $this->decode_like_the_bundle($url);
        $this->assertSame('ജോൺ', $decoded['first_name']);
        $this->assertSame('Łukasz Ünal 🙂', $decoded['last_name']);
        $this->assertSame('पायथन <Basics> & "more"', $decoded['course_name']);

        // The JSON inside the base64 is pure ASCII, which is what atob() + JSON.parse() can handle.
        parse_str(parse_url($url, PHP_URL_QUERY), $parsed);
        $this->assertMatchesRegularExpression('/^[\x20-\x7E]+$/', base64_decode($parsed['data']));
    }

    /**
     * A literal "+" in the query string would be read as a space (URLSearchParams) and corrupt the payload, so the
     * base64 is percent-encoded. Craft a payload whose base64 contains both "+" and "/" to prove it.
     */
    public function test_widget_url_percent_encodes_base64_specials(): void {
        $context = $this->context(['firstname' => str_repeat('?', 12) . str_repeat('>', 12) . str_repeat('~', 12)]);
        $client = new techversant_client();
        $params = $client->build_widget_params('APP-KEY-1', $context);

        $rawbase64 = base64_encode(json_encode($params));
        $this->assertStringContainsString('+', $rawbase64, 'precondition: the payload really needs a "+"');
        $this->assertStringContainsString('/', $rawbase64, 'precondition: the payload really needs a "/"');

        $url = $client->get_widget_script_url('APP-KEY-1', $context);
        $value = substr($url, strpos($url, '?data=') + 6);
        $this->assertStringNotContainsString('+', $value);
        $this->assertStringNotContainsString('/', $value);
        $this->assertStringNotContainsString('=', $value);
        $this->assertSame($params, $this->decode_like_the_bundle($url));
    }

    /**
     * The close request: URL, JSON body (the stored session id, as a string) and the x-api-key header.
     */
    public function test_close_session_request(): void {
        $captured = [];
        $http = new http_client(function (string $url, string $body, array $headers) use (&$captured): http_result {
            $captured = compact('url', 'body', 'headers');
            return new http_result(200);
        });

        $result = (new techversant_client($http))->close_session('APP-KEY-1', 'KEY-LEDGER', self::SESSION_ID);

        $this->assertSame(techversant_client::CLOSE_SESSION_URL, $captured['url']);
        $this->assertSame(
            ['session_id_or_external' => self::SESSION_ID, 'app_key' => 'APP-KEY-1', 'key_id' => 'KEY-LEDGER'],
            json_decode($captured['body'], true)
        );
        $this->assertContains('x-api-key: APP-KEY-1', $captured['headers']);
        $this->assertContains('Content-Type: application/json', $captured['headers']);
        $this->assertSame(close_result::SUCCESS, $result->outcome);
    }

    /**
     * The (unverified, conservative) mapping from HTTP result to outcome.
     *
     * @dataProvider status_mapping
     * @param int $status
     * @param int $errno
     * @param string $expected
     */
    public function test_classify(int $status, int $errno, string $expected): void {
        $outcome = (new techversant_client())->classify(new http_result($status, $errno, $errno ? 'error text' : ''))->outcome;
        $this->assertSame($expected, $outcome);
    }

    /**
     * Status mapping cases.
     *
     * @return array
     */
    public static function status_mapping(): array {
        $success = close_result::SUCCESS;
        $retry = close_result::RETRYABLE;
        $permanent = close_result::PERMANENT;
        return [
            '200' => [200, 0, $success],
            '201' => [201, 0, $success],
            '204' => [204, 0, $success],
            'timeout' => [0, 28, $retry],
            'connection refused' => [0, 7, $retry],
            'dns failure' => [0, 6, $retry],
            'tls failure' => [0, 60, $retry],
            '408' => [408, 0, $retry],
            '425' => [425, 0, $retry],
            '429' => [429, 0, $retry],
            '500' => [500, 0, $retry],
            '502' => [502, 0, $retry],
            '503' => [503, 0, $retry],
            '504' => [504, 0, $retry],
            '301 (redirects are not followed)' => [301, 0, $permanent],
            '400' => [400, 0, $permanent],
            '401' => [401, 0, $permanent],
            '403' => [403, 0, $permanent],
            '404 is NOT assumed to mean already closed' => [404, 0, $permanent],
            '409 is NOT assumed to mean already closed' => [409, 0, $permanent],
            '410' => [410, 0, $permanent],
            '422' => [422, 0, $permanent],
        ];
    }

    /**
     * The detail kept for the ledger never contains the response body or the keys.
     */
    public function test_outcome_detail_is_free_of_secrets(): void {
        $http = new http_client(static fn(): http_result => new http_result(500, 0, '', 'error: APP-KEY-1 KEY-LEDGER'));
        $result = (new techversant_client($http))->close_session('APP-KEY-1', 'KEY-LEDGER', self::SESSION_ID);

        $this->assertSame(close_result::RETRYABLE, $result->outcome);
        $this->assertSame('HTTP 500', $result->detail);
        $this->assertStringNotContainsString('APP-KEY-1', $result->detail);
        $this->assertStringNotContainsString('KEY-LEDGER', $result->detail);
    }

    /**
     * The factory returns the real client unless a test double is set.
     */
    public function test_factory(): void {
        $this->assertInstanceOf(techversant_client::class, vendor_factory::get());
        $fake = $this->createMock(vendor_client::class);
        vendor_factory::set_override($fake);
        $this->assertSame($fake, vendor_factory::get());
        vendor_factory::set_override(null);
        $this->assertInstanceOf(techversant_client::class, vendor_factory::get());
    }
}
