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
 * Calls a function at a fixed interval while the document is visible, until it is stopped.
 *
 * Nothing here touches Moodle APIs, and the clock and the visibility are injectable, so it can be
 * unit tested with plain Node (see tests/js).
 *
 * @module     format_rumbos/poller
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * @param {Object} params
 * @param {Function} params.tick Called on every interval at which the document is visible.
 * @param {number} params.intervalMs Time between two ticks.
 * @param {Function} [params.isVisible] Whether the document is visible.
 * @param {Function} [params.setTimer] setTimeout.
 * @param {Function} [params.clearTimer] clearTimeout.
 * @returns {{start: Function, stop: Function, isRunning: Function}}
 */
export const createPoller = ({
    tick,
    intervalMs,
    isVisible = () => document.visibilityState === 'visible',
    setTimer = (callback, ms) => window.setTimeout(callback, ms),
    clearTimer = (id) => window.clearTimeout(id),
}) => {
    let timer = null;
    let running = false;

    const arm = () => {
        timer = setTimer(() => {
            timer = null;
            if (!running) {
                return;
            }
            // A hidden tab skips the tick but keeps the schedule, so polling resumes when it is shown again.
            if (isVisible()) {
                tick();
            }
            if (running) {
                arm();
            }
        }, intervalMs);
    };

    /**
     * Starts counting from now. The first tick comes after one interval, not at once.
     */
    const start = () => {
        if (running) {
            return;
        }
        running = true;
        arm();
    };

    /**
     * Stops for good: no tick happens after this returns.
     */
    const stop = () => {
        running = false;
        if (timer !== null) {
            clearTimer(timer);
            timer = null;
        }
    };

    return {start, stop, isRunning: () => running};
};
