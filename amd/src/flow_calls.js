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
 * How the host makes the calls of the welcome flow: reads join the one in flight, writes go one at a time in order.
 *
 * A read is never reused after it finished: the app asks again exactly because something changed, and what it
 * gets must come from Moodle. Nothing is kept in memory once a call settles.
 *
 * Nothing here touches the DOM or Moodle APIs, so it can be unit tested with plain Node (see tests/js).
 *
 * @module     format_rumbos/flow_calls
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Reads that join the one already in flight for the same key.
 *
 * @param {Object} params
 * @param {Function} params.fetch Called with the key; returns a promise of the answer.
 * @returns {{request: Function}}
 */
export const createFlight = ({fetch}) => {
    const pending = new Map();

    /**
     * Asks for a key, joining the request in flight for it if there is one.
     *
     * @param {string|number} key
     * @returns {Promise<{value: *}|{error: *}>} Never rejects.
     */
    const request = (key) => {
        if (pending.has(key)) {
            return pending.get(key);
        }
        const call = Promise.resolve().then(() => fetch(key))
            .then((value) => ({value}), (error) => ({error}))
            .then((outcome) => {
                pending.delete(key);
                return outcome;
            });
        pending.set(key, call);
        return call;
    };

    return {request};
};

/**
 * A queue that runs one task at a time, in the order they were added, whatever happens to each.
 *
 * @returns {{enqueue: Function}}
 */
export const createSerialQueue = () => {
    let tail = Promise.resolve();

    /**
     * Adds a task: a function that returns a promise.
     *
     * @param {Function} task
     * @returns {Promise<{value: *}|{error: *}>} Never rejects.
     */
    const enqueue = (task) => {
        const run = tail.then(() => task()).then((value) => ({value}), (error) => ({error}));
        tail = run;
        return run;
    };

    return {enqueue};
};
