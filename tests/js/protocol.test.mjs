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
 * Unit tests for the host <-> app protocol helpers. Run with `pnpm test`.
 *
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
import assert from 'node:assert/strict';
import {describe, it} from 'node:test';
import {
    MESSAGE,
    findActivityUrl,
    hostInit,
    hostLaunch,
    hostState,
    parseAppMessage,
} from '../../amd/src/protocol.js';

const APP = 'https://rumbos.example.com';
const SITE = 'https://lms.example.com';
const frameWindow = {name: 'iframe-window'};
const expected = {appOrigin: APP, frameWindow};
const fromApp = (data, overrides = {}) => ({origin: APP, source: frameWindow, data, ...overrides});

describe('parseAppMessage', () => {
    it('accepts the argument-less messages', () => {
        for (const type of [MESSAGE.EMBED_READY, MESSAGE.REQUEST_STATE, MESSAGE.REQUEST_LAUNCH]) {
            assert.deepEqual(parseAppMessage(fromApp({type, v: 1}), expected), {type});
        }
    });

    it('accepts open-activity with a positive integer cmid', () => {
        assert.deepEqual(
            parseAppMessage(fromApp({type: MESSAGE.OPEN_ACTIVITY, v: 1, cmid: 42}), expected),
            {type: MESSAGE.OPEN_ACTIVITY, cmid: 42},
        );
    });

    it('accepts request-fullscreen with a boolean', () => {
        assert.deepEqual(
            parseAppMessage(fromApp({type: MESSAGE.REQUEST_FULLSCREEN, v: 1, on: true}), expected),
            {type: MESSAGE.REQUEST_FULLSCREEN, on: true},
        );
    });

    it('rejects a different origin even if the source matches', () => {
        const event = fromApp({type: MESSAGE.EMBED_READY, v: 1}, {origin: 'https://evil.example.com'});
        assert.equal(parseAppMessage(event, expected), null);
    });

    it('rejects the right origin from another window', () => {
        const event = fromApp({type: MESSAGE.EMBED_READY, v: 1}, {source: {}});
        assert.equal(parseAppMessage(event, expected), null);
    });

    it('rejects everything when the iframe window is not available', () => {
        const event = fromApp({type: MESSAGE.EMBED_READY, v: 1}, {source: null});
        assert.equal(parseAppMessage(event, {appOrigin: APP, frameWindow: null}), null);
    });

    it('rejects a null origin (opaque-origin sandboxed frame)', () => {
        assert.equal(parseAppMessage(fromApp({type: MESSAGE.EMBED_READY, v: 1}, {origin: 'null'}), expected), null);
    });

    it('ignores malformed payloads', () => {
        const bad = [
            null, undefined, 'rumbos:embed-ready', 42, [], [MESSAGE.EMBED_READY],
            {}, {type: MESSAGE.EMBED_READY}, {type: MESSAGE.EMBED_READY, v: 2}, {type: MESSAGE.EMBED_READY, v: '1'},
            {type: 7, v: 1}, {type: 'rumbos:unknown', v: 1}, {type: 'rumbos:host-init', v: 1},
        ];
        for (const data of bad) {
            assert.equal(parseAppMessage(fromApp(data), expected), null, JSON.stringify(data));
        }
    });

    it('rejects open-activity with a bad cmid', () => {
        for (const cmid of [undefined, null, '42', 0, -1, 1.5, NaN, Infinity, 2 ** 60, {}, [42]]) {
            assert.equal(
                parseAppMessage(fromApp({type: MESSAGE.OPEN_ACTIVITY, v: 1, cmid}), expected),
                null,
                String(cmid),
            );
        }
    });

    it('rejects open-activity that tries to supply a URL', () => {
        const parsed = parseAppMessage(
            fromApp({type: MESSAGE.OPEN_ACTIVITY, v: 1, cmid: 5, url: 'https://evil.example.com'}),
            expected,
        );
        assert.deepEqual(parsed, {type: MESSAGE.OPEN_ACTIVITY, cmid: 5});
        assert.equal('url' in parsed, false);
    });

    it('rejects request-fullscreen with a non-boolean', () => {
        for (const on of [undefined, 1, 'true', null]) {
            assert.equal(parseAppMessage(fromApp({type: MESSAGE.REQUEST_FULLSCREEN, v: 1, on}), expected), null);
        }
    });
});

describe('message builders', () => {
    it('always include the protocol version', () => {
        assert.equal(hostInit({}).v, 1);
        assert.equal(hostState({}).v, 1);
        assert.equal(hostLaunch('x').v, 1);
    });

    it('build host-init with every field of the contract', () => {
        const state = {schemaVersion: 1};
        assert.deepEqual(
            hostInit({mode: 'editor', locale: 'es', courseId: 2, assertion: 'jws', state}),
            {type: 'rumbos:host-init', v: 1, mode: 'editor', locale: 'es', courseId: 2, assertion: 'jws', state},
        );
    });

    it('build host-state and host-launch', () => {
        assert.deepEqual(hostState({a: 1}), {type: 'rumbos:host-state', v: 1, state: {a: 1}});
        assert.deepEqual(hostLaunch('jws'), {type: 'rumbos:host-launch', v: 1, assertion: 'jws'});
    });
});

describe('findActivityUrl', () => {
    const state = {
        activities: [
            {cmid: 1, availability: 'available', url: `${SITE}/mod/page/view.php?id=1`},
            {cmid: 2, availability: 'restricted', url: null},
            {cmid: 3, availability: 'restricted', url: `${SITE}/mod/page/view.php?id=3`},
            {cmid: 4, availability: 'hidden', url: `${SITE}/mod/page/view.php?id=4`},
            {cmid: 5, availability: 'available', url: 'https://evil.example.com/steal'},
            {cmid: 6, availability: 'available', url: 'javascript:alert(1)'},
            {cmid: 7, availability: 'available', url: '/mod/page/view.php?id=7'},
        ],
    };

    it('returns the URL of an available activity from the host state', () => {
        assert.equal(findActivityUrl(state, 1, SITE), `${SITE}/mod/page/view.php?id=1`);
    });

    it('returns the URL of a hidden activity (editors only receive them)', () => {
        assert.equal(findActivityUrl(state, 4, SITE), `${SITE}/mod/page/view.php?id=4`);
    });

    it('returns null for cmids that are not in the state', () => {
        assert.equal(findActivityUrl(state, 99, SITE), null);
    });

    it('returns null for restricted activities, with or without URL', () => {
        assert.equal(findActivityUrl(state, 2, SITE), null);
        assert.equal(findActivityUrl(state, 3, SITE), null);
    });

    it('returns null for URLs outside the Moodle site or with another scheme', () => {
        assert.equal(findActivityUrl(state, 5, SITE), null);
        assert.equal(findActivityUrl(state, 6, SITE), null);
    });

    it('resolves a relative URL against the site', () => {
        assert.equal(findActivityUrl(state, 7, SITE), `${SITE}/mod/page/view.php?id=7`);
    });

    it('returns null when there is no state yet', () => {
        assert.equal(findActivityUrl(null, 1, SITE), null);
        assert.equal(findActivityUrl({}, 1, SITE), null);
    });
});
