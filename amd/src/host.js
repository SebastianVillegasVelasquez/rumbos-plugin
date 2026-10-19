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
 * state, and falls back to the standard course content when the app does not
 * answer.
 *
 * @module     format_rumbos/host
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import {getString} from 'core/str';
import {
    MESSAGE,
    findActivityUrl,
    hostInit,
    hostLaunch,
    hostState,
    parseAppMessage,
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

class RumbosHost {
    /**
     * @param {Object} params
     * @param {number} params.courseId
     * @param {string} params.appOrigin Origin the iframe is expected to talk from.
     * @param {string} params.mode "student" or "editor".
     * @param {string} params.locale BCP 47 language tag.
     * @param {number} params.timeoutMs How long to wait for embed-ready.
     */
    constructor({courseId, appOrigin, mode, locale, timeoutMs}) {
        this.courseId = courseId;
        this.appOrigin = appOrigin;
        this.mode = mode;
        this.locale = locale;
        this.timeoutMs = timeoutMs;
        this.siteOrigin = window.location.origin;

        this.state = null;
        this.degraded = false;
        this.fullscreen = false;
        this.timer = null;
        this.scrollY = 0;
        this.returnFocus = null;

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

        window.addEventListener('message', this.onMessage);
        this.exitButton?.addEventListener('click', () => this.setFullscreen(false));
        this.timer = window.setTimeout(() => this.degrade('timeout'), this.timeoutMs);
    }

    /**
     * Handles a message event; anything not coming from the app is ignored.
     *
     * @param {MessageEvent} event
     */
    onMessage(event) {
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
                this.refreshState().then((state) => this.send(hostState(state))).catch(() => this.degrade('error'));
                break;
            case MESSAGE.REQUEST_LAUNCH:
                this.fetchAssertion().then((assertion) => this.send(hostLaunch(assertion)))
                    .catch(() => this.degrade('error'));
                break;
            case MESSAGE.OPEN_ACTIVITY:
                this.openActivity(message.cmid);
                break;
            case MESSAGE.REQUEST_FULLSCREEN:
                this.setFullscreen(message.on);
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
            .then(([state, assertion]) => this.send(hostInit({
                mode: this.mode,
                locale: this.locale,
                courseId: this.courseId,
                assertion,
                state,
            })))
            .catch(() => this.degrade('error'));
    }

    /**
     * Fetches the current user's course state and remembers it.
     *
     * @returns {Promise<Object>}
     */
    refreshState() {
        return Ajax.call([{methodname: 'format_rumbos_get_course_state', args: {courseid: this.courseId}}])[0]
            .then((state) => {
                this.state = state;
                return state;
            });
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
     * Opens an activity in the same tab, from the host's own last state only.
     *
     * @param {number} cmid
     */
    openActivity(cmid) {
        const url = findActivityUrl(this.state, cmid, this.siteOrigin);
        if (url) {
            window.location.assign(url);
        }
    }

    /**
     * Toggles the full screen overlay (CSS based: iOS Safari has no Fullscreen API for arbitrary elements).
     *
     * @param {boolean} on
     */
    setFullscreen(on) {
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
     * @param {string} reason "timeout" or "error"
     */
    degrade(reason) {
        if (this.degraded) {
            return;
        }
        this.degraded = true;
        this.clearTimer();
        this.setFullscreen(false);
        window.removeEventListener('message', this.onMessage);

        this.frameWrap?.classList.add(CLASSES.HIDDEN);
        this.frame.removeAttribute('src');

        if (this.activityList?.tagName === 'DETAILS') {
            this.activityList.open = true;
        }

        if (this.mode === 'editor' && this.notice) {
            getString(reason === 'timeout' ? 'fallback_timeout' : 'fallback_error', 'format_rumbos')
                .then((text) => {
                    this.notice.textContent = text;
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
