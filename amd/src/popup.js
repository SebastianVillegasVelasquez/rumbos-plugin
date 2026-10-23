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
 * The activity popup: a modal dialog the host opens over the map, framing a Moodle activity page.
 *
 * It lives in the Moodle page (the parent of the Rumbos iframe), never inside the
 * Rumbos iframe: a Moodle frame nested under a cross-site ancestor risks
 * third-party-cookie treatment and a login screen.
 *
 * It only builds DOM, manages focus and reports events; deciding what to open
 * and what to tell the app is the host's job. The host says where to mount it: the page body, or
 * the element that is in the browser's full screen (anything outside that element is not shown).
 *
 * @module     format_rumbos/popup
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const CLASSES = {
    ROOT: 'format-rumbos-popup',
    BODY_OPEN: 'format-rumbos-popup-open',
};

/*
 * The activity frame has NO sandbox attribute, on purpose:
 *  - Chrome refuses to show a PDF inside any sandboxed frame (a broken-page icon), and
 *    a file activity is very often a PDF. Verified in Chrome; see docs/DESIGN-activity-popups.md.
 *  - The sandbox would add nothing here. The framed page is same-origin Moodle and
 *    needs scripts and same-origin, and a sandbox that allows both gives the page no
 *    isolation (it can lift the sandbox itself). The real guard is the URL check in
 *    protocol.js: only Moodle's own /mod/ pages are ever framed.
 * Compare the Rumbos app frame, which is cross-origin and is sandboxed.
 */

let counter = 0;

export default class ActivityPopup {
    /**
     * @param {Object} params
     * @param {string} params.frameName Name of the activity iframe (the activity page checks it).
     * @param {string} params.backLabel Text of the visible "back to the map" button.
     * @param {Function} params.frameTitle Builds the iframe title from the activity title.
     * @param {Function} params.onClose Called when the user asks to close (button or Escape).
     * @param {Function} params.onLoad Called after every navigation of the activity frame.
     */
    constructor({frameName, backLabel, frameTitle, onClose, onLoad}) {
        this.frameName = frameName;
        this.backLabel = backLabel;
        this.frameTitle = frameTitle;
        this.onClose = onClose;
        this.onLoad = onLoad;

        this.root = null;
        this.frame = null;
        this.backButton = null;
        this.inerted = [];
        this.returnFocus = null;

        this.onKeydown = this.onKeydown.bind(this);
        this.onFocusin = this.onFocusin.bind(this);
        this.onViewport = this.onViewport.bind(this);
    }

    /**
     * @returns {boolean}
     */
    get isOpen() {
        return this.root !== null;
    }

    /**
     * @returns {?Window} The window of the activity frame.
     */
    get frameWindow() {
        return this.frame?.contentWindow ?? null;
    }

    /**
     * The address the activity frame is on now, or null when it is not readable (it left this origin).
     *
     * @returns {?string}
     */
    currentHref() {
        try {
            return this.frame.contentWindow.location.href;
        } catch (error) {
            return null;
        }
    }

    /**
     * Opens the dialog.
     *
     * @param {string} url Activity URL, already validated by the caller.
     * @param {string} title Activity title (plain text).
     * @param {?HTMLElement} returnFocus Where the focus goes back to on close.
     * @param {HTMLElement} [mount] Where to put the dialog; the page body by default.
     * @param {Object} [options]
     * @param {string} [options.headerSelector] The site header to leave visible above the dialog; empty for the whole window.
     */
    open(url, title, returnFocus, mount = document.body, {headerSelector = ''} = {}) {
        if (this.isOpen) {
            return;
        }
        counter += 1;
        const titleId = `format-rumbos-popup-title-${counter}`;
        this.returnFocus = returnFocus;

        const root = document.createElement('div');
        root.className = CLASSES.ROOT;
        root.setAttribute('role', 'dialog');
        root.setAttribute('aria-modal', 'true');
        root.setAttribute('aria-labelledby', titleId);

        const bar = document.createElement('div');
        bar.className = `${CLASSES.ROOT}-bar`;
        const heading = document.createElement('h2');
        heading.id = titleId;
        heading.className = `${CLASSES.ROOT}-title`;
        heading.textContent = title;
        this.backButton = document.createElement('button');
        this.backButton.type = 'button';
        this.backButton.className = 'btn btn-primary btn-sm';
        this.backButton.dataset.action = 'rumbos-popup-close';
        this.backButton.textContent = this.backLabel;
        this.backButton.addEventListener('click', () => this.onClose());
        bar.append(heading, this.backButton);

        const frame = document.createElement('iframe');
        frame.className = `${CLASSES.ROOT}-frame`;
        frame.name = this.frameName;
        frame.dataset.region = 'rumbos-popup-frame';
        frame.title = this.frameTitle(title);
        frame.setAttribute('allow', 'fullscreen');
        frame.addEventListener('load', () => this.onLoad());
        frame.src = url;
        this.frame = frame;

        // Tabbing past either end of the dialog comes back into it (onFocusin covers the rest).
        const before = this.sentinel(() => frame.focus());
        const after = this.sentinel(() => this.backButton.focus());
        root.append(before, bar, frame, after);

        mount.append(root);
        this.placeBelow(root, headerSelector);
        this.makeBackgroundInert(root);
        document.body.classList.add(CLASSES.BODY_OPEN);
        this.root = root;

        document.addEventListener('keydown', this.onKeydown);
        document.addEventListener('focusin', this.onFocusin);
        window.visualViewport?.addEventListener('resize', this.onViewport);
        this.onViewport();
        this.backButton.focus();
    }

