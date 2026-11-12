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
 * Unit tests for the flow call helpers. Run with `pnpm test`.
 *
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
import assert from 'node:assert/strict';
import {describe, it} from 'node:test';
import {createFlight, createSerialQueue} from '../../amd/src/flow_calls.js';

/**
 * A promise the test settles by hand.
 *
 * @returns {{promise: Promise, resolve: Function, reject: Function}}
 */
const deferred = () => {
    let resolve;
    let reject;
    const promise = new Promise((res, rej) => {
        resolve = res;
        reject = rej;
    });
    return {promise, resolve, reject};
};

describe('createFlight', () => {
    it('joins a request for the same key that is in flight', async() => {
        const calls = [];
        const gate = deferred();
        const flight = createFlight({fetch: (key) => {
            calls.push(key);
            return gate.promise;
        }});

        const first = flight.request('a');
        const second = flight.request('a');
        gate.resolve({answer: 1});

        assert.deepEqual(await first, {value: {answer: 1}});
        assert.equal(await second, await first);
        assert.deepEqual(calls, ['a']);
    });

    it('does not mix up different keys', async() => {
        const calls = [];
        const flight = createFlight({fetch: async(key) => {
            calls.push(key);
            return key;
        }});

        const results = await Promise.all([flight.request('a'), flight.request('b')]);

        assert.deepEqual(results, [{value: 'a'}, {value: 'b'}]);
        assert.deepEqual(calls, ['a', 'b']);
    });

    it('never reuses an answer once the call finished', async() => {
        let count = 0;
        const flight = createFlight({fetch: async() => ++count});

        assert.deepEqual(await flight.request('a'), {value: 1});
        assert.deepEqual(await flight.request('a'), {value: 2});
    });

    it('turns a failure into an error outcome, never a rejection, and recovers', async() => {
        const failure = new Error('boom');
        let fail = true;
        const flight = createFlight({fetch: async() => {
            if (fail) {
                throw failure;
            }
            return 'ok';
        }});

        assert.deepEqual(await flight.request('a'), {error: failure});
        fail = false;
        assert.deepEqual(await flight.request('a'), {value: 'ok'});
    });

    it('turns a synchronous throw into an error outcome', async() => {
        const failure = new Error('sync');
        const flight = createFlight({fetch: () => {
            throw failure;
        }});

        assert.deepEqual(await flight.request('a'), {error: failure});
    });
});

describe('createSerialQueue', () => {
    it('runs the tasks one at a time, in order', async() => {
        const log = [];
        const gates = [deferred(), deferred(), deferred()];
        const queue = createSerialQueue();

        const runs = gates.map((gate, index) => queue.enqueue(() => {
            log.push(`start ${index}`);
            return gate.promise.then((value) => {
                log.push(`end ${index}`);
                return value;
            });
        }));
        // The last one finishes first, but has to wait for its turn.
        gates[2].resolve('c');
        gates[1].resolve('b');
        gates[0].resolve('a');

        assert.deepEqual(await Promise.all(runs), [{value: 'a'}, {value: 'b'}, {value: 'c'}]);
        assert.deepEqual(log, ['start 0', 'end 0', 'start 1', 'end 1', 'start 2', 'end 2']);
    });

    it('goes on after a task failed', async() => {
        const failure = new Error('boom');
        const queue = createSerialQueue();

        const first = queue.enqueue(() => Promise.reject(failure));
        const second = queue.enqueue(async() => 'fine');

        assert.deepEqual(await first, {error: failure});
        assert.deepEqual(await second, {value: 'fine'});
    });
});
