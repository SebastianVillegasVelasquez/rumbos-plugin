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
 * Host side of the Rumbos iframe: relays messages between Moodle and the app.
 *
 * It contains no Rumbos logic. It verifies who is talking, answers with data
 * computed by Moodle for the current user, opens activities from its own last
 * state (in a popup over the map, or in a new tab), and falls back to the standard course content when the app does not
 * answer. It reads the state again, one request at a time, whenever completion can have changed.
 *
 * @module     format_rumbos/host
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Log from 'core/log';
import {getString, getStrings} from 'core/str';
import ActivityPopup from 'format_rumbos/popup';
import {createRefresher} from 'format_rumbos/refresher';
import {
    MESSAGE,
    hostFullscreen,
    hostInit,
    hostLaunch,
    hostOverlay,
    hostState,
    isCourseViewUrl,
    parseAppMessage,
    parsePopupMessage,
    resolveActivityTarget,
} from 'format_rumbos/protocol';

const SELECTORS = {
    CONTAINER: '[data-region="rumbos-container"]',
    FRAME: '[data-region="rumbos-frame"]',
    FRAME_WRAP: '[data-region="rumbos-frame-wrap"]',
    NOTICE: '[data-region="rumbos-notice"]',
    ACTIVITY_LIST: '[data-region="rumbos-activity-list"]',
    EXIT_FULLSCREEN: '[data-action="rumbos-exit-fullscreen"]',
};

const CLASSES = {
    FULLSCREEN: 'format-rumbos-fullscreen',
    BODY_FULLSCREEN: 'format-rumbos-fullscreen-open',
    HIDDEN: 'd-none',
};

/** A burst of navigations in the activity popup is refreshed once, this long after the last one (ms). */
const NAVIGATION_DEBOUNCE_MS = 400;

/** Minimum time between two refreshes caused by the activity popup navigating (ms). */
const NAVIGATION_REFRESH_MS = 2000;

class RumbosHost {
    /**
     * @param {Object} params
     * @param {number} params.courseId
     * @param {string} params.appOrigin Origin the iframe is expected to talk from.
     * @param {string} params.mode "student" or "editor".
     * @param {string} params.locale BCP 47 language tag.
     * @param {number} params.timeoutMs How long to wait for embed-ready.
     * @param {string} params.wwwroot Moodle's wwwroot, the base of every URL the host may open.
     * @param {string} params.popupFrameName Name of the activity popup frame.
     * @param {string[]} params.newTabModules Modules whose activities open in a new tab.
     */
    constructor({courseId, appOrigin, mode, locale, timeoutMs, wwwroot, popupFrameName, newTabModules}) {
        this.courseId = courseId;
        this.appOrigin = appOrigin;
        this.mode = mode;
        this.locale = locale;
        this.timeoutMs = timeoutMs;
        this.siteOrigin = window.location.origin;
        this.wwwroot = wwwroot;
        this.popupFrameName = popupFrameName;
        this.newTabModules = newTabModules;

        this.state = null;
        this.degraded = false;
        this.fullscreen = false;
        this.timer = null;
        this.scrollY = 0;
        this.returnFocus = null;
        this.popup = null;
        this.strings = {back: 'Back to the map', frameTitle: '{$a}'};
        this.overlayToken = 0;
        this.lastPushed = '';
        // Every read of the state goes through this: one request at a time, whoever asks.
        this.refresher = createRefresher({
            fetch: () => this.fetchState(),
            onState: (state) => this.onStateFetched(state),
            onError: (error) => Log.debug(`format_rumbos/host: ${error?.message ?? error}`),
            debounceMs: NAVIGATION_DEBOUNCE_MS,
            minIntervalMs: NAVIGATION_REFRESH_MS,
        });

        this.onMessage = this.onMessage.bind(this);
        this.onKeydown = this.onKeydown.bind(this);
    }

