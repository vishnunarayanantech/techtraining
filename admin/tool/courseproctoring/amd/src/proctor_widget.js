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
 * Starts the vendor's proctoring widget on a page of a proctored course.
 *
 * The URL is built on the server (tool_courseproctoring\local\vendor\vendor_client), so this module knows
 * nothing about parameters or personal data. It loads the vendor bundle through Moodle's RequireJS (the bundle
 * is an AMD module that must find its own <script src>) and calls its init() with the mount point.
 *
 * @module     tool_courseproctoring/proctor_widget
 * @copyright  2026 Techversant
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Log from 'core/log';
import Pending from 'core/pending';

/** Mount point of the vendor widget; the same id the vendor bundle was written for. */
export const ROOT_ID = 'proctor-widget-root';

/**
 * Load the vendor bundle and start it.
 *
 * Does nothing if a widget root already exists on the page, so a second widget is never mounted next to
 * another one (for example from another proctoring integration on the same site) or twice by this plugin.
 *
 * @param {String} scriptUrl The vendor bundle URL, including its parameters.
 */
export const init = (scriptUrl) => {
    if (document.getElementById(ROOT_ID)) {
        Log.debug('tool_courseproctoring: a proctoring widget is already present, not starting another one.');
        return;
    }

    const pending = new Pending('tool_courseproctoring/proctor_widget');

    const root = document.createElement('div');
    root.id = ROOT_ID;
    root.dataset.toolCourseproctoring = '1';
    document.body.appendChild(root);

    // The global RequireJS loader: the vendor bundle is an AMD module, loaded by URL.
    window.require([scriptUrl], (client) => {
        if (client && typeof client.init === 'function') {
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
