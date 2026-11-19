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
 * The tutor overlay: a full-viewport dialog the host opens over the page, framing the site's own virtual tutor.
 *
 * The tutor answers with X-Frame-Options: SAMEORIGIN, so the Rumbos app (another origin) cannot frame it and the
 * host does it instead. Like the activity popup it lives in the Moodle page, never inside the Rumbos iframe.
 *
 * It only builds DOM, manages focus and reports that it closed. Whether to open it, and which address, is the host's
 * job: the address comes from an admin setting, never from a message.
 *
 * @module     format_rumbos/tutor
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const CLASSES = {
    ROOT: 'format-rumbos-tutor',
    BODY_OPEN: 'format-rumbos-tutor-open',
};

/*
 * What the tutor frame is given, and why (see docs/TUTOR.md):
 *  - sandbox: the page is same-origin Moodle and needs its scripts and its own session (cookies), so scripts and
 *    same-origin are on. That pair gives the page no isolation from this one (it could lift the sandbox itself);
 *    what the sandbox still takes away is what a mistake could do: navigating the whole Moodle page away (top
 *    navigation), blocking modals, pointer lock and downloads. Forms are on because a chat is a form; popups are on
 *    so that a link the tutor opens in a new tab works, and they escape the sandbox so that tab is a normal one.
 *  - allow: nothing is delegated. Camera, microphone and geolocation are named with 'none', because a frame of
 *    the same origin would otherwise inherit them from this page.
 *  - referrerpolicy: same-origin, so the tutor sees where it was opened from and nobody else does.
 */
const FRAME_SANDBOX = 'allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox';
const FRAME_ALLOW = "camera 'none'; microphone 'none'; geolocation 'none'";

let counter = 0;

export default class TutorOverlay {
    /**
     * @param {Object} params
     * @param {string} params.title Accessible name of the dialog, also shown in its bar.
     * @param {string} params.closeLabel Text of the visible close button.
     * @param {Function} params.onClose Called when the overlay has closed, whoever asked.
     */
    constructor({title, closeLabel, onClose}) {
        this.title = title;
        this.closeLabel = closeLabel;
        this.onClose = onClose;

        this.root = null;
        this.frame = null;
        this.closeButton = null;
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
     * Opens the dialog.
     *
     * @param {string} url The tutor's address, already validated by the caller.
     * @param {?HTMLElement} returnFocus Where the focus goes back to on close.
     * @param {HTMLElement} [mount] Where to put the dialog; the page body by default. Inside the element that is in
     *      the browser's full screen when there is one (anything outside it is not shown).
     */
    open(url, returnFocus, mount = document.body) {
        if (this.isOpen) {
            return;
        }
        counter += 1;
        const titleId = `format-rumbos-tutor-title-${counter}`;
        this.returnFocus = returnFocus;

        const root = document.createElement('div');
        root.className = CLASSES.ROOT;
        root.dataset.region = 'rumbos-tutor';
        root.setAttribute('role', 'dialog');
        root.setAttribute('aria-modal', 'true');
        root.setAttribute('aria-labelledby', titleId);

        const bar = document.createElement('div');
        bar.className = `${CLASSES.ROOT}-bar`;
        const heading = document.createElement('h2');
        heading.id = titleId;
        heading.className = `${CLASSES.ROOT}-title`;
        heading.textContent = this.title;
        this.closeButton = document.createElement('button');
        this.closeButton.type = 'button';
        this.closeButton.className = 'btn btn-primary btn-sm';
        this.closeButton.dataset.action = 'rumbos-tutor-close';
        this.closeButton.textContent = this.closeLabel;
        this.closeButton.addEventListener('click', () => this.close());
        bar.append(heading, this.closeButton);

        const frame = document.createElement('iframe');
        frame.className = `${CLASSES.ROOT}-frame`;
        frame.dataset.region = 'rumbos-tutor-frame';
        frame.title = this.title;
        frame.setAttribute('sandbox', FRAME_SANDBOX);
        frame.setAttribute('allow', FRAME_ALLOW);
        frame.setAttribute('referrerpolicy', 'same-origin');
        frame.src = url;
        this.frame = frame;

        // Tabbing past either end of the dialog comes back into it (onFocusin covers the rest).
        const before = this.sentinel(() => frame.focus());
        const after = this.sentinel(() => this.closeButton.focus());
        root.append(before, bar, frame, after);

        mount.append(root);
        this.makeBackgroundInert(root);
        document.body.classList.add(CLASSES.BODY_OPEN);
        this.root = root;

        document.addEventListener('keydown', this.onKeydown);
        document.addEventListener('focusin', this.onFocusin);
        window.visualViewport?.addEventListener('resize', this.onViewport);
        this.onViewport();
        this.closeButton.focus();
    }

    /**
     * Closes the dialog, gives the focus back and says so.
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
        this.closeButton = null;
        document.body.classList.remove(CLASSES.BODY_OPEN);
        // The background must be reachable again before the focus can go back to it.
        this.inerted.forEach((element) => {
            element.inert = false;
        });
        this.inerted = [];

        const target = this.returnFocus;
        this.returnFocus = null;
        if (target && document.contains(target)) {
            target.focus({preventScroll: true});
        }
        this.onClose();
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
            this.closeButton.focus();
        }
    }

    /**
     * Escape closes the dialog while the focus is in the host page. Inside the tutor's own page the key belongs to
     * that page, so the visible Close button is the way out there.
     *
     * @param {KeyboardEvent} event
     */
    onKeydown(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            this.close();
        }
    }

    /**
     * Sizes the dialog to what is visible, so the on-screen keyboard does not cover the tutor's message box.
     */
    onViewport() {
        const height = window.visualViewport?.height;
        if (this.root && height) {
            this.root.style.setProperty('--format-rumbos-tutor-height', `${Math.round(height)}px`);
        }
    }
}
