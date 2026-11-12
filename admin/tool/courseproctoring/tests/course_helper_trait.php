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

use tool_courseproctoring\local\close_service;
use tool_courseproctoring\local\config;
use tool_courseproctoring\local\course_facts;
use tool_courseproctoring\local\course_key_repository;
use tool_courseproctoring\local\vendor\close_result;
use tool_courseproctoring\local\vendor\vendor_client;
use tool_courseproctoring\local\vendor\widget_context;

/**
 * Shared helpers for the tool_courseproctoring tests: a proctored course with a learner, plugin configuration and a
 * fake vendor.
 *
 * @package    tool_courseproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait course_helper_trait {
    /**
     * Create a course with completion tracking and a Proctor Key, and a student enrolled in it.
     *
     * @param array $options 'key' (default KEY-COURSE, '' for no key), 'completion' (default true).
     * @return \stdClass course, user (the student), keyid
     */
    protected function create_proctored_course(array $options = []): \stdClass {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => ($options['completion'] ?? true) ? 1 : 0]);
        $user = $generator->create_and_enrol($course, 'student');
        $keyid = $options['key'] ?? 'KEY-COURSE';
        if ($keyid !== '') {
            (new course_key_repository())->set((int) $course->id, $keyid);
        }
        return (object) ['course' => $course, 'user' => $user, 'keyid' => $keyid];
    }

    /**
     * Switch the plugin on with an App Key.
     *
     * @param string $appkey
     */
    protected function enable_integration(string $appkey = 'test-app-key-0123456789'): void {
        config::set_app_key($appkey);
        config::set_vendor_enabled(true);
    }

    /**
     * Facts about a page of a course, as seen by one learner (a page of a normal activity unless overridden).
     *
     * @param \stdClass $data see create_proctored_course()
     * @param array $override Constructor argument overrides of {@see course_facts}.
     * @return course_facts
     */
    protected function facts_for(\stdClass $data, array $override = []): course_facts {
        return new course_facts(...array_merge([
            'courseid' => (int) $data->course->id,
            'layout' => 'incourse',
            'pagetype' => 'mod-page-view',
            'activitytype' => 'page',
            'userid' => (int) $data->user->id,
            'loggedin' => true,
            'loggedinas' => false,
            'roleswitched' => false,
            'siteadmin' => false,
        ], $override));
    }

    /**
     * Record that a learner has completed a course.
     *
     * @param int $userid
     * @param int $courseid
     */
    protected function complete_course(int $userid, int $courseid): void {
        global $DB;
        $DB->insert_record('course_completions', (object) [
            'userid' => $userid,
            'course' => $courseid,
            'timeenrolled' => 0,
            'timestarted' => 0,
            'timecompleted' => time(),
            'reaggregate' => 0,
        ]);
        // Moodle caches who has completed what; its own completion code clears this cache when it records a completion.
        \cache::make('core', 'coursecompletion')->purge();
    }

    /**
     * Build a fake vendor client that records its calls.
     *
     * @param string[] $outcomes close_result outcomes to return in turn (the last one repeats)
     * @return vendor_client&object{closes: array, contexts: array, appkeys: array}
     */
    protected function create_fake_vendor(array $outcomes = [close_result::SUCCESS]): vendor_client {
        return new class ($outcomes) implements vendor_client {
            /** @var array Arguments of every close_session() call. */
            public array $closes = [];
            /** @var widget_context[] Contexts passed to get_widget_script_url(). */
            public array $contexts = [];
            /** @var string[] App keys passed to get_widget_script_url(). */
            public array $appkeys = [];
            /** @var string[] Outcomes to return. */
            private array $outcomes;
            /** @var \Throwable|null Thrown instead of returning, if set. */
            public ?\Throwable $throw = null;

            /**
             * Constructor.
             *
             * @param string[] $outcomes
             */
            public function __construct(array $outcomes) {
                $this->outcomes = $outcomes;
            }

            #[\Override]
            public function get_widget_script_url(string $appkey, widget_context $context): string {
                if ($this->throw) {
                    throw $this->throw;
                }
                $this->appkeys[] = $appkey;
                $this->contexts[] = $context;
                return 'https://vendor.example/widget?session=' . $context->sessionid;
            }

            #[\Override]
            public function close_session(string $appkey, string $keyid, string $sessionid): close_result {
                if ($this->throw) {
                    throw $this->throw;
                }
                $this->closes[] = ['appkey' => $appkey, 'keyid' => $keyid, 'sessionid' => $sessionid];
                $outcome = count($this->outcomes) > 1 ? array_shift($this->outcomes) : $this->outcomes[0];
                return match ($outcome) {
                    close_result::SUCCESS => close_result::success(),
                    close_result::RETRYABLE => close_result::retryable('HTTP 503'),
                    default => close_result::permanent('HTTP 400'),
                };
            }
        };
    }

    /**
     * A close service wired to the given fake vendor.
     *
     * @param vendor_client $vendor
     * @param int $locktimeout
     * @return close_service
     */
    protected function create_close_service(vendor_client $vendor, int $locktimeout = 1): close_service {
        return new close_service(null, $vendor, $locktimeout);
    }
}
