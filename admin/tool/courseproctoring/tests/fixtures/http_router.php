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

/**
 * Router for PHP's built-in web server, used by http_client_test to exercise the real transport locally.
 *
 * @package    tool_courseproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Moodle's code checking does not apply: this file runs as a stand-alone script.
// phpcs:disable moodle.Files.MoodleInternal.MoodleInternalNotNeeded

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

switch ($path) {
    case '/ok':
        header('Content-Type: application/json');
        echo json_encode([
            'method' => $_SERVER['REQUEST_METHOD'],
            'body' => file_get_contents('php://input'),
            'contenttype' => $_SERVER['CONTENT_TYPE'] ?? '',
            'apikey' => $_SERVER['HTTP_X_API_KEY'] ?? '',
        ]);
        break;
    case '/error':
        http_response_code(503);
        echo 'temporarily down';
        break;
    case '/redirect':
        header('Location: /ok', true, 302);
        break;
    case '/slow':
        sleep(20);
        echo 'too late';
        break;
    default:
        http_response_code(404);
}
