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
 * Unit tests for the statistics gate. Run with `pnpm test`.
 *
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
import assert from 'node:assert/strict';
import {describe, it} from 'node:test';
import {createStatsGate, statsErrorKind} from '../../amd/src/stats_gate.js';

const INTERVAL = 2000;

/**
 * A gate on a clock and timers the test moves by hand, over a fetch that records its calls.
 *
 * @param {Function} [fetch] Replaces the default fetch, which answers with the group id.
 * @returns {Object}
 */
const setup = (fetch) => {
    const state = {time: 0, calls: [], timers: []};
    const gate = createStatsGate({
        fetch: fetch ?? ((groupId) => {
            state.calls.push(groupId);
            return Promise.resolve({groupId});
        }),
        minIntervalMs: INTERVAL,
        now: () => state.time,
        setTimer: (callback, ms) => state.timers.push({callback, at: state.time + ms}),
    });
    state.gate = gate;
    state.advance = (ms) => {
        state.time += ms;
        state.timers.filter((timer) => timer.at <= state.time).forEach((timer) => timer.callback());
        state.timers = state.timers.filter((timer) => timer.at > state.time);
    };
    return state;
};

describe('createStatsGate', () => {
    it('asks the server once for requests that arrive together', async() => {
        const {gate, calls} = setup();
        const [first, second] = await Promise.all([gate.request(0), gate.request(0)]);
        assert.deepEqual(calls, [0]);
        assert.deepEqual(first, {stats: {groupId: 0}});
        assert.deepEqual(second, first);
    });

    it('gives the same answer again to a request inside the window', async() => {
        const state = setup();
        await state.gate.request(0);
        state.advance(INTERVAL - 1);
        await state.gate.request(0);
        assert.deepEqual(state.calls, [0]);
    });

    it('asks again once the window is over', async() => {
        const state = setup();
        await state.gate.request(0);
        state.advance(INTERVAL);
        await state.gate.request(0);
        assert.deepEqual(state.calls, [0, 0]);
    });

    it('keeps groups apart', async() => {
        const {gate, calls} = setup();
        await gate.request(0);
        await gate.request(4);
        assert.deepEqual(calls, [0, 4]);
    });

    it('forgets an answer when its window is over, even if nobody asks again', async() => {
        const state = setup();
        const reply = await state.gate.request(0);
        assert.deepEqual(reply, {stats: {groupId: 0}});
        state.advance(INTERVAL);
        await state.gate.request(0);
        assert.equal(state.calls.length, 2);
    });

    it('turns a failure into the contract error and does not reject', async() => {
        const {gate} = setup(() => Promise.reject({errorcode: 'nopermissions'}));
        assert.deepEqual(await gate.request(0), {error: 'capability'});
    });

    it('answers a thrown error too', async() => {
        const {gate} = setup(() => {
            throw new Error('network');
        });
        assert.deepEqual(await gate.request(0), {error: 'unavailable'});
    });

    it('remembers a bounded number of groups', async() => {
        const state = setup();
        for (let groupId = 1; groupId <= 60; groupId++) {
            await state.gate.request(groupId);
        }
        // The first group was dropped to make room, so it is asked for again inside the window.
        await state.gate.request(1);
        assert.equal(state.calls.filter((groupId) => groupId === 1).length, 2);
    });
});

describe('statsErrorKind', () => {
    it('knows a missing capability from the error code or the exception name', () => {
        assert.equal(statsErrorKind({errorcode: 'nopermissions'}), 'capability');
        assert.equal(statsErrorKind({exception: 'required_capability_exception'}), 'capability');
    });

    it('calls everything else unavailable', () => {
        for (const error of [{errorcode: 'invalidparameter'}, new Error('x'), null, undefined, 'boom', {}]) {
            assert.equal(statsErrorKind(error), 'unavailable');
        }
    });
});
