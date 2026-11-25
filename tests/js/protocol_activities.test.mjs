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
import {
    MESSAGE,
    hostActivities,
    hostActivitiesError,
    hostActivityClosed,
    hostActivityOpenFailed,
    hostActivityOpened,
    hostCapabilities,
    hostInit,
    parseAppMessage,
    resolveFlowActivityUrl,
} from '../../amd/src/protocol.js';

const APP = 'https://rumbos.example.com';
const SITE = 'https://lms.example.com';
const frameWindow = {name: 'rumbos-frame'};
const expected = {appOrigin: APP, frameWindow};
const fromApp = (data, overrides = {}) => ({origin: APP, source: frameWindow, data, ...overrides});

describe('rumbos:request-activities', () => {
    it('is accepted with no fields, and anything the app adds is dropped', () => {
        assert.deepEqual(parseAppMessage(fromApp({type: 'rumbos:request-activities', v: 1}), expected),
            {type: MESSAGE.REQUEST_ACTIVITIES});
        assert.deepEqual(
            parseAppMessage(fromApp({type: 'rumbos:request-activities', v: 1, courseId: 9, url: 'https://evil.example'}), expected),
            {type: 'rumbos:request-activities'}
        );
    });

    it('is ignored from another origin, another window or without the version', () => {
        const message = {type: 'rumbos:request-activities', v: 1};
        assert.equal(parseAppMessage(fromApp(message, {origin: 'https://evil.example'}), expected), null);
        assert.equal(parseAppMessage(fromApp(message, {source: {name: 'other'}}), expected), null);
        assert.equal(parseAppMessage(fromApp({type: message.type}), expected), null);
    });
});

describe('rumbos:activity-open', () => {
    it('is accepted with a positive integer cmid and nothing else', () => {
        assert.deepEqual(parseAppMessage(fromApp({type: 'rumbos:activity-open', v: 1, cmid: 42, url: 'https://evil.example'}), expected),
            {type: MESSAGE.ACTIVITY_OPEN, cmid: 42});
    });

    it('is ignored when the cmid is not a positive integer', () => {
        for (const cmid of [0, -1, 1.5, '42', null, undefined, NaN, Infinity, [], {}, true, 2 ** 60]) {
            assert.equal(parseAppMessage(fromApp({type: 'rumbos:activity-open', v: 1, cmid}), expected), null, String(cmid));
        }
    });

    it('is ignored from another origin or another window', () => {
        const message = {type: 'rumbos:activity-open', v: 1, cmid: 42};
        assert.equal(parseAppMessage(fromApp(message, {origin: SITE}), expected), null);
        assert.equal(parseAppMessage(fromApp(message, {source: null}), expected), null);
    });

    it('has no address field: a url sent instead of a cmid is not a message', () => {
        assert.equal(parseAppMessage(fromApp({type: 'rumbos:activity-open', v: 1, url: 'https://lms.example.com/mod/page/view.php?id=3'}),
            expected), null);
    });

    it('is not confused with the map activity message, and the host replies are not app messages', () => {
        assert.equal(MESSAGE.ACTIVITY_OPEN, 'rumbos:activity-open');
        assert.notEqual(MESSAGE.ACTIVITY_OPEN, MESSAGE.OPEN_ACTIVITY);
        for (const type of ['rumbos:host-activities', 'rumbos:host-activity-opened', 'rumbos:host-activity-open-failed',
            'rumbos:host-activity-closed']) {
            assert.equal(parseAppMessage(fromApp({type, v: 1, cmid: 1}), expected), null, type);
        }
    });
});

describe('hostActivities', () => {
    const item = {cmid: 7, name: 'Guía de inicio', modname: 'resource', visible: true};

    it('carries the items field by field, and nothing else Moodle returned', () => {
        const message = hostActivities({items: [{...item, url: 'https://lms.example.com/mod/resource/view.php?id=7', extra: 1}]});
        assert.deepEqual(message, {type: 'rumbos:host-activities', v: 1, items: [item]});
    });

    it('accepts an empty list', () => {
        assert.deepEqual(hostActivities({items: []}), {type: 'rumbos:host-activities', v: 1, items: []});
    });

    it('is null when the reply or any item is not well formed', () => {
        assert.equal(hostActivities(null), null);
        assert.equal(hostActivities({}), null);
        assert.equal(hostActivities({items: 'x'}), null);
        for (const broken of [{cmid: 0}, {cmid: '7'}, {name: 5}, {name: 'x'.repeat(256)}, {modname: 'quiz'}, {modname: ''},
            {visible: 1}, {visible: undefined}]) {
            assert.equal(hostActivities({items: [{...item, ...broken}]}), null, JSON.stringify(broken));
        }
    });

    it('has an error form that never names anything but the two reasons', () => {
        assert.deepEqual(hostActivitiesError('capability'), {type: 'rumbos:host-activities', v: 1, error: 'capability'});
        assert.deepEqual(hostActivitiesError('unavailable'), {type: 'rumbos:host-activities', v: 1, error: 'unavailable'});
        assert.equal(hostActivitiesError('SQL error near x').error, 'unavailable');
    });
});

