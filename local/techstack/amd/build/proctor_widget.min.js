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
 * Loads the external proctoring widget bundle on the quiz attempt page.
 *
 * @module     local_techstack/proctor_widget
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['require'], function(require) {

    /**
     * Build the CloudFront entry URL, with the session params (passed in from
     * attempt.php via $PAGE->requires->js_call_amd()) base64-encoded into the
     * query string the same way the original inline script did.
     *
     * @param {Object} params
     * @return {String}
     */
    var buildScriptUrl = function(params) {
        var base64str = window.btoa(JSON.stringify(params));
        var entry = 'https://dedylcfq70vm0.cloudfront.net/';
        return entry + '?data=' + base64str;
    };

    return {
        /**
         * Entry point, called via $PAGE->requires->js_call_amd().
         *
         * Loads the proctor bundle directly on this page (no iframe) via
         * Moodle's own RequireJS require([url], ...), rather than manually
         * inserting a <script> tag.
         *
         * Two isolation strategies were tried and dropped before this one:
         *
         * 1. A same-origin blank iframe - avoided the collision below, but the
         *    widget's own focus/blur/visibility/fullscreen listeners then
         *    observed the iframe instead of the real page, which is unusable
         *    for a proctoring tool that needs to watch the actual quiz page.
         *
         * 2. Fetching the bundle as text and executing it as an inline
         *    classic script - kept everything on the main page and let us
         *    control timing precisely, but a manually inserted script has no
         *    real `src`, and the bundle reads its own params by looking at
         *    its own script tag's src (probably `document.currentScript.src`
         *    or a scan of `<script>` tags for its own URL) - without a real
         *    src it throws `Cannot destructure property 'lang' of
         *    window.__PROCTOR_PARAMS__ as it is null`, and re-asserting that
         *    global manually didn't hold (something inside the bundle kept
         *    resetting it).
         *
         * require([url], ...) solves both: Moodle's RequireJS inserts a real
         * <script src="..."> itself (so the bundle's own src-based param
         * reading works exactly as it was designed to), and because RequireJS
         * initiated the load itself, it correctly expects and attributes the
         * bundle's anonymous define() call instead of rejecting it as
         * "Mismatched anonymous define() module" - so window.define never
         * needs to be touched at all, which is what made the very first fix
         * attempt (temporarily nulling window.define around a manually
         * inserted <script>) unsafe: it raced with Moodle's own concurrent
         * AMD module loading and broke unrelated core modules like
         * core_question/question_engine.
         *
         * @param {Object} params
         */
        init: function(params) {
            var scriptUrl = buildScriptUrl(params);

            var root = document.getElementById('proctor-widget-root');
            if (!root) {
                root = document.createElement('div');
                root.id = 'proctor-widget-root';
                document.body.appendChild(root);
            }

            require([scriptUrl], function(proctorClient) {
                window.console.log('[host] Initializing widget...');
                if (proctorClient && typeof proctorClient.init === 'function') {
                    proctorClient.init('proctor-widget-root');
                } else {
                    window.alert('proctorClient.init not found — check your bundle export.');
                }
                window.console.log('[host] Widget initialization complete.');
            }, function(err) {
                window.console.error('eflag ~ Failed to load proctoring client:', err);
            });
        }
    };
});
