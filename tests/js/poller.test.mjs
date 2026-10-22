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

// Tests for the interval poller (amd/src/poller.js), with a fake clock.

import {test} from 'node:test';
import assert from 'node:assert/strict';
import {createPoller} from '../../amd/src/poller.js';

/**
 * A manual clock: timers run only when advance() reaches them.
 */
const fakeClock = () => {
    let time = 0;
    let nextId = 1;
    const timers = new Map();
    return {
        setTimer: (callback, ms) => {
            const id = nextId++;
            timers.set(id, {callback, at: time + ms});
            return id;
        },
        clearTimer: (id) => timers.delete(id),
        pending: () => timers.size,
        advance: (ms) => {
            const end = time + ms;
            for (;;) {
                const due = [...timers.entries()].filter(([, timer]) => timer.at <= end).sort((a, b) => a[1].at - b[1].at)[0];
                if (!due) {
                    break;
                }
                timers.delete(due[0]);
                time = due[1].at;
                due[1].callback();
            }
            time = end;
        },
    };
};

test('ticks once per interval, and not before the first one', () => {
    const clock = fakeClock();
    let ticks = 0;
    const poller = createPoller({tick: () => ticks++, intervalMs: 15000, isVisible: () => true, ...clock});
    poller.start();
    clock.advance(14999);
    assert.equal(ticks, 0);
    clock.advance(1);
    assert.equal(ticks, 1);
    clock.advance(30000);
    assert.equal(ticks, 3);
});

test('a hidden document skips the tick and polling resumes when it is visible again', () => {
    const clock = fakeClock();
    let ticks = 0;
    let visible = false;
    const poller = createPoller({tick: () => ticks++, intervalMs: 1000, isVisible: () => visible, ...clock});
    poller.start();
    clock.advance(5000);
    assert.equal(ticks, 0);
    visible = true;
    clock.advance(1000);
    assert.equal(ticks, 1);
});

test('stop leaves no timer and no later tick', () => {
    const clock = fakeClock();
    let ticks = 0;
    const poller = createPoller({tick: () => ticks++, intervalMs: 1000, isVisible: () => true, ...clock});
    poller.start();
    clock.advance(1000);
    poller.stop();
    assert.equal(clock.pending(), 0);
    assert.equal(poller.isRunning(), false);
    clock.advance(10000);
    assert.equal(ticks, 1);
});

test('start twice does not double the rate, and it can start again after a stop', () => {
    const clock = fakeClock();
    let ticks = 0;
    const poller = createPoller({tick: () => ticks++, intervalMs: 1000, isVisible: () => true, ...clock});
    poller.start();
    poller.start();
    clock.advance(3000);
    assert.equal(ticks, 3);
    poller.stop();
    poller.start();
    clock.advance(2000);
    assert.equal(ticks, 5);
});

test('a tick that stops the poller ends it', () => {
    const clock = fakeClock();
    let ticks = 0;
    const poller = createPoller({
        tick: () => {
            ticks++;
            poller.stop();
        },
        intervalMs: 1000,
        isVisible: () => true,
        ...clock,
    });
    poller.start();
    clock.advance(1000);
    assert.equal(ticks, 1);
    assert.equal(clock.pending(), 0, 'no timer is left behind by the tick that stopped it');
    clock.advance(5000);
    assert.equal(ticks, 1);
});