describe('the replies about opening an activity', () => {
    it('opened and closed carry the cmid and nothing else, in particular no address', () => {
        assert.deepEqual(hostActivityOpened(5), {type: 'rumbos:host-activity-opened', v: 1, cmid: 5});
        assert.deepEqual(hostActivityClosed(5), {type: 'rumbos:host-activity-closed', v: 1, cmid: 5});
    });

    it('failed names one of the three reasons, and any other text becomes not_found', () => {
        for (const reason of ['not_found', 'forbidden', 'unsupported']) {
            assert.deepEqual(hostActivityOpenFailed(5, reason), {type: 'rumbos:host-activity-open-failed', v: 1, cmid: 5, reason});
        }
        assert.equal(hostActivityOpenFailed(5, 'exception: /var/www/secret').reason, 'not_found');
        assert.equal(hostActivityOpenFailed(5, undefined).reason, 'not_found');
    });

    it('no reply of this family has a url, href or link field', () => {
        const messages = [
            hostActivities({items: [{cmid: 1, name: 'a', modname: 'page', visible: true}]}),
            hostActivitiesError('capability'), hostActivityOpened(1), hostActivityOpenFailed(1, 'forbidden'), hostActivityClosed(1),
        ];
        for (const message of messages) {
            assert.doesNotMatch(JSON.stringify(message), /url|href|link|http/i);
        }
    });
});

describe('capabilities in host-init (v5.10 and v5.11)', () => {
    it('a student is told about activity and not about activities', () => {
        assert.deepEqual(hostCapabilities({tutor: false, canEdit: false}), {activity: true});
    });

    it('an editor is told about both', () => {
        assert.deepEqual(hostCapabilities({tutor: false, canEdit: true}), {activities: true, activity: true});
    });

    it('the tutor is announced only when configured, and nothing is ever set to false', () => {
        assert.deepEqual(hostCapabilities({tutor: true, canEdit: true}), {tutor: true, activities: true, activity: true});
        for (const features of [{tutor: false, canEdit: false}, {tutor: true, canEdit: false}, {tutor: false, canEdit: true}]) {
            assert.equal(Object.values(hostCapabilities(features)).includes(false), false);
        }
    });

    it('travels in host-init', () => {
        const init = hostInit({
            mode: 'student', locale: 'es', courseId: 2, courseName: 'Ruta', assertion: 'jws', state: {},
            capabilities: hostCapabilities({tutor: false, canEdit: false}),
        });
        assert.deepEqual(init.capabilities, {activity: true});
    });
});

describe('resolveFlowActivityUrl', () => {
    const site = {siteOrigin: SITE, wwwroot: SITE};

    it('accepts the view page of each allowed module and the plugin inline page', () => {
        for (const path of ['/mod/resource/view.php', '/mod/url/view.php', '/mod/page/view.php',
            '/course/format/rumbos/resource.php']) {
            assert.equal(resolveFlowActivityUrl(`${SITE}${path}?id=12`, site), `${SITE}${path}?id=12`, path);
        }
    });

    it('works for a site in a sub-directory', () => {
        const sub = {siteOrigin: SITE, wwwroot: `${SITE}/moodle`};
        assert.equal(resolveFlowActivityUrl(`${SITE}/moodle/mod/page/view.php?id=3`, sub), `${SITE}/moodle/mod/page/view.php?id=3`);
        assert.equal(resolveFlowActivityUrl(`${SITE}/mod/page/view.php?id=3`, sub), null);
    });

    it('refuses another origin, a look-alike host, credentials and non-http schemes', () => {
        for (const href of ['https://evil.example/mod/page/view.php?id=3', 'https://lms.example.com.evil.example/mod/page/view.php?id=3',
            'https://lms.example.com@evil.example/mod/page/view.php?id=3', 'https://u:p@lms.example.com/mod/page/view.php?id=3',
            'https://lms.example.com:8443/mod/page/view.php?id=3', 'javascript:alert(1)', 'data:text/html,hi']) {
            assert.equal(resolveFlowActivityUrl(href, site), null, href);
        }
    });

    it('refuses any other page of the site, a missing or odd id, and modules that are not allowed', () => {
        for (const href of [`${SITE}/mod/quiz/view.php?id=3`, `${SITE}/mod/scorm/view.php?id=3`, `${SITE}/login/logout.php?id=3`,
            `${SITE}/mod/page/view.php`, `${SITE}/mod/page/view.php?id=0`, `${SITE}/mod/page/view.php?id=3x`,
            `${SITE}/mod/page/view.php?id=-3`, `${SITE}/mod/page/../quiz/view.php?id=3`, `${SITE}/pluginfile.php/1/mod_resource/content/1/a.pdf`]) {
            assert.equal(resolveFlowActivityUrl(href, site), null, href);
        }
    });

    it('refuses what is not an address', () => {
        for (const value of ['', null, undefined, 42, {}, '/mod/page/view.php?id=3', 'not a url']) {
            assert.equal(resolveFlowActivityUrl(value, site), null, String(value));
        }
    });
});
