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
 * Unit tests for the welcome-flow messages of the protocol (contract v5.8). Run with `pnpm test`.
 *
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
import assert from 'node:assert/strict';
import {describe, it} from 'node:test';
import {
    MAX_REVISION,
    MESSAGE,
    hostFlowProgress,
    hostFlowProgressError,
    hostQuizResult,
    hostQuizResultError,
    parseAppMessage,
} from '../../amd/src/protocol.js';

const APP = 'https://rumbos.example.com';
const frameWindow = {name: 'iframe-window'};
const expected = {appOrigin: APP, frameWindow};
const fromApp = (data, overrides = {}) => ({origin: APP, source: frameWindow, data, ...overrides});
const parse = (data) => parseAppMessage(fromApp({v: 1, ...data}), expected);

const MAP = '3f0c2a52-6c1e-4f6e-9a8e-0d6b1f9a7c11';

describe('rumbos:request-flow-progress and rumbos:flow-reset', () => {
    for (const type of [MESSAGE.REQUEST_FLOW_PROGRESS, MESSAGE.FLOW_RESET]) {
        it(`${type} accepts a map id and drops anything else`, () => {
            assert.deepEqual(parse({type, mapId: MAP, extra: 'x'}), {type, mapId: MAP});
            assert.deepEqual(parse({type, mapId: MAP.toUpperCase()}), {type, mapId: MAP.toUpperCase()});
        });

        it(`${type} rejects a map id that is not a UUID`, () => {
            for (const mapId of [undefined, null, '', 'abc', 7, {}, `${MAP}\n`, ` ${MAP}`, `${MAP}0`, `${MAP.slice(1)}`]) {
                assert.equal(parse({type, mapId}), null, String(mapId));
            }
        });
    }
});

describe('rumbos:flow-step-done', () => {
    const valid = {type: MESSAGE.FLOW_STEP_DONE, mapId: MAP, stepKey: 'video_1', revision: 3, method: 'completed'};

    it('accepts a well formed message and keeps only its fields', () => {
        assert.deepEqual(parse({...valid, extra: 'x'}), valid);
        for (const method of ['completed', 'fallback', 'unavailable']) {
            assert.equal(parse({...valid, method}).method, method);
        }
        assert.equal(parse({...valid, revision: 0}).revision, 0);
        assert.equal(parse({...valid, revision: MAX_REVISION}).revision, MAX_REVISION);
        assert.equal(parse({...valid, stepKey: 'a'.repeat(16)}).stepKey, 'a'.repeat(16));
    });

    it('rejects every field that is not well formed', () => {
        const bad = {
            mapId: [undefined, 'x', 5],
            stepKey: [undefined, '', 'a'.repeat(17), 'a b', 'a/b', '<b>', 'a\n', 7, null],
            revision: [undefined, -1, MAX_REVISION + 1, 1.5, '1', NaN, Infinity, null],
            method: [undefined, '', 'done', 'COMPLETED', 'completed ', 1, null],
        };
        for (const [field, values] of Object.entries(bad)) {
            for (const value of values) {
                assert.equal(parse({...valid, [field]: value}), null, `${field}: ${String(value)}`);
            }
        }
    });
});

describe('rumbos:request-quiz-result', () => {
    it('accepts a positive integer cmid', () => {
        assert.deepEqual(parse({type: MESSAGE.REQUEST_QUIZ_RESULT, cmid: 12, x: 1}), {type: MESSAGE.REQUEST_QUIZ_RESULT, cmid: 12});
    });

    it('rejects anything else', () => {
        for (const cmid of [undefined, 0, -3, 1.5, '12', null, NaN]) {
            assert.equal(parse({type: MESSAGE.REQUEST_QUIZ_RESULT, cmid}), null, String(cmid));
        }
    });

    it('still needs the origin, the source and the version', () => {
        const data = {type: MESSAGE.REQUEST_QUIZ_RESULT, v: 1, cmid: 12};
        assert.equal(parseAppMessage(fromApp(data, {origin: 'https://evil.example'}), expected), null);
        assert.equal(parseAppMessage(fromApp(data, {source: {}}), expected), null);
        assert.equal(parseAppMessage(fromApp({...data, v: 2}), expected), null);
    });
});

