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
import {addNotification} from 'core/notification';
import {getString, getStrings} from 'core/str';
import ActivityPopup from 'format_rumbos/popup';
import {createPoller} from 'format_rumbos/poller';
import {createRefresher} from 'format_rumbos/refresher';
import {exitScorm} from 'format_rumbos/safe_exit';
import {
    MESSAGE,
    POPUP_MESSAGE,
    hostFullscreen,
    hostInit,
    hostLaunch,
    hostOverlay,
    hasActivity,
    hostState,
    isCourseViewUrl,
    parseAppMessage,
    parsePopupMessage,
    resolveActivityTarget,
    resolveSettingsUrl,
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
    ANIMATE: 'format-rumbos-animate',
};

/** Custom property the stylesheet reads for the height of the area in automatic mode (see styles.css). */
const AUTO_HEIGHT_PROPERTY = '--format-rumbos-auto-height';

/** The width below which the area takes a percentage of the screen height instead (the stylesheet's breakpoint). */
const SMALL_SCREEN_QUERY = '(max-width: 767.98px)';

/** A burst of navigations in the activity popup is refreshed once, this long after the last one (ms). */
const NAVIGATION_DEBOUNCE_MS = 400;

/** Minimum time between two refreshes caused by the activity popup navigating (ms). */
const NAVIGATION_REFRESH_MS = 2000;

/** Modules whose popup commits what the package reported before it closes (see safe_exit.js). */
const SAFE_EXIT_MODULES = ['scorm'];

/** How often an open popup of a module that reports without navigating re-reads the state (ms). */
const POLL_INTERVAL_MS = 15000;

