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

namespace tool_courseproctoring;

/**
 * Guards the stand-alone rule: this plugin must work on a Moodle that has no other proctoring plugin, so nothing in it
 * may name, load, read from or write to one.
 *
 * What is checked:
 *  1. no file of the plugin names another proctoring plugin (this file included: the names are built from pieces);
 *  2. version.php declares no dependency on any other plugin;
 *  3. the code does not read source files, include files of other plugins or use classes of other plugins;
 *  4. every table the plugin creates is its own, and no query names a table of another plugin;
 *  5. every component name the code uses (settings, strings, classes) is this plugin's own;
 *  6. the folder holds no symbolic links that could lead outside it.
 *
 * Checks for the registrations in db/ (hooks, events, tasks, permissions) are added in the phase that adds those files.
 *
 * @package    tool_courseproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class independence_test extends \advanced_testcase {
    /** @var string This plugin's component name. */
    private const COMPONENT = 'tool_courseproctoring';
    /** @var string This plugin's folder name under admin/tool. */
    private const COMPONENT_DIR = 'courseproctoring';

    /**
     * Names of the other proctoring plugins, lower case. Built from pieces so that this file does not contain them
     * and can be scanned like every other file.
     *
     * @return string[]
     */
    private static function other_plugin_names(): array {
        return [
            'quiz' . 'proctoring',
            'quiz_' . 'proctoring',
            'quiz ' . 'proctoring',
            'tech' . 'stack',
            'tech_' . 'stack',
        ];
    }

    /**
     * Every file and link in the plugin folder, by path relative to it.
     *
     * @return \SplFileInfo[]
     */
    private function plugin_entries(): array {
        $root = realpath(__DIR__ . '/..');
        $entries = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if ($entry->isFile() || $entry->isLink()) {
                $entries[substr($entry->getPathname(), strlen($root) + 1)] = clone $entry;
            }
        }
        ksort($entries);
        return $entries;
    }

    /**
     * The plugin's PHP code: everything that is not a test, a document or built output.
     *
     * @return string[] relative path => contents
     */
    private function code_files(): array {
        $code = [];
        foreach ($this->plugin_entries() as $path => $entry) {
            if ($entry->isLink() || !preg_match('/\.php$/', $path) || preg_match('#^(tests|docs|amd/build)/#', $path)) {
                continue;
            }
            $code[$path] = file_get_contents($entry->getPathname());
        }
        return $code;
    }

    /**
     * Whether a table name belongs to another plugin (or to the quiz module).
     *
     * @param string $name Table name without the Moodle prefix.
     * @return bool
     */
    private static function is_foreign_table(string $name): bool {
        if (str_starts_with($name, self::COMPONENT . '_')) {
            return false;
        }
        $pluginprefixes = '/^(?:tool|local|block|report|format|mod|enrol|auth|quiz|qtype|theme)_/';
        return $name === 'quiz' || (bool) preg_match($pluginprefixes, $name);
    }

    /**
     * 1. No file, and no file name, mentions another proctoring plugin.
     */
    public function test_no_file_names_another_plugin(): void {
        $offenders = [];
        $scanned = 0;
        foreach ($this->plugin_entries() as $path => $entry) {
            if ($entry->isLink()) {
                continue;
            }
            $scanned++;
            $haystack = strtolower($path . "\n" . file_get_contents($entry->getPathname()));
            foreach (self::other_plugin_names() as $name) {
                if (str_contains($haystack, $name)) {
                    $offenders[] = $path . ' mentions "' . $name . '"';
                }
            }
        }
        $this->assertGreaterThan(5, $scanned, 'the scan found the plugin files');
        $this->assertSame([], $offenders, 'files that name another proctoring plugin');
    }

    /**
     * 2. The plugin requires no other plugin, not even the quiz module.
     */
    public function test_declares_no_dependencies(): void {
        $plugin = new \stdClass();
        require(__DIR__ . '/../version.php');

        $this->assertSame(self::COMPONENT, $plugin->component);
        $this->assertSame([], (array) ($plugin->dependencies ?? []), 'no other plugin is required');
    }

    /**
     * 3. The code stays inside this plugin: it reads no source files, includes no files of other plugins and uses no
     * classes of other modules.
     */
    public function test_code_stays_inside_the_plugin(): void {
        $code = $this->code_files();
        $this->assertArrayHasKey('settings.php', $code, 'the scan found the plugin code');

        $readers = 'file_get_contents|token_get_all|highlight_file|show_source|php_strip_whitespace';
        $otherplugins = '#/(?:local|mod|blocks|report|theme)/|/admin/tool/(?!' . self::COMPONENT_DIR . '/)#';

        $offenders = [];
        foreach ($code as $path => $contents) {
            // Behaviour must not depend on the state of code this plugin does not own.
            if (preg_match('/\b(?:' . $readers . ')\s*\(/', $contents)) {
                $offenders[] = $path . ' reads source files';
            }
            if (preg_match_all('/\b(?:require|include)(?:_once)?\b[^;]*;/', $contents, $statements)) {
                foreach ($statements[0] as $statement) {
                    if (preg_match($otherplugins, $statement)) {
                        $offenders[] = $path . ' includes a file of another plugin: ' . trim($statement);
                    }
                }
            }
            if (preg_match('/\bmod_[a-z0-9_]+\\\\/', $contents) || str_contains($contents, 'mod_quiz')) {
                $offenders[] = $path . ' uses a class of another module';
            }
        }
        $this->assertSame([], $offenders);
    }

    /**
     * 4. The plugin's tables are its own, and no query touches a table of another plugin.
     */
    public function test_database_tables_are_the_plugins_own(): void {
        $xml = __DIR__ . '/../db/install.xml';
        if (file_exists($xml)) {
            $document = new \DOMDocument();
            $this->assertTrue($document->load($xml), 'install.xml is well-formed');
            foreach ($document->getElementsByTagName('TABLE') as $table) {
                $name = $table->getAttribute('NAME');
                $this->assertStringStartsWith(self::COMPONENT . '_', $name, 'a table that is not the plugin\'s own');
            }
            foreach ($document->getElementsByTagName('KEY') as $key) {
                if (in_array($key->getAttribute('TYPE'), ['foreign', 'foreign-unique'], true)) {
                    $reference = $key->getAttribute('REFTABLE');
                    $this->assertTrue(
                        in_array($reference, ['course', 'user'], true) || str_starts_with($reference, self::COMPONENT . '_'),
                        'a foreign key to "' . $reference . '", which is neither core course/user nor the plugin\'s own'
                    );
                }
            }
        }

        $offenders = [];
        foreach ($this->code_files() as $path => $contents) {
            $named = [];
            // Tables named as the first argument of a call on the database object (get_record, insert_record, ...).
            if (preg_match_all('/\$DB->\w+\(\s*[\'"]([a-z0-9_]+)[\'"]/', $contents, $calls)) {
                $named = array_merge($named, $calls[1]);
            }
            // Tables named inside SQL as {table}.
            if (preg_match_all('/\{([a-z][a-z0-9_]*)\}/', $contents, $placeholders)) {
                $named = array_merge($named, $placeholders[1]);
            }
            foreach (array_unique($named) as $name) {
                if (self::is_foreign_table($name)) {
                    $offenders[] = $path . ' names the table "' . $name . '"';
                }
            }
        }
        $this->assertSame([], $offenders, 'queries on tables of other plugins');
    }

    /**
     * 5. Every component the code names (for settings, strings, classes) is this plugin; nothing is borrowed from
     * another plugin.
     */
    public function test_components_used_by_the_code_are_this_plugins_own(): void {
        $prefixes = 'tool|local|mod|block|report|format|theme|auth|enrol|qtype|editor|filter|repository|plagiarism|tiny|'
            . 'quizaccess|assignsubmission|assignfeedback';

        $offenders = [];
        foreach ($this->code_files() as $path => $contents) {
            if (preg_match_all('/[\'"]((?:' . $prefixes . ')_[a-z0-9_]+)[\'"]/', $contents, $matches)) {
                foreach ($matches[1] as $name) {
                    if (!str_starts_with($name, self::COMPONENT)) {
                        $offenders[] = $path . ' uses "' . $name . '"';
                    }
                }
            }
        }
        $this->assertSame([], $offenders, 'settings, strings or classes of another plugin');
    }

    /**
     * 6. No symbolic link: a link could lead out of the folder and the zip would not contain what it points to.
     */
    public function test_no_symbolic_links(): void {
        $links = [];
        foreach ($this->plugin_entries() as $path => $entry) {
            if ($entry->isLink()) {
                $links[] = $path;
            }
        }
        $this->assertSame([], $links);
    }
}
