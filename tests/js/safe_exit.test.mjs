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
 * Unit tests for the SCORM exit. Run with `pnpm test`.
 *
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
import assert from 'node:assert/strict';
import {describe, it} from 'node:test';
import {commitScorm, exitScorm} from '../../amd/src/safe_exit.js';

/**
 * A SCORM 1.2 adapter that records its calls.
 *
 * @param {string} result What LMSCommit answers.
 * @param {string} error What LMSGetLastError answers.
 * @returns {{calls: string[], LMSCommit: Function, LMSGetLastError: Function}}
 */
const api12 = (result = 'true', error = '0') => {
    const calls = [];
    return {
        calls,
        LMSCommit: (param) => {
            calls.push(`LMSCommit(${param})`);
            return result;
        },
        LMSGetLastError: () => error,
        LMSFinish: () => {
            calls.push('LMSFinish');
            return 'true';
        },
    };
};

describe('commitScorm', () => {
    it('commits a SCORM 1.2 adapter and never finishes it', () => {
        const API = api12();
        assert.deepEqual(commitScorm({API}), {ok: true, reason: 'committed'});
        assert.deepEqual(API.calls, ['LMSCommit()']);
    });

    it('commits a SCORM 2004 adapter', () => {
        const calls = [];
        const API_1484_11 = {Commit: (param) => (calls.push(`Commit(${param})`), 'true'), GetLastError: () => '0'};
        assert.deepEqual(commitScorm({API_1484_11}), {ok: true, reason: 'committed'});
        assert.deepEqual(calls, ['Commit()']);
    });

    it('is fine with a frame that is not on a player page', () => {
        assert.deepEqual(commitScorm({}), {ok: true, reason: 'no-api'});
        assert.deepEqual(commitScorm(null), {ok: true, reason: 'no-api'});
    });

    it('is fine when the package never started or already finished (error 301)', () => {
        assert.deepEqual(commitScorm({API: api12('false', '301')}), {ok: true, reason: 'not-initialized'});
    });

    it('reports a refused commit as a possible loss', () => {
        assert.deepEqual(commitScorm({API: api12('false', '101')}), {ok: false, reason: 'refused'});
    });

    it('reports an adapter that throws as a possible loss', () => {
        const API = {
            LMSCommit: () => {
                throw new Error('boom');
            },
        };
        assert.deepEqual(commitScorm({API}), {ok: false, reason: 'error'});
    });

    it('treats a window it cannot read as nothing to commit', () => {
        const win = {
            get API() {
                throw new DOMException('cross-origin');
            },
        };
        assert.deepEqual(commitScorm(win), {ok: true, reason: 'unreadable'});
    });
});

describe('exitScorm', () => {
    it('resolves after the commit', async() => {
        const API = api12();
        assert.deepEqual(await exitScorm({API}, 1000), {ok: true, reason: 'committed'});
        assert.deepEqual(API.calls, ['LMSCommit()']);
    });

    it('resolves, not rejects, when the commit throws', async() => {
        const API = {
            LMSCommit: () => {
                throw new Error('boom');
            },
        };
        assert.deepEqual(await exitScorm({API}, 1000), {ok: false, reason: 'error'});
    });
});