class RumbosHost {
    /**
     * @param {Object} params
     * @param {number} params.courseId
     * @param {string} [params.courseName] The course full name as plain text, for the app's welcome screen.
     * @param {string} params.appOrigin Origin the iframe is expected to talk from.
     * @param {string} params.mode "student" or "editor".
     * @param {{mode: string, min: number, max: number}} [params.size] Height mode of the area and the site limits (px).
     * @param {boolean} [params.canEdit] Whether the user may edit the map (the notices about an empty map are for them).
     * @param {string} params.locale BCP 47 language tag.
     * @param {number} params.timeoutMs How long to wait for embed-ready.
     * @param {string} params.wwwroot Moodle's wwwroot, the base of every URL the host may open.
     * @param {string} params.popupFrameName Name of the activity popup frame.
     * @param {string[]} params.newTabModules Modules whose activities open in a new tab.
     * @param {string[]} [params.pollModules] Modules whose open popup also re-reads the state on an interval.
     * @param {string} [params.popupHeaderSelector] The site header the popup sits below, or empty for the whole window.
     * @param {boolean} [params.popupSession] Whether to mark the session for the popup (the activity pages drop the site chrome).
     */
    constructor({
        courseId, courseName = '', appOrigin, mode, size = {mode: 'viewport', min: 0, max: Infinity}, canEdit = false,
        locale, timeoutMs, wwwroot, popupFrameName, newTabModules, pollModules = [], popupHeaderSelector = '', popupSession = true,
    }) {
        this.courseId = courseId;
        this.courseName = courseName;
        this.appOrigin = appOrigin;
        this.mode = mode;
        this.canEdit = canEdit;
        this.size = size;
        this.aspectRatio = null;
        this.resizeObserver = null;
        this.locale = locale;
        this.timeoutMs = timeoutMs;
        this.siteOrigin = window.location.origin;
        this.wwwroot = wwwroot;
        this.popupFrameName = popupFrameName;
        this.newTabModules = newTabModules;
        this.pollModules = pollModules;
        this.popupHeaderSelector = popupHeaderSelector;
        this.popupSession = popupSession;
        this.popupPending = false;
        this.openModname = '';
        this.closing = false;

        this.state = null;
        this.degraded = false;
        this.fullscreen = false;
        this.timer = null;
        this.scrollY = 0;
        this.returnFocus = null;
        this.popup = null;
        this.strings = {back: 'Back to the map', frameTitle: '{$a}', settingsBlocked: ''};
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

        // The same single-flight reader, so a poll that meets a navigation refresh joins it.
        this.poller = createPoller({
            tick: () => this.refresher.load().catch((error) => Log.debug(`format_rumbos/host: ${error?.message ?? error}`)),
            intervalMs: POLL_INTERVAL_MS,
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
            {key: 'settingsblocked', component: 'format_rumbos'},
        ]).then(([back, frameTitle, settingsBlocked]) => {
            this.strings = {back, frameTitle, settingsBlocked};
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
        document.addEventListener('fullscreenchange', () => this.onNativeFullscreenChange());
        this.timer = window.setTimeout(() => this.degrade('timeout'), this.timeoutMs);
        this.watchWidth();

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
                // Escape is the user leaving the activity; "leave" is the frame already gone from it.
                if (popupMessage === POPUP_MESSAGE.ESCAPE) {
                    this.requestClose();
                } else {
                    this.closePopup();
                }
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
            case MESSAGE.OPEN_ACTIVITY_SETTINGS:
                this.openActivitySettings(message.cmid);
                break;
            case MESSAGE.REQUEST_FULLSCREEN:
                // The app follows the host's state: reply with what actually happened (false when denied).
                this.setFullscreen(message.on, false);
                this.send(hostFullscreen(this.fullscreen));
                break;
            case MESSAGE.ERROR:
                this.degrade('apperror', message.code, message.detail);
                break;
            case MESSAGE.PREFERRED_SIZE:
                this.setPreferredSize(message.aspectRatio);
                break;
            case MESSAGE.NO_CONTENT:
                this.degrade('nocontent', message.reason);
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
                this.lastPushed = this.signature(state);
                this.send(hostInit({
                    mode: this.mode,
                    locale: this.locale,
                    courseId: this.courseId,
                    courseName: this.courseName,
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
        if (this.popup?.isOpen || this.popupPending) {
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
        this.openPopup({...target, cmid});
    }

    /**
     * Opens an activity's settings page (where its completion is configured) in a new tab, for the map editor only.
     *
     * The app only names the activity. Whether the user may configure it, and the address, come from Moodle.
     * Anything that is not editor mode, or not in the host's own last state, is ignored without an answer.
     *
     * @param {number} cmid
     */
    openActivitySettings(cmid) {
        if (this.mode !== 'editor' || !hasActivity(this.state, cmid)) {
            return;
        }
        Ajax.call([{methodname: 'format_rumbos_get_activity_settings_url', args: {courseid: this.courseId, cmid}}])[0]
            .then(({url}) => {
                const target = resolveSettingsUrl(url, {wwwroot: this.wwwroot});
                if (target && !window.open(target, '_blank', 'noopener')) {
                    // The new tab was blocked. Leaving this page could lose unsaved work in the map editor.
                    return addNotification({message: this.strings.settingsBlocked, type: 'warning'});
                }
                return null;
            })
            .catch((error) => Log.debug(`format_rumbos/host: ${error?.message ?? error}`));
    }

    /**
     * Marks the session as having a popup open, then shows the popup.
     *
     * The marker is what lets the activity page drop the site chrome; it has to be stored before the
     * frame requests the page. If it cannot be stored the popup still opens, only with the chrome.
     *
     * @param {{url: string, title: string, modname: string, cmid: number}} target
     */
    async openPopup(target) {
        if (!this.popup) {
            this.popup = this.createPopup();
        }
        this.overlayToken += 1;
        const token = this.overlayToken;
        if (this.popupSession) {
            this.popupPending = true;
            try {
                await Ajax.call([{methodname: 'format_rumbos_start_popup_session', args: {cmid: target.cmid}}])[0];
            } catch (error) {
                Log.debug(`format_rumbos/host: ${error?.message ?? error}`);
            }
            this.popupPending = false;
        }
        if (token === this.overlayToken) {
            this.showPopup(target);
        }
    }

    /**
     * Shows the popup and tells the app an overlay is above it.
     *
     * @param {{url: string, title: string, modname: string}} target
     */
    showPopup({url, title, modname}) {
        // Anything outside the element that is in the browser's full screen is not shown, so the popup goes
        // inside it. Opening an activity then does not end the full screen, and neither does closing it.
        const inNativeFullscreen = document.fullscreenElement && document.fullscreenElement === this.frameWrap;
        this.openModname = modname;
        this.popup.open(url, title, this.frame, inNativeFullscreen ? this.frameWrap : document.body, {
            headerSelector: inNativeFullscreen ? '' : this.popupHeaderSelector,
        });
        this.send(hostOverlay(true));
        if (this.pollModules.includes(modname)) {
            // A package reporting inside its own frame loads no page, so nothing else would notice it.
            this.poller.start();
        }
    }

    /**
     * @returns {ActivityPopup}
     */
    createPopup() {
        return new ActivityPopup({
            frameName: this.popupFrameName,
            backLabel: this.strings.back,
            frameTitle: (title) => this.strings.frameTitle.replace('{$a}', () => title),
            onClose: () => this.requestClose(),
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
     * The user asked to leave: save what the activity holds first, where that is needed, then close.
     *
     * For a SCORM package the player's adapter is told to store the package's values (see safe_exit.js).
     * Whatever happens, the popup closes: a failure is logged, never shown as a stuck dialog.
     *
     * @returns {Promise}
     */
    async requestClose() {
        if (!this.popup?.isOpen || this.closing) {
            return;
        }
        if (!SAFE_EXIT_MODULES.includes(this.openModname)) {
            this.closePopup();
            return;
        }
        this.closing = true;
        const token = this.overlayToken;
        try {
            const result = await exitScorm(this.popup.frameWindow);
            if (!result.ok) {
                Log.warn(`format_rumbos/host: the activity did not confirm the save before closing (${result.reason})`);
            }
        } catch (error) {
            Log.warn(`format_rumbos/host: leaving the activity failed (${error?.message ?? error})`);
        }
        this.closing = false;
        // The frame may have left the activity while the commit ran, which already closed the popup.
        if (token === this.overlayToken) {
            this.closePopup();
        }
    }

    /**
     * Closes the popup, then gives the app the refreshed state and tells it the overlay is gone.
     */
    closePopup() {
        if (!this.popup?.isOpen) {
            return;
        }
        this.popup.close();
        if (this.popupSession) {
            Ajax.call([{methodname: 'format_rumbos_end_popup_session', args: {}}])[0]
                .catch((error) => Log.debug(`format_rumbos/host: ${error?.message ?? error}`));
        }
        this.poller.stop();
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
     * Follows the width of the container, so that in automatic mode the height keeps the proportions of the level.
     *
     * The stylesheet ignores the height set here on small screens and in full screen, so nothing is undone when
     * those start or end.
     */
    watchWidth() {
        if (this.size.mode !== 'auto' || typeof ResizeObserver === 'undefined') {
            return;
        }
        this.lastWidth = this.container.getBoundingClientRect().width;
        this.resizeObserver = new ResizeObserver(() => {
            // Setting the height also resizes the container: only a change of width needs a new height.
            const width = this.container.getBoundingClientRect().width;
            if (width !== this.lastWidth) {
                this.lastWidth = width;
                this.updateAutoHeight(false);
            }
        });
        this.resizeObserver.observe(this.container);
    }

    /**
     * The app says which proportions the level on display has (contract v5.5).
     *
     * It only matters in automatic mode; in any other mode the course settings decide and it is ignored.
     *
     * @param {number} aspectRatio Width divided by height, already validated.
     */
    setPreferredSize(aspectRatio) {
        if (this.size.mode !== 'auto' || this.degraded || aspectRatio === this.aspectRatio) {
            return;
        }
        this.aspectRatio = aspectRatio;
        this.updateAutoHeight(true);
    }

    /**
     * Sets the height of the area from its width and the proportions of the level, within the site limits.
     *
     * @param {boolean} animate Whether the change is a different level (animated) or only a resize (immediate).
     */
    updateAutoHeight(animate) {
        if (!this.aspectRatio || !this.frameWrap) {
            return;
        }
        const width = this.container.getBoundingClientRect().width;
        const target = Math.round(Math.min(this.size.max, Math.max(this.size.min, width / this.aspectRatio)));
        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const visible = !window.matchMedia(SMALL_SCREEN_QUERY).matches && !this.fullscreen;
        if (animate && visible && !reducedMotion) {
            if (!this.container.style.getPropertyValue(AUTO_HEIGHT_PROPERTY)) {
                // The reserved box has no height to animate from: start where it is.
                this.container.style.setProperty(AUTO_HEIGHT_PROPERTY, `${this.frameWrap.getBoundingClientRect().height}px`);
                this.frameWrap.getBoundingClientRect();
            }
            this.frameWrap.classList.add(CLASSES.ANIMATE);
        } else {
            this.frameWrap.classList.remove(CLASSES.ANIMATE);
        }
        this.container.style.setProperty(AUTO_HEIGHT_PROPERTY, `${target}px`);
    }

    /**
     * Toggles the full screen mode and, where the browser allows it, also the browser's own full screen.
     *
     * The mode is a CSS overlay that always works (iPhone Safari has no Fullscreen API for arbitrary
     * elements, and any browser may refuse the request), so a refused native request changes nothing
     * the app can see. While it is on, the host's exit control owns the top-right corner (styles.css).
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
            this.requestNativeFullscreen();
        } else {
            document.removeEventListener('keydown', this.onKeydown);
            this.leaveNativeFullscreen();
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
     * Asks the browser for its own full screen on the wrapper. A refusal is fine: the overlay stays.
     */
    requestNativeFullscreen() {
        // A popup already open in the body would be hidden by the browser's full screen; the overlay will do.
        if (typeof this.frameWrap.requestFullscreen !== 'function' || document.fullscreenElement || this.popup?.isOpen) {
            return;
        }
        try {
            Promise.resolve(this.frameWrap.requestFullscreen({navigationUI: 'hide'}))
                .catch((error) => Log.debug(`format_rumbos/host: ${error?.message ?? error}`));
        } catch (error) {
            Log.debug(`format_rumbos/host: ${error?.message ?? error}`);
        }
    }

    /**
     * Leaves the browser's own full screen if the wrapper is in it.
     */
    leaveNativeFullscreen() {
        if (document.fullscreenElement && document.fullscreenElement === this.frameWrap) {
            document.exitFullscreen().catch((error) => Log.debug(`format_rumbos/host: ${error?.message ?? error}`));
        }
    }

    /**
     * The browser's full screen ended. If the user did it (the browser handles Escape itself there and
     * the page never sees the key), the mode ends too, and the app is told.
     */
    onNativeFullscreenChange() {
        if (document.fullscreenElement || !this.fullscreen) {
            return;
        }
        // An open popup stays where it is: it is a fixed dialog, so it keeps showing without the full screen
        // (moving its frame to the body would reload the activity, and a quiz attempt would be lost).
        this.setFullscreen(false);
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
     * explaining what happened. A second call does nothing, so a repeated message from the app is ignored.
     *
     * @param {string} reason "timeout", "error" (a host call failed), "apperror" (the app reported an error)
     *      or "nocontent" (the app has nothing to show this user)
     * @param {string} code Error code reported by the app, for "apperror"; the reason it gave, for "nocontent".
     * @param {string} detail Untrusted text reported by the app; only ever rendered with textContent.
     */
    degrade(reason, code = '', detail = '') {
        if (this.degraded) {
            return;
        }
        this.degraded = true;
        this.setState('degraded', ['apperror', 'nocontent'].includes(reason) ? code : reason);
        this.clearTimer();
        this.refresher.cancel();
        this.poller.stop();
        this.resizeObserver?.disconnect();
        this.setFullscreen(false);
        window.removeEventListener('message', this.onMessage);

        this.frameWrap?.classList.add(CLASSES.HIDDEN);
        this.frame.removeAttribute('src');

        if (this.activityList?.tagName === 'DETAILS') {
            this.activityList.open = true;
        }

        // The notice about an empty map is for everyone who can edit it, whatever the editing switch says:
        // it tells them what to do. The others are for the map editor, who was looking at the app.
        const notified = reason === 'nocontent' ? this.canEdit : this.mode === 'editor';
        if (notified && this.notice) {
            this.showNotice(reason, code)
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

    /**
     * The text that explains to a user who can edit why the app was replaced by the standard content.
     *
     * @param {string} reason See degrade().
     * @param {string} code See degrade().
     * @returns {Promise<string>}
     */
    showNotice(reason, code) {
        switch (reason) {
            case 'apperror':
                return getString('fallback_apperror', 'format_rumbos', code);
            case 'nocontent':
                return getString(code === 'empty_map' ? 'fallback_emptymap' : 'fallback_nomap', 'format_rumbos');
            case 'timeout':
                return getString('fallback_timeout', 'format_rumbos');
            default:
                return getString('fallback_error', 'format_rumbos');
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