    /**
     * Closes the dialog and gives the focus back.
     */
    close() {
        if (!this.isOpen) {
            return;
        }
        document.removeEventListener('keydown', this.onKeydown);
        document.removeEventListener('focusin', this.onFocusin);
        window.visualViewport?.removeEventListener('resize', this.onViewport);

        this.root.remove();
        this.root = null;
        this.frame = null;
        this.backButton = null;
        document.body.classList.remove(CLASSES.BODY_OPEN);
        this.inerted.forEach((element) => {
            element.inert = false;
        });
        this.inerted = [];

        if (this.returnFocus && document.contains(this.returnFocus)) {
            this.returnFocus.focus({preventScroll: true});
        }
        this.returnFocus = null;
    }

    /**
     * Starts the dialog below the site header, when one is configured and visible.
     *
     * The page is scrolled to the top first so the header is where it can be seen above the dialog.
     *
     * @param {HTMLElement} root The dialog.
     * @param {string} selector
     */
    placeBelow(root, selector) {
        if (!selector) {
            return;
        }
        let header = null;
        try {
            header = document.querySelector(selector);
        } catch (error) {
            return;
        }
        if (!header) {
            return;
        }
        window.scrollTo(0, 0);
        const bottom = Math.round(header.getBoundingClientRect().bottom);
        if (bottom > 0 && bottom < window.innerHeight / 2) {
            root.style.setProperty('--format-rumbos-popup-top', `${bottom}px`);
        }
    }

    /**
     * An invisible tab stop that sends the focus somewhere else.
     *
     * @param {Function} redirect
     * @returns {HTMLElement}
     */
    sentinel(redirect) {
        const element = document.createElement('span');
        element.tabIndex = 0;
        element.addEventListener('focus', redirect);
        return element;
    }

    /**
     * The rest of the page is unreachable while the dialog is open (no focus, no clicks, hidden from screen readers).
     *
     * The dialog may be inside another element (the one in the browser's full screen), so what is made inert
     * is every sibling of the dialog and of each of its ancestors, up to the body.
     *
     * @param {HTMLElement} root The dialog, which stays active.
     */
    makeBackgroundInert(root) {
        this.inerted = [];
        for (let node = root; node !== document.body && node.parentElement; node = node.parentElement) {
            for (const sibling of node.parentElement.children) {
                if (sibling !== node && !sibling.inert) {
                    this.inerted.push(sibling);
                }
            }
        }
        this.inerted.forEach((element) => {
            element.inert = true;
        });
    }

    /**
     * Keeps the focus in the dialog if anything outside it gets it.
     *
     * @param {FocusEvent} event
     */
    onFocusin(event) {
        if (this.root && !this.root.contains(event.target)) {
            this.backButton.focus();
        }
    }

    /**
     * Escape closes the dialog when the focus is in the host page (the activity page forwards its own).
     *
     * @param {KeyboardEvent} event
     */
    onKeydown(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            this.onClose();
        }
    }

    /**
     * Sizes the dialog to what is visible, so the on-screen keyboard does not cover the bottom of the activity.
     */
    onViewport() {
        const height = window.visualViewport?.height;
        if (this.root && height) {
            this.root.style.setProperty('--format-rumbos-popup-height', `${Math.round(height)}px`);
        }
    }
}