    /**
     * Wires the DOM and starts waiting for the app.
     */
    start() {
        this.container = document.querySelector(SELECTORS.CONTAINER);
        this.frame = this.container?.querySelector(SELECTORS.FRAME);
        if (!this.container || !this.frame) {
            return;
        }
        this.frameWrap = this.container.querySelector(SELECTORS.FRAME_WRAP);
        this.notice = this.container.querySelector(SELECTORS.NOTICE);
        this.exitButton = this.container.querySelector(SELECTORS.EXIT_FULLSCREEN);
        this.activityList = document.querySelector(SELECTORS.ACTIVITY_LIST);

        this.setState('waiting');
        getStrings([
            {key: 'backtomap', component: 'format_rumbos'},
            {key: 'popupframetitle', component: 'format_rumbos', param: '{$a}'},
        ]).then(([back, frameTitle]) => {
            this.strings = {back, frameTitle};
            return true;
        }).catch(() => false);
        window.addEventListener('message', this.onMessage);
        // A page restored from the back/forward cache would keep the mode and state of the
        // moment it was left (editing mode is toggled by reloading the page): start afresh.
        window.addEventListener('pageshow', (event) => {
            if (event.persisted) {
                window.location.reload();
            }
        });
        this.exitButton?.addEventListener('click', () => this.setFullscreen(false));
        this.timer = window.setTimeout(() => this.degrade('timeout'), this.timeoutMs);

        // The iframe is in the page HTML, so it can finish loading and send embed-ready
        // before this module is ready to listen, and that message would be lost for good.
        // Restarting the navigation now guarantees the app announces itself to a host that
        // is already listening. It is the original src again: nothing is read or changed.
        this.frame.src = this.frame.getAttribute('src');
    }

    /**
     * Handles a message event; anything not coming from the app is ignored.
     *
     * @param {MessageEvent} event
     */
    onMessage(event) {
        if (this.popup?.isOpen) {
            const popupMessage = parsePopupMessage(event, {siteOrigin: this.siteOrigin, frameWindow: this.popup.frameWindow});
            if (popupMessage) {
                this.closePopup();
                return;
            }
        }
        const message = parseAppMessage(event, {appOrigin: this.appOrigin, frameWindow: this.frame.contentWindow});
        if (!message) {
            return;
        }

        switch (message.type) {
            case MESSAGE.EMBED_READY:
                this.clearTimer();
                this.init();
                break;
            case MESSAGE.REQUEST_STATE:
                this.refreshState().then((state) => this.pushState(state)).catch((error) => this.fail(error));
                break;
            case MESSAGE.REQUEST_LAUNCH:
                this.fetchAssertion().then((assertion) => this.send(hostLaunch(assertion)))
                    .catch((error) => this.fail(error));
                break;
            case MESSAGE.OPEN_ACTIVITY:
                this.openActivity(message.cmid);
                break;
            case MESSAGE.REQUEST_FULLSCREEN:
                // The app follows the host's state: reply with what actually happened (false when denied).
                this.setFullscreen(message.on, false);
                this.send(hostFullscreen(this.fullscreen));
                break;
            case MESSAGE.ERROR:
                this.degrade('apperror', message.code, message.detail);
                break;
        }
    }

    /**
     * Answers embed-ready with the first state and a fresh assertion.
     *
     * @returns {Promise}
     */
    init() {
        return Promise.all([this.refreshState(), this.fetchAssertion()])
            .then(([state, assertion]) => {
                this.send(hostInit({
                    mode: this.mode,
                    locale: this.locale,
                    courseId: this.courseId,
                    assertion,
                    state,
                }));
                this.setState('ready');
                return true;
            })
            .catch((error) => this.fail(error));
    }

    /**
     * Reads the current user's course state, joining the read already in flight if there is one.
     *
     * @returns {Promise<Object>}
     */
    refreshState() {
        return this.refresher.load();
    }

    /**
     * The request itself; use refreshState(), which never runs two at once.
     *
     * @returns {Promise<Object>}
     */
    fetchState() {
        return Ajax.call([{methodname: 'format_rumbos_get_course_state', args: {courseid: this.courseId}}])[0];
    }

    /**
     * Remembers a state that was just read and, while the activity popup is open, tells the app if it changed.
     *
     * The app is not looking at the map then, but it keeps the progress it will show when the popup closes.
     *
     * @param {Object} state
     */
    onStateFetched(state) {
        this.state = state;
        if (this.popup?.isOpen && this.signature(state) !== this.lastPushed) {
            this.pushState(state);
        }
    }

