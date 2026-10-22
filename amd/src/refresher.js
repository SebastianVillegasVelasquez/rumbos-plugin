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
 * Runs the course state fetch one request at a time, and schedules it without hammering the server.
 *
 * Nothing here touches the DOM or Moodle APIs, and the clock is injectable, so it can be unit
 * tested with plain Node (see tests/js).
 *
 * @module     format_rumbos/refresher
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * @param {Object} params
 * @param {Function} params.fetch Starts a fetch and returns a promise of the state.
 * @param {Function} params.onState Called with the state of every fetch that finished, whoever asked for it.
 * @param {Function} params.onError Called with the error of a scheduled fetch that failed (the last state is kept).
 * @param {number} params.debounceMs A burst of requests waits this long after its last request.
 * @param {number} params.minIntervalMs Two fetches the scheduler starts are at least this far apart.
 * @param {Function} [params.now] Clock in ms.
 * @param {Function} [params.setTimer] setTimeout.
 * @param {Function} [params.clearTimer] clearTimeout.
 * @returns {{load: Function, schedule: Function, flush: Function, cancel: Function}}
 */
export const createRefresher = ({
    fetch,
    onState,
    onError,
    debounceMs,
    minIntervalMs,
    now = Date.now,
    setTimer = (callback, ms) => window.setTimeout(callback, ms),
    clearTimer = (id) => window.clearTimeout(id),
}) => {
    let inFlight = null;
    let timer = null;
    let rerun = false;
    let lastStart = -Infinity;

    /**
     * Starts a fetch, or joins the one in flight: there is never more than one request at a time.
     *
     * @returns {Promise<Object>}
     */
    const load = () => {
        if (inFlight) {
            return inFlight;
        }
        lastStart = now();
        inFlight = Promise.resolve().then(fetch)
            .then((state) => {
                onState(state);
                return state;
            })
            .finally(() => {
                inFlight = null;
                if (rerun) {
                    rerun = false;
                    schedule();
                }
            });
        return inFlight;
    };

    const cancelTimer = () => {
        if (timer !== null) {
            clearTimer(timer);
            timer = null;
        }
    };

    /**
     * Asks for a fetch soon: a burst of calls becomes one fetch, and fetches are spaced.
     */
    const schedule = () => {
        if (inFlight) {
            // Whatever changed may have happened after that fetch began: go again once it ends.
            rerun = true;
            return;
        }
        cancelTimer();
        const at = Math.max(now() + debounceMs, lastStart + minIntervalMs);
        timer = setTimer(() => {
            timer = null;
            load().catch(onError);
        }, Math.max(0, at - now()));
    };

    /**
     * Fetches as soon as nothing is in flight, ignoring the spacing. Use it when the answer is needed now.
     *
     * @returns {Promise<Object>} The state of a fetch that began after this call.
     */
    const flush = () => {
        cancelTimer();
        rerun = false;
        if (!inFlight) {
            return load();
        }
        // The fetch in flight began before this call, so it may predate the change that is being waited for.
        return inFlight.catch(() => null).then(() => load());
    };

    /**
     * Forgets what is scheduled. A fetch already in flight finishes and is reported as usual.
     */
    const cancel = () => {
        cancelTimer();
        rerun = false;
    };

    return {load, schedule, flush, cancel};
};
