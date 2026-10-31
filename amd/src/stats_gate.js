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
 * Answers the app's statistics requests without letting it hammer the server.
 *
 * A request for a group joins the one already in flight for that group, and a request that comes soon
 * after an answer gets that answer again instead of a new call. An answer is kept only for that short
 * window, in memory, and then dropped: the counts are not stored anywhere.
 *
 * Nothing here touches the DOM or Moodle APIs, and the clock is injectable, so it can be unit
 * tested with plain Node (see tests/js).
 *
 * @module     format_rumbos/stats_gate
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** The most groups remembered at once, so a stream of made-up group ids cannot grow the memory without bound. */
const MAX_ENTRIES = 20;

/**
 * Which of the contract's two errors a failed call is.
 *
 * @param {*} error What the call rejected with (a Moodle exception object).
 * @returns {string} "capability" when Moodle says the user may not see statistics, otherwise "unavailable".
 */
export const statsErrorKind = (error) => {
    const names = [error?.errorcode, error?.exception].filter((name) => typeof name === 'string');
    const denied = names.some((name) => name === 'nopermissions' || name.includes('required_capability'));
    return denied ? 'capability' : 'unavailable';
};

/**
 * @param {Object} params
 * @param {Function} params.fetch Called with a group id; returns a promise of the CourseStats.
 * @param {number} params.minIntervalMs An answer is reused for this long.
 * @param {Function} [params.now] Clock in ms.
 * @param {Function} [params.setTimer] setTimeout.
 * @returns {{request: Function}}
 */
export const createStatsGate = ({
    fetch,
    minIntervalMs,
    now = Date.now,
    setTimer = (callback, ms) => window.setTimeout(callback, ms),
}) => {
    const entries = new Map();

    /**
     * Drops the answers that are old enough to be asked for again, and the oldest entries over the limit.
     * The timer set with each answer does the same; this keeps the limit even if timers are late.
     */
    const prune = () => {
        for (const [groupId, entry] of entries) {
            if (!entry.pending && now() - entry.at >= minIntervalMs) {
                entries.delete(groupId);
            }
        }
        while (entries.size >= MAX_ENTRIES) {
            entries.delete(entries.keys().next().value);
        }
    };

    /**
     * Asks for the statistics of a group.
     *
     * @param {number} groupId 0 for everyone the user may see.
     * @returns {Promise<{stats: Object}|{error: string}>} Never rejects.
     */
    const request = (groupId) => {
        prune();
        const existing = entries.get(groupId);
        if (existing) {
            return existing.reply;
        }
        const entry = {at: now(), pending: true};
        entry.reply = Promise.resolve().then(() => fetch(groupId))
            .then((stats) => ({stats}), (error) => ({error: statsErrorKind(error)}))
            .then((reply) => {
                entry.at = now();
                entry.pending = false;
                // Forget the answer once the window is over, even if nobody asks again.
                setTimer(() => {
                    if (entries.get(groupId) === entry) {
                        entries.delete(groupId);
                    }
                }, minIntervalMs);
                return reply;
            });
        entries.set(groupId, entry);
        return entry.reply;
    };

    return {request};
};
