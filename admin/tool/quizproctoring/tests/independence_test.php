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

namespace tool_quizproctoring;

/**
 * Guards the requirement that this plugin is self-contained: it must not depend on any other proctoring set-up on the
 * site (another plugin, its tables, or edits made to core files), whatever state that set-up is in.
 *
 * @package    tool_quizproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class independence_test extends \advanced_testcase {
    /**
     * The plugin's own code (not tests, documentation or the built JavaScript).
     *
     * @return string[] relative path => contents
     */
    private function plugin_code(): array {
        $root = realpath(__DIR__ . '/..');
        $code = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if (
                !$file->isFile()
                || preg_match('#^(tests|docs|amd/build)/#', $relative)
                || !preg_match('/\.(php|mustache|js|xml)$/', $relative)
            ) {
                continue;
            }
            $code[$relative] = file_get_contents($file->getPathname());
        }
        return $code;
    }

    /**
     * Nothing in the plugin's code refers to the older local_techstack plugin, its tables or its hook.
     */
    public function test_code_never_refers_to_the_older_plugin(): void {
        $code = $this->plugin_code();
        $this->assertNotEmpty($code, 'the scan found the plugin files');
        $this->assertArrayHasKey('classes/hook_callbacks.php', $code);

        $offenders = [];
        foreach ($code as $path => $contents) {
            if (stripos($contents, 'techstack') !== false) {
                $offenders[] = $path;
            }
        }
        $this->assertSame([], $offenders, 'files that mention the older plugin');
    }

    /**
     * The plugin never reads the source of other files (for example to check whether core files were edited): its
     * behaviour cannot depend on the state of code it does not own.
     */
    public function test_code_never_reads_source_files_of_the_site(): void {
        $offenders = [];
        foreach ($this->plugin_code() as $path => $contents) {
            $readers = 'file_get_contents|token_get_all|highlight_file|show_source|php_strip_whitespace';
            if (preg_match('/\b(' . $readers . ')\s*\(/', $contents)) {
                $offenders[] = $path;
            }
        }
        $this->assertSame([], $offenders, 'files that read source files');
    }

    /**
     * The plugin declares no dependency except mod_quiz, which it cannot work without.
     */
    public function test_declared_dependencies(): void {
        $plugin = new \stdClass();
        require(__DIR__ . '/../version.php');
        $this->assertSame(['mod_quiz' => ANY_VERSION], $plugin->dependencies);
    }
}
