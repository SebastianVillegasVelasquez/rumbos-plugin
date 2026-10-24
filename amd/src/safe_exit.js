// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Saves what a SCORM package has reported before the activity popup is closed.
 *
 * Moodle's SCORM player keeps the package's values in an API adapter (window.API for SCORM 1.2,
 * window.API_1484_11 for SCORM 2004) that lives in the player page, which is same-origin with the host.
 * The adapter stores values only when the package commits or finishes; it has no handler of its own for
 * the page going away. Destroying the frame would leave whatever the package set since its last commit
 * to the package's own unload handlers, so the host commits first.
 *
 * It commits and does not finish: Moodle's LMSFinish marks a SCO that is still "not attempted" as
 * completed, which closing early must never do.
 *
 * Nothing here touches the DOM, so it can be unit tested with plain Node (see tests/js).
 *
 * @module     format_rumbos/safe_exit
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** SCORM error code for "not initialized": the package never started or has already finished (and stored its data). */
const NOT_INITIALIZED = '301';

/** How long the whole exit may take before the popup closes anyway (ms). */
export const EXIT_TIMEOUT_MS = 4000;

/**
 * Finds the adapter and the name of its commit function in the player window.
 *
 * @param {Window} win
 * @returns {?{api: Object, commit: string, lastError: string}}
 */
const findAdapter = (win) => {
    if (win.API_1484_11 && typeof win.API_1484_11.Commit === 'function') {
        return {api: win.API_1484_11, commit: 'Commit', lastError: 'GetLastError'};
    }
    if (win.API && typeof win.API.LMSCommit === 'function') {
        return {api: win.API, commit: 'LMSCommit', lastError: 'LMSGetLastError'};
    }
    return null;
};

/**
 * Asks the SCORM adapter in a window to store everything the package has set so far.
 *
 * The adapter's commit is a synchronous request, so when this returns the server has answered.
 *
 * @param {?Window} win The window of the activity frame.
 * @returns {{ok: boolean, reason: string}} ok is false only when data may have been lost. The reason is
 *      "committed", "no-api" (the frame is not on a player page), "not-initialized" (nothing running, or
 *      the package already finished), "unreadable", "refused" or "error".
 */
export const commitScorm = (win) => {
    let adapter = null;
    try {
        adapter = win ? findAdapter(win) : null;
    } catch (error) {
        return {ok: true, reason: 'unreadable'};
    }
    if (!adapter) {
        return {ok: true, reason: 'no-api'};
    }
    try {
        if (String(adapter.api[adapter.commit]('')) === 'true') {
            return {ok: true, reason: 'committed'};
        }
        if (String(adapter.api[adapter.lastError]()) === NOT_INITIALIZED) {
            return {ok: true, reason: 'not-initialized'};
        }
        return {ok: false, reason: 'refused'};
    } catch (error) {
        return {ok: false, reason: 'error'};
    }
};

/**
 * Leaves a SCORM activity cleanly: commit, with a limit on how long that may take.
 *
 * It always resolves, and never rejects, so the caller can close the popup whatever happened.
 *
 * @param {?Window} win The window of the activity frame.
 * @param {number} timeoutMs
 * @returns {Promise<{ok: boolean, reason: string}>} ok is false when the data may not have been saved;
 *      the reason is then what to put in the log ("timeout" when the limit was reached).
 */
export const exitScorm = (win, timeoutMs = EXIT_TIMEOUT_MS) => new Promise((resolve) => {
    const timer = setTimeout(() => resolve({ok: false, reason: 'timeout'}), timeoutMs);
    // Out of the click's own turn, so the timer is registered before a slow commit can block anything.
    setTimeout(() => {
        const result = commitScorm(win);
        clearTimeout(timer);
        resolve(result);
    }, 0);
});
