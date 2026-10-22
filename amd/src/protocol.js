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
 * Pure helpers for the host <-> Rumbos app postMessage protocol (contract v5, up to v5.4).
 *
 * Nothing here touches the DOM or Moodle APIs, so it can be unit tested with
 * plain Node (see tests/js).
 *
 * @module     format_rumbos/protocol
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

export const PROTOCOL_VERSION = 1;

export const MESSAGE = {
    EMBED_READY: 'rumbos:embed-ready',
    REQUEST_STATE: 'rumbos:request-state',
    REQUEST_LAUNCH: 'rumbos:request-launch',
    OPEN_ACTIVITY: 'rumbos:open-activity',
    OPEN_ACTIVITY_SETTINGS: 'rumbos:open-activity-settings',
    REQUEST_FULLSCREEN: 'rumbos:request-fullscreen',
    ERROR: 'rumbos:error',
    HOST_INIT: 'rumbos:host-init',
    HOST_STATE: 'rumbos:host-state',
    HOST_LAUNCH: 'rumbos:host-launch',
    HOST_FULLSCREEN: 'rumbos:host-fullscreen',
    HOST_OVERLAY: 'rumbos:host-overlay',
};

/**
 * Messages the activity page inside the popup sends to the host (same origin, not part of the app contract).
 */
export const POPUP_MESSAGE = {
    LEAVE: 'rumbos:popup-leave',
    ESCAPE: 'rumbos:popup-escape',
};

/** Error codes the app may report (contract v5.1). */
export const ERROR_CODES = ['launch_failed', 'token_refresh_failed', 'app_crashed', 'protocol_unsupported'];

/** Maximum length of the untrusted error detail. */
export const MAX_DETAIL_LENGTH = 200;

/**
 * Whether a value is a plain JSON-like object (not null, array or class instance).
 *
 * @param {*} value
 * @returns {boolean}
 */
const isPlainObject = (value) => Object.prototype.toString.call(value) === '[object Object]';

/**
 * Reduces the untrusted error detail to a short single line of text.
 *
 * It is still untrusted: callers must only ever render it with textContent.
 *
 * @param {*} detail
 * @returns {string} Empty when there is no usable detail.
 */
const cleanDetail = (detail) => {
    if (typeof detail !== 'string') {
        return '';
    }
    // eslint-disable-next-line no-control-regex
    return detail.replace(/[\u0000-\u001f\u007f]+/g, ' ').trim().slice(0, MAX_DETAIL_LENGTH);
};

/**
 * Validates a message event coming from the app.
 *
 * The event is accepted only when it comes from the expected origin AND from
 * the iframe's own window, carries protocol version 1, and has a known type
 * with well-formed fields. Anything else is ignored.
 *
 * @param {{origin: string, source: *, data: *}} event The message event.
 * @param {{appOrigin: string, frameWindow: *}} expected Expected sender.
 * @returns {?{type: string, cmid: (number|undefined), on: (boolean|undefined)}} Clean message, or null.
 */
export const parseAppMessage = (event, {appOrigin, frameWindow}) => {
    if (!event || !frameWindow || event.origin !== appOrigin || event.source !== frameWindow) {
        return null;
    }
    const data = event.data;
    if (!isPlainObject(data) || data.v !== PROTOCOL_VERSION || typeof data.type !== 'string') {
        return null;
    }

    switch (data.type) {
        case MESSAGE.EMBED_READY:
        case MESSAGE.REQUEST_STATE:
        case MESSAGE.REQUEST_LAUNCH:
            return {type: data.type};
        case MESSAGE.OPEN_ACTIVITY:
        case MESSAGE.OPEN_ACTIVITY_SETTINGS:
            return Number.isSafeInteger(data.cmid) && data.cmid > 0 ? {type: data.type, cmid: data.cmid} : null;
        case MESSAGE.REQUEST_FULLSCREEN:
            return typeof data.on === 'boolean' ? {type: data.type, on: data.on} : null;
        case MESSAGE.ERROR:
            return ERROR_CODES.includes(data.code)
                ? {type: data.type, code: data.code, detail: cleanDetail(data.detail)}
                : null;
        default:
            return null;
    }
};

/**
 * The host-init message.
 *
 * @param {{mode: string, locale: string, courseId: number, assertion: string, state: Object}} init
 * @returns {Object}
 */
export const hostInit = ({mode, locale, courseId, assertion, state}) => ({
    type: MESSAGE.HOST_INIT,
    v: PROTOCOL_VERSION,
    mode,
    locale,
    courseId,
    assertion,
    state,
});

/**
 * The host-state message.
 *
 * @param {Object} state CourseState.
 * @returns {Object}
 */
export const hostState = (state) => ({type: MESSAGE.HOST_STATE, v: PROTOCOL_VERSION, state});

/**
 * The host-launch message.
 *
 * @param {string} assertion Signed launch assertion.
 * @returns {Object}
 */
export const hostLaunch = (assertion) => ({type: MESSAGE.HOST_LAUNCH, v: PROTOCOL_VERSION, assertion});

/**
 * The host-fullscreen message: the host's actual fullscreen state.
 *
 * @param {boolean} on
 * @returns {Object}
 */
