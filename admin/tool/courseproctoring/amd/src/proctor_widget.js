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
 * Starts the vendor's proctoring widget on a page of a proctored course, and stops it when the learner completes the course.
 *
 * The URL is built on the server (tool_courseproctoring\local\vendor\vendor_client), so this module knows
 * nothing about parameters or personal data. It loads the vendor bundle through Moodle's RequireJS (the bundle
 * is an AMD module that must find its own <script src>) and calls its init() with the mount point.
 *
 * When the learner marks an activity as done, it asks the server whether proctoring is over for them in this course (the
 * server closes their vendor session the moment the course is completed). If it is, it stops the widget with the
 * vendor's own endSession(), so that the widget does not go on talking to a session that no longer exists.
 *
 * @module     tool_courseproctoring/proctor_widget
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Log from 'core/log';
import Pending from 'core/pending';

/** Mount point of the vendor widget; the same id the vendor bundle was written for. */
export const ROOT_ID = 'proctor-widget-root';

/**
 * The event Moodle's "Mark as done" button fires (on the button, bubbling up to the document) after the server has saved
 * the new state. Its detail has `completed`: true when the activity was marked as done, false when it was un-marked.
 */
export const MANUAL_COMPLETION_EVENT = 'core_course:manualcompletiontoggled';

/** The external function (see classes/external/is_proctoring_over.php). */
export const IS_OVER_FUNCTION = 'tool_courseproctoring_is_proctoring_over';

/**
 * Stop the widget, once, with the vendor's own call.
 *
 * @param {Object} state The state of this page's widget: {client, ended, listener}.
 */
const endWidget = (state) => {
    if (state.ended) {
        return;
    }
    state.ended = true;
    document.removeEventListener(MANUAL_COMPLETION_EVENT, state.listener);

    if (!state.client) {
        // The vendor bundle has not finished loading: it will not be started (see init()).
        Log.debug('tool_courseproctoring: proctoring is over before the widget started, it will not start.');
        return;
    }
    if (typeof state.client.endSession !== 'function') {
        Log.error('tool_courseproctoring: the proctoring bundle did not provide endSession().');
        return;
    }
    try {
        state.client.endSession(ROOT_ID);
    } catch (error) {
        Log.error('tool_courseproctoring: the proctoring bundle failed to end the session.', error);
    }
};

/**
 * Ask the server whether proctoring is over for this learner in this course, and stop the widget if it is.
 *
 * Any failure (network, session expired, ...) is only logged: the widget then simply keeps running, and the next page
 * decides again.
 *
 * @param {Object} state The state of this page's widget.
 * @param {Number} courseId The course of the page.
 */
const stopWidgetIfOver = (state, courseId) => {
    const pending = new Pending('tool_courseproctoring/is_proctoring_over');
    Ajax.call([{methodname: IS_OVER_FUNCTION, args: {courseid: courseId}}])[0].then((result) => {
        if (result && result.over === true) {
            endWidget(state);
        }
        pending.resolve();
        return null;
    }, (error) => {
        Log.debug('tool_courseproctoring: could not ask whether proctoring is over.', error);
        pending.resolve();
        return null;
    });
};

/**
 * Load the vendor bundle and start it.
 *
 * Does nothing if a widget root already exists on the page, so a second widget is never mounted next to
 * another one (for example from another proctoring integration on the same site) or twice by this plugin.
 *
 * @param {String} scriptUrl The vendor bundle URL, including its parameters.
 * @param {Number} courseId The course of the page; without it the widget is started but never stopped by this module.
 */
export const init = (scriptUrl, courseId = 0) => {
    if (document.getElementById(ROOT_ID)) {
        Log.debug('tool_courseproctoring: a proctoring widget is already present, not starting another one.');
        return;
    }

    const pending = new Pending('tool_courseproctoring/proctor_widget');
    const state = {client: null, ended: false, listener: null};

    if (courseId > 0) {
        state.listener = (event) => {
            // Only marking an activity as done can complete a course; un-marking one cannot.
            if (event.detail && event.detail.completed === true) {
                stopWidgetIfOver(state, courseId);
            }
        };
        document.addEventListener(MANUAL_COMPLETION_EVENT, state.listener);
    }

    const root = document.createElement('div');
    root.id = ROOT_ID;
    root.dataset.toolCourseproctoring = '1';
    document.body.appendChild(root);

    // The global RequireJS loader: the vendor bundle is an AMD module, loaded by URL.
    window.require([scriptUrl], (client) => {
        if (state.ended) {
            Log.debug('tool_courseproctoring: proctoring ended while the bundle was loading, not starting the widget.');
        } else if (client && typeof client.init === 'function') {
            state.client = client;
            client.init(ROOT_ID);
        } else {
            Log.error('tool_courseproctoring: the proctoring bundle did not provide init().');
        }
        pending.resolve();
    }, (error) => {
        Log.error('tool_courseproctoring: failed to load the proctoring bundle.', error);
        pending.resolve();
    });
};