describe('hostFlowProgress', () => {
    const reply = {
        error: '',
        outcome: 'read',
        persistent: true,
        config: {quizThreshold: 80},
        items: [{stepKey: 'video', revision: 1, method: 'completed', completedAt: '2026-11-11T10:00:00Z', userid: 7}],
        secret: 'x',
    };

    it('builds the message of the contract and nothing more', () => {
        assert.deepEqual(hostFlowProgress(MAP, reply), {
            type: 'rumbos:host-flow-progress',
            v: 1,
            mapId: MAP,
            items: [{stepKey: 'video', revision: 1, method: 'completed', completedAt: '2026-11-11T10:00:00Z'}],
            persistent: true,
            config: {quizThreshold: 80},
        });
    });

    it('gives an empty list and persistent false as they are', () => {
        const message = hostFlowProgress(MAP, {...reply, items: [], persistent: false, config: {quizThreshold: 0}});
        assert.deepEqual([message.items, message.persistent, message.config], [[], false, {quizThreshold: 0}]);
    });

    it('returns null for a reply that is not well formed', () => {
        const broken = [
            null, {}, {...reply, items: 'x'}, {...reply, persistent: 'yes'}, {...reply, config: {}},
            {...reply, config: {quizThreshold: 101}}, {...reply, config: {quizThreshold: 80.5}},
            {...reply, items: [{...reply.items[0], method: 'done'}]},
            {...reply, items: [{...reply.items[0], stepKey: 'a b'}]},
            {...reply, items: [{...reply.items[0], completedAt: 5}]},
            {...reply, items: [null]},
        ];
        for (const item of broken) {
            assert.equal(hostFlowProgress(MAP, item), null);
        }
    });

    it('builds the error form with only an allowed error', () => {
        assert.deepEqual(hostFlowProgressError(MAP, 'readonly'), {type: 'rumbos:host-flow-progress', v: 1, mapId: MAP, error: 'readonly'});
        assert.equal(hostFlowProgressError(MAP, 'unavailable').error, 'unavailable');
        assert.equal(hostFlowProgressError(MAP, 'whatever').error, 'unavailable');
        assert.deepEqual(Object.keys(hostFlowProgressError(MAP, 'readonly')), ['type', 'v', 'mapId', 'error']);
    });
});

describe('hostQuizResult', () => {
    const result = {
        attempts: 2, finishedAttempts: 1, inProgress: true, percent: 87.5, pendingGrading: false, passedThreshold: true,
        userid: 9,
    };

    it('builds the message of the contract and nothing more', () => {
        assert.deepEqual(hostQuizResult(5, result), {
            type: 'rumbos:host-quiz-result',
            v: 1,
            cmid: 5,
            result: {
                attempts: 2, finishedAttempts: 1, inProgress: true, percent: 87.5, pendingGrading: false, passedThreshold: true,
            },
        });
    });

    it('keeps the nulls', () => {
        const message = hostQuizResult(5, {...result, percent: null, passedThreshold: null});
        assert.deepEqual([message.result.percent, message.result.passedThreshold], [null, null]);
    });

    it('returns null for a result that is not well formed', () => {
        const broken = [
            null, {}, {...result, attempts: -1}, {...result, attempts: 1.5}, {...result, finishedAttempts: '1'},
            {...result, inProgress: 1}, {...result, percent: '80'}, {...result, percent: NaN}, {...result, percent: undefined},
            {...result, pendingGrading: null}, {...result, passedThreshold: 'yes'}, {...result, passedThreshold: undefined},
        ];
        for (const item of broken) {
            assert.equal(hostQuizResult(5, item), null);
        }
    });

    it('builds the error form with only an allowed error', () => {
        for (const error of ['not_found', 'unavailable', 'capability']) {
            assert.deepEqual(hostQuizResultError(5, error), {type: 'rumbos:host-quiz-result', v: 1, cmid: 5, error});
        }
        assert.equal(hostQuizResultError(5, 'nope').error, 'unavailable');
        assert.deepEqual(Object.keys(hostQuizResultError(5, 'capability')), ['type', 'v', 'cmid', 'error']);
    });
});
