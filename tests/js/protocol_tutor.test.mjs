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

import assert from 'node:assert/strict';
import {describe, it} from 'node:test';
import {MESSAGE, hostInit, hostTutorClosed, parseAppMessage, resolveTutorUrl} from '../../amd/src/protocol.js';

const APP = 'https://rumbos.example.com';
const SITE = 'https://lms.example.com';
const frameWindow = {name: 'rumbos-frame'};
const expected = {appOrigin: APP, frameWindow};
const fromApp = (data, overrides = {}) => ({origin: APP, source: frameWindow, data, ...overrides});

describe('tutor.open', () => {
    it('is accepted from the Rumbos origin and the Rumbos frame', () => {
        assert.deepEqual(parseAppMessage(fromApp({type: 'tutor.open', v: 1}), expected), {type: MESSAGE.TUTOR_OPEN});
    });

    it('carries nothing: a url or any other field the app adds is dropped', () => {
        const message = parseAppMessage(fromApp({type: 'tutor.open', v: 1, url: 'https://evil.example', data: {a: 1}}), expected);
        assert.deepEqual(message, {type: 'tutor.open'});
    });

    it('is ignored from another origin, even with the right window', () => {
        assert.equal(parseAppMessage(fromApp({type: 'tutor.open', v: 1}, {origin: 'https://evil.example'}), expected), null);
        assert.equal(parseAppMessage(fromApp({type: 'tutor.open', v: 1}, {origin: SITE}), expected), null);
    });

    it('is ignored from another window, even with the right origin', () => {
        assert.equal(parseAppMessage(fromApp({type: 'tutor.open', v: 1}, {source: {name: 'other'}}), expected), null);
        assert.equal(parseAppMessage(fromApp({type: 'tutor.open', v: 1}, {source: null}), expected), null);
    });

    it('is ignored without the protocol version', () => {
        assert.equal(parseAppMessage(fromApp({type: 'tutor.open'}), expected), null);
        assert.equal(parseAppMessage(fromApp({type: 'tutor.open', v: 2}), expected), null);
    });

    it('is never sent by the host: tutor.closed is not an app message', () => {
        assert.equal(parseAppMessage(fromApp({type: 'tutor.closed', v: 1}), expected), null);
    });
});

describe('capabilities in host-init', () => {
    const base = {mode: 'student', locale: 'es', courseId: 2, courseName: 'Ruta', assertion: 'jws', state: {}};

    it('announces the tutor only when it is given', () => {
        assert.deepEqual(hostInit({...base, capabilities: {tutor: true}}).capabilities, {tutor: true});
    });

    it('is an empty object when no feature is on, never a missing tutor flag set to false', () => {
        assert.deepEqual(hostInit(base).capabilities, {});
        assert.equal('tutor' in hostInit(base).capabilities, false);
    });
});

describe('hostTutorClosed', () => {
    it('is the closed message with the protocol version and nothing else', () => {
        assert.deepEqual(hostTutorClosed(), {type: 'tutor.closed', v: 1});
    });
});

describe('resolveTutorUrl', () => {
    const site = {siteOrigin: SITE};

    it('accepts an https address on the page origin', () => {
        assert.equal(resolveTutorUrl(`${SITE}/agente/chat`, site), `${SITE}/agente/chat`);
    });

    it('refuses another host, another port and another scheme', () => {
        assert.equal(resolveTutorUrl('https://evil.example/agente/chat', site), null);
        assert.equal(resolveTutorUrl('https://lms.example.com:8443/agente/chat', site), null);
        assert.equal(resolveTutorUrl('http://lms.example.com/agente/chat', site), null);
        assert.equal(resolveTutorUrl('javascript:alert(1)', site), null);
        assert.equal(resolveTutorUrl('data:text/html,hi', site), null);
    });

    it('refuses a look-alike host and credentials', () => {
        assert.equal(resolveTutorUrl('https://lms.example.com.evil.example/x', site), null);
        assert.equal(resolveTutorUrl('https://lms.example.com@evil.example/x', site), null);
        assert.equal(resolveTutorUrl('https://user:pass@lms.example.com/x', site), null);
    });

    it('refuses what is not an address', () => {
        for (const value of ['', null, undefined, 42, {}, '/agente/chat', 'not a url']) {
            assert.equal(resolveTutorUrl(value, site), null, String(value));
        }
    });
});