export const hostFullscreen = (on) => ({type: MESSAGE.HOST_FULLSCREEN, v: PROTOCOL_VERSION, on});

/**
 * The host-overlay message: the host opened or closed an overlay above the app.
 *
 * @param {boolean} open
 * @returns {Object}
 */
export const hostOverlay = (open) => ({type: MESSAGE.HOST_OVERLAY, v: PROTOCOL_VERSION, open});

/**
 * Path of the Moodle site without a trailing slash ("" for a site at the root of its host).
 *
 * @param {URL} root The wwwroot URL.
 * @returns {string}
 */
const rootPath = (root) => root.pathname.replace(/\/+$/, '');

/**
 * Decides how to open an activity, using ONLY the host's own last state.
 *
 * The app never supplies URLs. The cmid must be in the state, the activity must
 * be openable (not restricted, with a URL) and the URL must be a Moodle URL:
 *  - "popup": same origin as this page and under {wwwroot}/mod/, and the module
 *    is not in the new-tab list.
 *  - "tab": any other URL under the Moodle site (a module that works poorly in a
 *    frame, or a page that is not an activity).
 *  - null: not a Moodle URL at all, so it is not opened.
 *
 * @param {?{activities: Array}} state Last CourseState received from Moodle.
 * @param {number} cmid Course module id requested by the app.
 * @param {{siteOrigin: string, wwwroot: string, newTabModules: string[]}} site Where the page is running.
 * @returns {?{target: string, url: string, title: string, modname: string}} What to open, or null when it must not be opened.
 */
export const resolveActivityTarget = (state, cmid, {siteOrigin, wwwroot, newTabModules}) => {
    const activity = state?.activities?.find((candidate) => candidate.cmid === cmid);
    if (!activity || activity.availability === 'restricted' || typeof activity.url !== 'string') {
        return null;
    }
    try {
        const root = new URL(wwwroot);
        const url = new URL(activity.url, siteOrigin);
        const base = rootPath(root);
        if (!['http:', 'https:'].includes(url.protocol) || url.origin !== root.origin ||
                (url.pathname !== base && !url.pathname.startsWith(`${base}/`))) {
            return null;
        }
        const inMod = url.origin === siteOrigin && url.pathname.startsWith(`${base}/mod/`);
        const popup = inMod && !newTabModules.includes(activity.modname);
        return {
            target: popup ? 'popup' : 'tab',
            url: url.href,
            title: String(activity.name ?? ''),
            modname: String(activity.modname ?? ''),
        };
    } catch (error) {
        return null;
    }
};

/**
 * Whether the host's last state lists an activity.
 *
 * It is the only thing the host needs to know about an activity before asking Moodle for its settings page:
 * unlike opening an activity, configuring it does not depend on the activity being available to the user.
 *
 * @param {?{activities: Array}} state Last CourseState received from Moodle.
 * @param {number} cmid Course module id requested by the app.
 * @returns {boolean}
 */
export const hasActivity = (state, cmid) => Array.isArray(state?.activities) &&
    state.activities.some((candidate) => candidate.cmid === cmid);

/**
 * Whether an address is the activity settings page of this Moodle site, the only kind the host opens for the editor.
 *
 * Moodle builds the address; this is a second check on what came back, so a wrong answer can never send
 * the editor to another site or another page.
 *
 * @param {string} href
 * @param {{wwwroot: string}} site
 * @returns {?string} The normalised address, or null when it must not be opened.
 */
export const resolveSettingsUrl = (href, {wwwroot}) => {
    try {
        const root = new URL(wwwroot);
        const url = new URL(href);
        const isSettingsPage = url.pathname === `${rootPath(root)}/course/modedit.php` &&
            /^[1-9]\d*$/.test(url.searchParams.get('update') ?? '');
        return ['http:', 'https:'].includes(url.protocol) && url.origin === root.origin && isSettingsPage ? url.href : null;
    } catch (error) {
        return null;
    }
};

/**
 * Whether a URL is the view page of a course (where "Continue" and "Back to course" lead).
 *
 * @param {string} href
 * @param {{wwwroot: string, courseId: number}} site
 * @returns {boolean}
 */
export const isCourseViewUrl = (href, {wwwroot, courseId}) => {
    try {
        const root = new URL(wwwroot);
        const url = new URL(href);
        return url.origin === root.origin &&
            url.pathname === `${rootPath(root)}/course/view.php` &&
            url.searchParams.get('id') === String(courseId);
    } catch (error) {
        return false;
    }
};

/**
 * Validates a message sent by the activity page inside the popup.
 *
 * @param {{origin: string, source: *, data: *}} event
 * @param {{siteOrigin: string, frameWindow: *}} expected The popup frame's own window on this site.
 * @returns {?string} The message type, or null when the event must be ignored.
 */
export const parsePopupMessage = (event, {siteOrigin, frameWindow}) => {
    if (!event || !frameWindow || event.origin !== siteOrigin || event.source !== frameWindow) {
        return null;
    }
    const data = event.data;
    if (!isPlainObject(data) || data.v !== PROTOCOL_VERSION || !Object.values(POPUP_MESSAGE).includes(data.type)) {
        return null;
    }
    return data.type;
};
