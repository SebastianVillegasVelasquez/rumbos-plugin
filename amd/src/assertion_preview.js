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
 * Shows the decoded header and claims of a sample assertion on the registration panel.
 *
 * The server signs a fresh assertion for the administrator, decodes it and returns
 * only the header and the claims. The token and its signature never reach the page.
 *
 * @module     format_rumbos/assertion_preview
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';

const SELECTORS = {
    BUTTON: '[data-action="format-rumbos-preview"]',
    REGION: '[data-region="format-rumbos-preview"]',
    OUTPUT: '[data-region="format-rumbos-preview-output"]',
    TIMES: '[data-region="format-rumbos-preview-times"]',
};

/**
 * Formats a Unix time as UTC text.
 *
 * @param {number} seconds
 * @returns {string}
 */
const utc = (seconds) => new Date(seconds * 1000).toISOString().replace('T', ' ').replace(/\.\d+Z$/, ' UTC');

/**
 * Fills and reveals the preview region. Everything is set with textContent: none of it is HTML.
 *
 * @param {Object} result Decoded header, claims and lifetime.
 */
const show = (result) => {
    const region = document.querySelector(SELECTORS.REGION);
    const output = region.querySelector(SELECTORS.OUTPUT);
    const times = region.querySelector(SELECTORS.TIMES);

    output.textContent = JSON.stringify({header: result.header, claims: result.claims}, null, 2);
    times.textContent = `${output.dataset.issuedLabel}: ${utc(result.claims.iat)} · ` +
        `${output.dataset.expiresLabel}: ${utc(result.claims.exp)}`;
    region.hidden = false;
};

/**
 * Wires the button on the registration panel.
 */
export const init = () => {
    const button = document.querySelector(SELECTORS.BUTTON);
    if (!button) {
        return;
    }

    button.addEventListener('click', () => {
        button.disabled = true;
        Ajax.call([{methodname: 'format_rumbos_get_assertion_preview', args: {courseid: 0}}])[0]
            .then((result) => {
                show(result);
                return true;
            })
            .catch(Notification.exception)
            .finally(() => {
                button.disabled = false;
            });
    });
};