    /**
     * Sends the app a state and remembers what it has been told.
     *
     * @param {Object} state
     */
    pushState(state) {
        this.lastPushed = this.signature(state);
        this.send(hostState(state));
    }

    /**
     * What identifies a state apart from the time it was generated.
     *
     * @param {Object} state
     * @returns {string}
     */
    signature(state) {
        return JSON.stringify({...state, generatedAt: undefined});
    }

    /**
     * Fetches a fresh launch assertion.
     *
     * @returns {Promise<string>}
     */
    fetchAssertion() {
        return Ajax.call([{methodname: 'format_rumbos_get_launch_assertion', args: {courseid: this.courseId}}])[0]
            .then((result) => result.assertion);
    }

    /**
     * Posts a message to the app, always with an explicit target origin.
     *
     * @param {Object} message
     */
    send(message) {
        if (!this.degraded && this.frame.contentWindow) {
            this.frame.contentWindow.postMessage(message, this.appOrigin);
        }
    }

    /**
     * Opens an activity from the host's own last state only: in the popup over the map, or in a new tab.
     *
     * @param {number} cmid
     */
    openActivity(cmid) {
        if (this.popup?.isOpen) {
            return;
        }
        const target = resolveActivityTarget(this.state, cmid, {
            siteOrigin: this.siteOrigin,
            wwwroot: this.wwwroot,
            newTabModules: this.newTabModules,
        });
        if (!target) {
            return;
        }
        if (target.target === 'tab') {
            // The click that started this happened in the app's frame; browsers pass that activation to
            // this window. Where they do not (Safari), the activity opens here rather than not at all.
            if (!window.open(target.url, '_blank', 'noopener')) {
                window.location.assign(target.url);
            }
            return;
        }
        this.openPopup(target);
    }

    /**
     * Opens the activity popup and tells the app an overlay is above it.
     *
     * @param {{url: string, title: string}} target
     */
    openPopup({url, title}) {
        if (!this.popup) {
            this.popup = this.createPopup();
        }
        this.overlayToken += 1;
        this.popup.open(url, title, this.frame);
        this.send(hostOverlay(true));
    }

    /**
     * @returns {ActivityPopup}
     */
    createPopup() {
        return new ActivityPopup({
            frameName: this.popupFrameName,
            backLabel: this.strings.back,
            frameTitle: (title) => this.strings.frameTitle.replace('{$a}', () => title),
            onClose: () => this.closePopup(),
            onLoad: () => this.onPopupLoad(),
        });
    }

    /**
     * The activity frame finished a navigation: leave if it went to the course page, else refresh the progress.
     *
     * Completion can change on any page of the activity (viewing it, a submission, the review page that
     * follows a quiz), so every navigation asks for a refresh; the refresher debounces and spaces them.
     */
    onPopupLoad() {
        const href = this.popup?.currentHref();
        if (href && isCourseViewUrl(href, {wwwroot: this.wwwroot, courseId: this.courseId})) {
            // "Continue" and "Back to course" end here: the map is already behind the popup.
            this.closePopup();
            return;
        }
        this.refresher.schedule();
    }

    /**
     * Closes the popup, then gives the app the refreshed state and tells it the overlay is gone.
     */
    closePopup() {
        if (!this.popup?.isOpen) {
            return;
        }
        this.popup.close();
        // Nothing is read on a timer while no overlay is open; the close has its own read below.
        this.refresher.cancel();
        this.finishClose(this.overlayToken);
    }

    /**
     * After closing: the refreshed state first, then the end of the overlay.
     *
     * @param {number} token Which popup was closed.
     * @returns {Promise}
     */
    async finishClose(token) {
        try {
            // Not a read that began before the close: the last page of the activity may have changed it.
            this.pushState(await this.refresher.flush());
        } catch (error) {
            Log.debug(`format_rumbos/host: ${error?.message ?? error}`);
        }
        // Another popup may have been opened while the state was loading: that one owns the overlay flag.
        if (token === this.overlayToken) {
            this.send(hostOverlay(false));
        }
    }

