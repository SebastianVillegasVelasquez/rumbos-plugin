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
 * Runs inside the activity page when the activity popup frames it.
 *
 * The plugin's page hook loads this module only for same-origin iframe requests
 * on a page of a Rumbos course. It does nothing unless the window is the popup
 * frame, so a page that is merely framed by something else is left alone.
 *
 * @module     format_rumbos/popup_page
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {POPUP_MESSAGE, PROTOCOL_VERSION} from 'format_rumbos/protocol';

/**
 * Tells the host page something, on this site's origin only.
 *
 * @param {string} type A POPUP_MESSAGE value.
 */
const tellHost = (type) => {
    window.parent.postMessage({type, v: PROTOCOL_VERSION}, window.location.origin);
};

/**
 * Whether Escape belongs to something inside the activity (an open Moodle modal, a handled key).
 *
 * @param {KeyboardEvent} event
 * @returns {boolean}
 */
const isHandledInside = (event) => event.defaultPrevented || document.querySelector('.modal.show, .moodle-dialogue') !== null;

/**
 * Links to other sites open in a new tab: most of them refuse to be framed (X-Frame-Options),
 * and a popup that left Moodle could not get back to the activity.
 *
 * @param {MouseEvent} event
 */
const openExternalLinksInNewTab = (event) => {
    const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
    if (link && ['http:', 'https:'].includes(link.protocol) && link.origin !== window.location.origin && !link.target) {
        link.target = '_blank';
        link.rel = 'noopener';
    }
};

/**
 * @param {Object} params
 * @param {string} params.frameName The name of the popup frame.
 * @param {boolean} params.courseView The page is the course page, where the activity's "Continue" leads.
 */
export const init = ({frameName, courseView}) => {
    if (window.name !== frameName || window.parent === window) {
        return;
    }
    if (courseView) {
        // Leaving the activity for the course page: the host closes the popup instead of showing the map in it.
        tellHost(POPUP_MESSAGE.LEAVE);
        return;
    }
    // The styles (styles.css) hide the course chrome when this class is on the body.
    document.body.classList.add('format-rumbos-popup-page');
    // Capture phase: the attributes are set before the browser follows the link.
    document.addEventListener('click', openExternalLinksInNewTab, true);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !isHandledInside(event)) {
            tellHost(POPUP_MESSAGE.ESCAPE);
        }
    });
};
