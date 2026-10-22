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
 * Unit tests for the state refresher. Run with `pnpm test`.
 *
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
import assert from 'node:assert/strict';
import {describe, it} from 'node:test';
import {createRefresher} from '../../amd/src/refresher.js';

const DEBOUNCE = 400;
const INTERVAL = 2000;

/**
 * A refresher on a clock the test moves by hand, over a fetch the test settles by hand.
 *
 * @returns {Object}
 */
const setup = () => {
    let time = 10000;
    let nextId = 1;
    const timers = new Map();
    const fetches = [];
    const states = [];
    const errors = [];
    const refresher = createRefresher({
        fetch: () => new Promise((resolve, reject) => {
            fetches.push({resolve, reject});
        }),
        onState: (state) => states.push(state),
        onError: (error) => errors.push(error),
        debounceMs: DEBOUNCE,
        minIntervalMs: INTERVAL,
        now: () => time,
        setTimer: (callback, ms) => {
            timers.set(nextId, {callback, at: time + ms});
            return nextId++;
        },
        clearTimer: (id) => timers.delete(id),
    });
    return {
        refresher,
        fetches,
        states,
        errors,
        timers,
        /** Moves the clock and fires the timers that fall due, in order. */
        advance: (ms) => {
            const end = time + ms;
            for (;;) {
                const due = [...timers.entries()].filter(([, timer]) => timer.at <= end).sort((a, b) => a[1].at - b[1].at)[0];
                if (!due) {
                    break;
                }
                time = Math.max(time, due[1].at);
                timers.delete(due[0]);
                due[1].callback();
            }
            time = end;
        },
        /** Lets promise callbacks run. */
        settle: () => new Promise((resolve) => setImmediate(resolve)),
    };
};

describe('createRefresher', () => {
    it('never runs two fetches at once: load joins the one in flight', async() => {
        const t = setup();
        const first = t.refresher.load();
        const second = t.refresher.load();
        await t.settle();
        assert.equal(t.fetches.length, 1);
        t.fetches[0].resolve({n: 1});
        assert.deepEqual(await first, {n: 1});
        assert.deepEqual(await second, {n: 1});
        assert.deepEqual(t.states, [{n: 1}]);
    });

    it('turns a burst of requests into one fetch after the debounce', async() => {
        const t = setup();
        t.refresher.schedule();
        t.advance(100);
        t.refresher.schedule();
        t.advance(100);
        t.refresher.schedule();
        t.advance(DEBOUNCE - 1);
        await t.settle();
        assert.equal(t.fetches.length, 0, 'still waiting for the burst to end');
        t.advance(1);
        await t.settle();
        assert.equal(t.fetches.length, 1);
    });

    it('spaces fetches by the minimum interval, and still fetches for the last request', async() => {
        const t = setup();
        t.refresher.schedule();
        t.advance(DEBOUNCE);
        await t.settle();
        t.fetches[0].resolve({n: 1});
        await t.settle();

        // A request right after the first fetch waits until the interval has passed (not forgotten).
        t.advance(300);
        t.refresher.schedule();
        t.advance(DEBOUNCE + 100);
        await t.settle();
        assert.equal(t.fetches.length, 1, 'inside the interval');
        t.advance(INTERVAL);
        await t.settle();
        assert.equal(t.fetches.length, 2, 'the trailing request is served');
    });

    it('runs one more fetch after the one in flight when a request arrives meanwhile', async() => {
        const t = setup();
        t.refresher.schedule();
        t.advance(DEBOUNCE);
        await t.settle();
        assert.equal(t.fetches.length, 1);

        t.refresher.schedule();
        t.refresher.schedule();
        t.advance(DEBOUNCE + INTERVAL);
        await t.settle();
        assert.equal(t.fetches.length, 1, 'no second request while one is in flight');

        t.fetches[0].resolve({n: 1});
        await t.settle();
        t.advance(INTERVAL + DEBOUNCE);
        await t.settle();
        assert.equal(t.fetches.length, 2, 'exactly one follow-up');
        t.fetches[1].resolve({n: 2});
        await t.settle();
        t.advance(10 * INTERVAL);
        await t.settle();
        assert.equal(t.fetches.length, 2);
        assert.deepEqual(t.states, [{n: 1}, {n: 2}]);
    });

    it('swallows errors of a scheduled fetch, reports them and carries on', async() => {
        const t = setup();
        t.refresher.schedule();
        t.advance(DEBOUNCE);
        await t.settle();
        t.fetches[0].reject(new Error('offline'));
        await t.settle();
        assert.equal(t.errors.length, 1);
        assert.equal(t.states.length, 0);

        t.refresher.schedule();
        t.advance(INTERVAL + DEBOUNCE);
        await t.settle();
        assert.equal(t.fetches.length, 2, 'a failure does not block the next fetch');
    });

    it('flush fetches at once, ignoring the spacing', async() => {
        const t = setup();
        t.refresher.schedule();
        t.advance(DEBOUNCE);
        await t.settle();
        t.fetches[0].resolve({n: 1});
        await t.settle();

        const flushed = t.refresher.flush();
        await t.settle();
        assert.equal(t.fetches.length, 2);
        t.fetches[1].resolve({n: 2});
        assert.deepEqual(await flushed, {n: 2});
    });

    it('flush waits for the fetch in flight and then fetches again, because that one may be older', async() => {
        const t = setup();
        t.refresher.schedule();
        t.advance(DEBOUNCE);
        await t.settle();
        const flushed = t.refresher.flush();
        await t.settle();
        assert.equal(t.fetches.length, 1);

        t.fetches[0].resolve({n: 1});
        await t.settle();
        assert.equal(t.fetches.length, 2);
        t.fetches[1].resolve({n: 2});
        assert.deepEqual(await flushed, {n: 2});
        assert.deepEqual(t.states, [{n: 1}, {n: 2}]);
    });

    it('flush cancels what was scheduled, so there is no extra fetch afterwards', async() => {
        const t = setup();
        t.refresher.schedule();
        const flushed = t.refresher.flush();
        await t.settle();
        t.fetches[0].resolve({n: 1});
        await flushed;
        t.advance(10 * INTERVAL);
        await t.settle();
        assert.equal(t.fetches.length, 1);
    });

    it('cancel drops the scheduled fetch and the follow-up', async() => {
        const t = setup();
        t.refresher.schedule();
        t.refresher.cancel();
        t.advance(10 * INTERVAL);
        await t.settle();
        assert.equal(t.fetches.length, 0);

        t.refresher.schedule();
        t.advance(DEBOUNCE);
        await t.settle();
        t.refresher.schedule();
        t.refresher.cancel();
        t.fetches[0].resolve({n: 1});
        await t.settle();
        t.advance(10 * INTERVAL);
        await t.settle();
        assert.equal(t.fetches.length, 1);
    });
});