    /**
     * Toggles the full screen overlay (CSS based: iOS Safari has no Fullscreen API for arbitrary elements).
     *
     * @param {boolean} on
     * @param {boolean} notify Tell the app the new state (every change is reported, whoever caused it).
     */
    setFullscreen(on, notify = true) {
        if (on === this.fullscreen || !this.frameWrap) {
            return;
        }
        this.fullscreen = on;

        if (on) {
            this.returnFocus = document.activeElement;
            this.scrollY = window.scrollY;
            this.frameWrap.classList.add(CLASSES.FULLSCREEN);
            this.frameWrap.setAttribute('role', 'dialog');
            this.frameWrap.setAttribute('aria-modal', 'true');
            this.frameWrap.setAttribute('aria-label', this.frame.getAttribute('title'));
            document.body.classList.add(CLASSES.BODY_FULLSCREEN);
            if (this.exitButton) {
                this.exitButton.hidden = false;
            }
            document.addEventListener('keydown', this.onKeydown);
            this.frame.focus();
        } else {
            document.removeEventListener('keydown', this.onKeydown);
            this.frameWrap.classList.remove(CLASSES.FULLSCREEN);
            this.frameWrap.removeAttribute('role');
            this.frameWrap.removeAttribute('aria-modal');
            this.frameWrap.removeAttribute('aria-label');
            document.body.classList.remove(CLASSES.BODY_FULLSCREEN);
            if (this.exitButton) {
                this.exitButton.hidden = true;
            }
            window.scrollTo(0, this.scrollY);
            if (this.returnFocus && document.contains(this.returnFocus)) {
                this.returnFocus.focus({preventScroll: true});
            }
            this.returnFocus = null;
        }

        if (notify) {
            this.send(hostFullscreen(on));
        }
    }

    /**
     * Escape leaves full screen when the focus is in the host page.
     *
     * @param {KeyboardEvent} event
     */
    onKeydown(event) {
        if (event.key === 'Escape') {
            this.setFullscreen(false);
        }
    }

    /**
     * A host-side failure (an external function or the reply): log it and fall back.
     *
     * @param {Error} error
     */
    fail(error) {
        Log.debug(`format_rumbos/host: ${error?.message ?? error}`);
        this.degrade('error');
    }

    /**
     * Publishes the host state on the container: waiting, ready or degraded (with the reason).
     * It is for debugging and for tests; nothing else reads it.
     *
     * @param {string} state
     * @param {string} reason
     */
    setState(state, reason = '') {
        this.container.dataset.state = state;
        if (reason) {
            this.container.dataset.reason = reason;
        }
    }

    clearTimer() {
        if (this.timer !== null) {
            window.clearTimeout(this.timer);
            this.timer = null;
        }
    }

    /**
     * Gives up on the app: hides the iframe and shows the standard content.
     *
     * Students are never left on a blank page; teachers also get a notice
     * explaining what happened.
     *
     * @param {string} reason "timeout", "error" (a host call failed) or "apperror" (the app reported an error)
     * @param {string} code Error code reported by the app, for "apperror".
     * @param {string} detail Untrusted text reported by the app; only ever rendered with textContent.
     */
    degrade(reason, code = '', detail = '') {
        if (this.degraded) {
            return;
        }
        this.degraded = true;
        this.setState('degraded', reason === 'apperror' ? code : reason);
        this.clearTimer();
        this.refresher.cancel();
        this.setFullscreen(false);
        window.removeEventListener('message', this.onMessage);

        this.frameWrap?.classList.add(CLASSES.HIDDEN);
        this.frame.removeAttribute('src');

        if (this.activityList?.tagName === 'DETAILS') {
            this.activityList.open = true;
        }

        if (this.mode === 'editor' && this.notice) {
            const request = reason === 'apperror' ?
                getString('fallback_apperror', 'format_rumbos', code) :
                getString(reason === 'timeout' ? 'fallback_timeout' : 'fallback_error', 'format_rumbos');
            request
                .then((text) => {
                    this.notice.textContent = detail ? `${text} ${detail}` : text;
                    this.notice.classList.remove(CLASSES.HIDDEN);
                    return text;
                })
                .catch(() => {
                    this.notice.classList.remove(CLASSES.HIDDEN);
                });
        }
    }
}

/**
 * Starts the host for the Rumbos container on the page.
 *
 * @param {Object} params See RumbosHost.
 */
export const init = (params) => {
    new RumbosHost(params).start();
};
