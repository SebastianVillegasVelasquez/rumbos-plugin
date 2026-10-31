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
    ASPECT_RATIO_RANGE,
    ERROR_CODES,
    MAX_DETAIL_LENGTH,
    MESSAGE,
    NO_CONTENT_REASONS,
    POPUP_MESSAGE,
    hostFullscreen,
    hostInit,
    hostLaunch,
    hostOverlay,
    hostStats,
    hostStatsError,
    hasActivity,
    hostState,
    isCourseViewUrl,
    parseAppMessage,
    parsePopupMessage,
    resolveActivityTarget,
    resolveSettingsUrl,
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

    it('accepts open-activity-settings (contract v5.4) with a positive integer cmid only', () => {
        assert.deepEqual(
            parseAppMessage(fromApp({type: MESSAGE.OPEN_ACTIVITY_SETTINGS, v: 1, cmid: 42}), expected),
            {type: MESSAGE.OPEN_ACTIVITY_SETTINGS, cmid: 42},
        );
        for (const cmid of [0, -1, 1.5, '42', null, undefined, NaN, Infinity]) {
            assert.equal(parseAppMessage(fromApp({type: MESSAGE.OPEN_ACTIVITY_SETTINGS, v: 1, cmid}), expected), null);
        }
    });

    it('drops anything else the app adds to open-activity-settings, such as an address', () => {
        const message = {type: MESSAGE.OPEN_ACTIVITY_SETTINGS, v: 1, cmid: 42, url: 'https://evil.example.com/'};
        assert.deepEqual(parseAppMessage(fromApp(message), expected), {type: MESSAGE.OPEN_ACTIVITY_SETTINGS, cmid: 42});
    });

    it('accepts request-fullscreen with a boolean', () => {
        assert.deepEqual(
            parseAppMessage(fromApp({type: MESSAGE.REQUEST_FULLSCREEN, v: 1, on: true}), expected),
            {type: MESSAGE.REQUEST_FULLSCREEN, on: true},
        );
    });

    it('accepts request-stats (contract v5.7) with no group, or a group id of zero or more', () => {
        assert.deepEqual(
            parseAppMessage(fromApp({type: MESSAGE.REQUEST_STATS, v: 1}), expected),
            {type: MESSAGE.REQUEST_STATS, groupId: 0},
        );
        for (const groupId of [0, 3, 120]) {
            assert.deepEqual(
                parseAppMessage(fromApp({type: MESSAGE.REQUEST_STATS, v: 1, groupId}), expected),
                {type: MESSAGE.REQUEST_STATS, groupId},
            );
        }
    });

    it('rejects request-stats with a group id that is not a non-negative integer', () => {
        for (const groupId of [-1, 1.5, '3', null, NaN, Infinity, {}, [], true]) {
            assert.equal(parseAppMessage(fromApp({type: MESSAGE.REQUEST_STATS, v: 1, groupId}), expected), null);
        }
    });

    it('drops anything else the app adds to request-stats, such as a user id', () => {
        const message = {type: MESSAGE.REQUEST_STATS, v: 1, groupId: 2, userId: 7, refresh: true};
        assert.deepEqual(parseAppMessage(fromApp(message), expected), {type: MESSAGE.REQUEST_STATS, groupId: 2});
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

describe('rumbos:error (contract v5.1)', () => {
    const error = (extra) => fromApp({type: MESSAGE.ERROR, v: 1, ...extra});

    it('accepts every documented code', () => {
        for (const code of ERROR_CODES) {
            assert.deepEqual(parseAppMessage(error({code}), expected), {type: MESSAGE.ERROR, code, detail: ''});
        }
        assert.deepEqual(ERROR_CODES, ['launch_failed', 'token_refresh_failed', 'app_crashed', 'protocol_unsupported']);
    });

    it('keeps the detail as plain text', () => {
        const parsed = parseAppMessage(error({code: 'app_crashed', detail: '<img src=x onerror=alert(1)>'}), expected);
        // Kept verbatim as text: the host renders it with textContent only, never as HTML.
        assert.equal(parsed.detail, '<img src=x onerror=alert(1)>');
    });

    it('truncates a detail longer than 200 characters', () => {
        const parsed = parseAppMessage(error({code: 'app_crashed', detail: 'x'.repeat(500)}), expected);
        assert.equal(parsed.detail.length, MAX_DETAIL_LENGTH);
        assert.equal(MAX_DETAIL_LENGTH, 200);
    });

    it('flattens control characters and newlines in the detail', () => {
        const detail = 'a\nb\u0000c\u001b[31m';
        const parsed = parseAppMessage(error({code: 'app_crashed', detail}), expected);
        assert.equal(parsed.detail, 'a b c [31m');
    });

    it('ignores a detail that is not a string', () => {
        for (const detail of [42, {}, [], null, true]) {
            assert.equal(parseAppMessage(error({code: 'app_crashed', detail}), expected).detail, '');
        }
    });

    it('rejects unknown, missing or non-string codes', () => {
        for (const code of ['boom', '', undefined, null, 7, ['app_crashed'], 'APP_CRASHED']) {
            assert.equal(parseAppMessage(error({code}), expected), null, String(code));
        }
    });

    it('rejects an error from the wrong sender', () => {
        const forged = fromApp({type: MESSAGE.ERROR, v: 1, code: 'app_crashed'}, {origin: 'https://evil.example.com'});
        assert.equal(parseAppMessage(forged, expected), null);
    });
});

describe('rumbos:no-content (contract v5.5)', () => {
    it('accepts the two known reasons', () => {
        assert.deepEqual(NO_CONTENT_REASONS, ['no_saved_map', 'empty_map']);
        for (const reason of NO_CONTENT_REASONS) {
            assert.deepEqual(
                parseAppMessage(fromApp({type: MESSAGE.NO_CONTENT, v: 1, reason}), expected),
                {type: MESSAGE.NO_CONTENT, reason},
            );
        }
    });

    it('rejects an unknown, missing or non-string reason', () => {
        for (const reason of ['other', '', 'NO_SAVED_MAP', 7, null, undefined, {}, ['empty_map']]) {
            assert.equal(parseAppMessage(fromApp({type: MESSAGE.NO_CONTENT, v: 1, reason}), expected), null);
        }
        assert.equal(parseAppMessage(fromApp({type: MESSAGE.NO_CONTENT, v: 1}), expected), null);
    });

    it('is ignored from another origin, another window or another version', () => {
        const data = {type: MESSAGE.NO_CONTENT, v: 1, reason: 'empty_map'};
        assert.equal(parseAppMessage(fromApp(data, {origin: 'https://evil.example.com'}), expected), null);
        assert.equal(parseAppMessage(fromApp(data, {source: {}}), expected), null);
        assert.equal(parseAppMessage(fromApp({...data, v: 2}), expected), null);
    });

    it('drops anything else the app adds', () => {
        const data = {type: MESSAGE.NO_CONTENT, v: 1, reason: 'empty_map', html: '<b>x</b>'};
        assert.deepEqual(parseAppMessage(fromApp(data), expected), {type: MESSAGE.NO_CONTENT, reason: 'empty_map'});
    });
});

describe('rumbos:preferred-size (contract v5.5)', () => {
    const size = (aspectRatio) => fromApp({type: MESSAGE.PREFERRED_SIZE, v: 1, aspectRatio});

    it('accepts a ratio from 0.5 to 4, bounds included', () => {
        assert.deepEqual(ASPECT_RATIO_RANGE, {min: 0.5, max: 4});
        for (const aspectRatio of [0.5, 1, 16 / 9, 4]) {
            assert.deepEqual(parseAppMessage(size(aspectRatio), expected), {type: MESSAGE.PREFERRED_SIZE, aspectRatio});
        }
    });

    it('rejects a ratio outside the range or that is not a finite number', () => {
        for (const aspectRatio of [0.49, 4.01, 0, -1, NaN, Infinity, -Infinity, '1.5', null, undefined, {}]) {
            assert.equal(parseAppMessage(size(aspectRatio), expected), null);
        }
    });

    it('is ignored from another origin or window', () => {
        assert.equal(parseAppMessage({...size(1.5), origin: 'https://evil.example.com'}, expected), null);
        assert.equal(parseAppMessage({...size(1.5), source: {}}, expected), null);
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
            hostInit({mode: 'editor', locale: 'es', courseId: 2, courseName: 'Ruta 1', assertion: 'jws', state}),
            {
                type: 'rumbos:host-init', v: 1, mode: 'editor', locale: 'es', courseId: 2, courseName: 'Ruta 1',
                assertion: 'jws', state,
            },
        );
    });

    it('builds host-stats with the counts, or with an error and nothing else', () => {
        const stats = {schemaVersion: 1, tracked: 8};
        assert.deepEqual(hostStats(stats), {type: 'rumbos:host-stats', v: 1, stats});
        assert.deepEqual(hostStatsError('capability'), {type: 'rumbos:host-stats', v: 1, error: 'capability'});
        assert.deepEqual(Object.keys(hostStatsError('unavailable')), ['type', 'v', 'error']);
    });

    it('build host-fullscreen with the exact boolean', () => {
        assert.deepEqual(hostFullscreen(true), {type: 'rumbos:host-fullscreen', v: 1, on: true});
        assert.deepEqual(hostFullscreen(false), {type: 'rumbos:host-fullscreen', v: 1, on: false});
    });

    it('build host-state and host-launch', () => {
        assert.deepEqual(hostState({a: 1}), {type: 'rumbos:host-state', v: 1, state: {a: 1}});
        assert.deepEqual(hostLaunch('jws'), {type: 'rumbos:host-launch', v: 1, assertion: 'jws'});
    });

    it('build host-overlay with the exact boolean (contract v5.3)', () => {
        assert.deepEqual(hostOverlay(true), {type: 'rumbos:host-overlay', v: 1, open: true});
        assert.deepEqual(hostOverlay(false), {type: 'rumbos:host-overlay', v: 1, open: false});
    });
});

describe('resolveActivityTarget', () => {
    const page = (cmid, extra = {}) => ({
        cmid,
        modname: 'page',
        name: `Page ${cmid}`,
        availability: 'available',
        url: `${SITE}/mod/page/view.php?id=${cmid}`,
        ...extra,
    });
    const state = {
        activities: [
            page(1),
            page(2, {availability: 'restricted', url: null}),
            page(3, {availability: 'restricted'}),
            page(4, {availability: 'hidden'}),
            page(5, {url: 'https://evil.example.com/steal'}),
            page(6, {url: 'javascript:alert(1)'}),
            page(7, {url: '/mod/page/view.php?id=7'}),
            page(8, {modname: 'lti', url: `${SITE}/mod/lti/view.php?id=8`}),
            page(9, {url: `${SITE}/course/view.php?id=2`}),
            page(10, {url: `${SITE}/mod/../admin/index.php`}),
            page(11, {url: 'https://lms.example.com.evil.example.com/mod/page/view.php?id=11'}),
            page(12, {url: `${SITE}/modx/page/view.php?id=12`}),
            page(13, {url: 'data:text/html,<script>1</script>'}),
        ],
    };
    const site = {siteOrigin: SITE, wwwroot: SITE, newTabModules: ['lti']};
    const resolve = (cmid, overrides = {}) => resolveActivityTarget(state, cmid, {...site, ...overrides});

    it('opens an available activity under /mod/ in the popup, with its title', () => {
        assert.deepEqual(resolve(1), {
            target: 'popup',
            url: `${SITE}/mod/page/view.php?id=1`,
            title: 'Page 1',
            modname: 'page',
        });
    });

    it('opens a hidden activity (editors only receive them)', () => {
        assert.equal(resolve(4).target, 'popup');
    });

    it('returns null for cmids that are not in the state, or when there is no state', () => {
        assert.equal(resolve(99), null);
        assert.equal(resolveActivityTarget(null, 1, site), null);
        assert.equal(resolveActivityTarget({}, 1, site), null);
    });

    it('returns null for restricted activities, with or without URL', () => {
        assert.equal(resolve(2), null);
        assert.equal(resolve(3), null);
    });

    it('refuses URLs outside the Moodle site or with another scheme', () => {
        for (const cmid of [5, 6, 11, 13]) {
            assert.equal(resolve(cmid), null, `cmid ${cmid}`);
        }
    });

    it('resolves a relative URL against the site', () => {
        assert.equal(resolve(7).url, `${SITE}/mod/page/view.php?id=7`);
    });

    it('opens modules in the new-tab list in a new tab', () => {
        assert.equal(resolve(8).target, 'tab');
        assert.equal(resolve(8, {newTabModules: []}).target, 'popup');
    });

    it('opens Moodle URLs that are not under /mod/ in a new tab', () => {
        assert.equal(resolve(9).target, 'tab');
    });

    it('does not treat a path that only starts like /mod/ as an activity', () => {
        assert.equal(resolve(12).target, 'tab');
    });

    it('normalises dot segments before checking the path', () => {
        assert.equal(resolve(10).target, 'tab');
    });

    it('honours a Moodle site installed in a sub-directory', () => {
        const sub = `${SITE}/moodle`;
        const subState = {
            activities: [
                {cmid: 1, modname: 'page', name: 'A', availability: 'available', url: `${sub}/mod/page/view.php?id=1`},
                {cmid: 2, modname: 'page', name: 'B', availability: 'available', url: `${SITE}/mod/page/view.php?id=2`},
            ],
        };
        const subSite = {siteOrigin: SITE, wwwroot: sub, newTabModules: []};
        assert.equal(resolveActivityTarget(subState, 1, subSite).target, 'popup');
        assert.equal(resolveActivityTarget(subState, 2, subSite), null);
    });

    it('keeps the title as plain text', () => {
        const titled = {activities: [page(1, {name: '<img src=x onerror=alert(1)>'})]};
        assert.equal(resolveActivityTarget(titled, 1, site).title, '<img src=x onerror=alert(1)>');
    });
});

describe('isCourseViewUrl', () => {
    const site = {wwwroot: SITE, courseId: 2};

    it('recognises the view page of this course', () => {
        assert.equal(isCourseViewUrl(`${SITE}/course/view.php?id=2`, site), true);
        assert.equal(isCourseViewUrl(`${SITE}/course/view.php?id=2&section=3#x`, site), true);
    });

    it('does not match other courses, pages, origins or garbage', () => {
        assert.equal(isCourseViewUrl(`${SITE}/course/view.php?id=3`, site), false);
        assert.equal(isCourseViewUrl(`${SITE}/course/view.php`, site), false);
        assert.equal(isCourseViewUrl(`${SITE}/mod/page/view.php?id=2`, site), false);
        assert.equal(isCourseViewUrl('https://evil.example.com/course/view.php?id=2', site), false);
        assert.equal(isCourseViewUrl('not a url', site), false);
        assert.equal(isCourseViewUrl('about:blank', site), false);
    });

    it('honours a Moodle site installed in a sub-directory', () => {
        const sub = {wwwroot: `${SITE}/moodle`, courseId: 2};
        assert.equal(isCourseViewUrl(`${SITE}/moodle/course/view.php?id=2`, sub), true);
        assert.equal(isCourseViewUrl(`${SITE}/course/view.php?id=2`, sub), false);
    });
});

describe('parsePopupMessage', () => {
    const popupWindow = {name: 'popup-window'};
    const expectedPopup = {siteOrigin: SITE, frameWindow: popupWindow};
    const fromPopup = (data, overrides = {}) => ({origin: SITE, source: popupWindow, data, ...overrides});

    it('accepts the known messages from the popup frame on this site', () => {
        const leave = fromPopup({type: POPUP_MESSAGE.LEAVE, v: 1});
        const escape = fromPopup({type: POPUP_MESSAGE.ESCAPE, v: 1});
        assert.equal(parsePopupMessage(leave, expectedPopup), POPUP_MESSAGE.LEAVE);
        assert.equal(parsePopupMessage(escape, expectedPopup), POPUP_MESSAGE.ESCAPE);
    });

    it('ignores other origins, other windows, other versions and unknown types', () => {
        const message = {type: POPUP_MESSAGE.LEAVE, v: 1};
        assert.equal(parsePopupMessage(fromPopup(message, {origin: APP}), expectedPopup), null);
        assert.equal(parsePopupMessage(fromPopup(message, {source: {}}), expectedPopup), null);
        assert.equal(parsePopupMessage(fromPopup({...message, v: 2}), expectedPopup), null);
        assert.equal(parsePopupMessage(fromPopup({type: 'rumbos:open-activity', v: 1}), expectedPopup), null);
        assert.equal(parsePopupMessage(fromPopup('rumbos:popup-leave'), expectedPopup), null);
        assert.equal(parsePopupMessage(fromPopup(message), {siteOrigin: SITE, frameWindow: null}), null);
    });
});

describe('hasActivity', () => {
    const state = {activities: [{cmid: 7, availability: 'restricted', url: null}, {cmid: 8, availability: 'hidden'}]};

    it('finds any listed activity, whatever its availability', () => {
        assert.equal(hasActivity(state, 7), true);
        assert.equal(hasActivity(state, 8), true);
    });

    it('does not find an activity the last state does not list', () => {
        assert.equal(hasActivity(state, 9), false);
        assert.equal(hasActivity({activities: [], unavailableCmids: [9]}, 9), false);
        assert.equal(hasActivity(null, 7), false);
        assert.equal(hasActivity({}, 7), false);
    });
});

describe('resolveSettingsUrl', () => {
    const site = {wwwroot: SITE};

    it('accepts the activity settings page of this site', () => {
        const href = `${SITE}/course/modedit.php?update=12&showonly=activitycompletionheader`;
        assert.equal(resolveSettingsUrl(href, site), href);
    });

    it('honours a Moodle site installed in a sub-directory', () => {
        const href = `${SITE}/moodle/course/modedit.php?update=12`;
        assert.equal(resolveSettingsUrl(href, {wwwroot: `${SITE}/moodle`}), href);
        assert.equal(resolveSettingsUrl(`${SITE}/course/modedit.php?update=12`, {wwwroot: `${SITE}/moodle`}), null);
    });

    it('rejects other sites, other pages, other schemes and a missing or odd activity id', () => {
        for (const href of [
            'https://evil.example.com/course/modedit.php?update=12',
            `${SITE}.evil.example.com/course/modedit.php?update=12`,
            `${SITE}/course/view.php?id=2`,
            `${SITE}/course/modedit.php`,
            `${SITE}/course/modedit.php?update=abc`,
            `${SITE}/course/modedit.php?update=0`,
            `${SITE}/course/modedit.php/extra?update=12`,
            `javascript:alert(1)//${SITE}/course/modedit.php?update=12`,
            'data:text/html,hello',
            '//evil.example.com/course/modedit.php?update=12',
            '',
            'not a url',
        ]) {
            assert.equal(resolveSettingsUrl(href, site), null, href);
        }
    });
});
