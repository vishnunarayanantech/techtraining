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

use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;
use tool_quizproctoring\local\close_service;
use tool_quizproctoring\local\config;
use tool_quizproctoring\local\vendor\close_result;
use tool_quizproctoring\local\vendor\vendor_client;
use tool_quizproctoring\local\vendor\widget_context;

/**
 * Shared helpers for the tool_quizproctoring tests: real quiz attempts, plugin configuration and a fake vendor.
 *
 * @package    tool_quizproctoring
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait attempt_helper_trait {
    /**
     * Create a course with a quiz (one question), a student and a started attempt.
     *
     * @param array $options Optional 'course', 'quiz' (existing records) and 'user'.
     * @return \stdClass course, quiz, cm, user, attempt (record), attemptobj (quiz_attempt)
     */
    protected function create_quiz_attempt(array $options = []): \stdClass {
        $generator = $this->getDataGenerator();
        $course = $options['course'] ?? $generator->create_course();
        $user = $options['user'] ?? $generator->create_and_enrol($course, 'student');

        $quiz = $options['quiz'] ?? null;
        if ($quiz === null) {
            $quiz = $generator->get_plugin_generator('mod_quiz')
                ->create_instance(['course' => $course->id, 'grade' => 100.0, 'sumgrades' => 1]);
            $questiongenerator = $generator->get_plugin_generator('core_question');
            $category = $questiongenerator->create_question_category();
            $question = $questiongenerator->create_question('truefalse', null, ['category' => $category->id]);
            quiz_add_quiz_question($question->id, $quiz);
        }

        $quizobj = quiz_settings::create($quiz->id, $user->id);
        $attempt = quiz_prepare_and_start_new_attempt($quizobj, 1, null, false, [], [], $user->id);

        return (object) [
            'course' => $course,
            'quiz' => $quiz,
            'cm' => get_coursemodule_from_instance('quiz', $quiz->id),
            'user' => $user,
            'attempt' => $attempt,
            'attemptobj' => quiz_attempt::create($attempt->id),
        ];
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
            public function close_session(string $appkey, string $keyid, int $attemptid): close_result {
                if ($this->throw) {
                    throw $this->throw;
                }
                $this->closes[] = ['appkey' => $appkey, 'keyid' => $keyid, 'attemptid' => $attemptid];
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
