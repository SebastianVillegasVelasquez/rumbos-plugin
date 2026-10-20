<?php
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

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../../lib/behat/behat_base.php');

/**
 * Behat steps for the Rumbos course format.
 *
 * There is no Rumbos app in the test site, so these steps play its part: they put a stand-in
 * window where the iframe's window is, send the host the messages the app would send and
 * record what the host sends back.
 *
 * @package    format_rumbos
 * @category   test
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_format_rumbos extends behat_base {
    /**
     * Replaces the app's window with a stand-in that records what the host sends it.
     *
     * Must run before the host gives up waiting for the app (8 seconds), which is why the
     * announcement is sent in the same step.
     *
     * @Given /^the Rumbos app announces that it is ready$/
     */
    public function the_rumbos_app_announces_that_it_is_ready(): void {
        $this->getSession()->executeScript(<<<'JS'
            (function() {
                var frame = document.querySelector('[data-region="rumbos-frame"]');
                var standin = document.createElement('iframe');
                standin.style.display = 'none';
                document.body.append(standin);
                var win = standin.contentWindow;
                window.rumbosSent = [];
                window.rumbosOpened = [];
                win.postMessage = function(message) {
                    window.rumbosSent.push(message.type + (message.type === 'rumbos:host-overlay' ? ':' + message.open : ''));
                };
                window.open = function(url) {
                    window.rumbosOpened.push(url);
                    return {};
                };
                Object.defineProperty(frame, 'contentWindow', {get: function() { return win; }});
                window.rumbosAppSays = function(data) {
                    data.v = 1;
                    window.dispatchEvent(new MessageEvent('message', {
                        data: data,
                        origin: 'https://rumbos.invalid',
                        source: win
                    }));
                };
                window.rumbosAppSays({type: 'rumbos:embed-ready'});
            })();
JS
        );
        $this->wait_for_pending_js();
    }

    /**
     * Waits until the host has answered the app's announcement, and says what state it is in if it did not.
     *
     * @Then /^the Rumbos host should be ready$/
     */
    public function the_rumbos_host_should_be_ready(): void {
        $state = '';
        for ($attempt = 0; $attempt < 40; $attempt++) {
            $state = $this->getSession()->evaluateScript(
                '(function(c) { return c.dataset.state + (c.dataset.reason ? ":" + c.dataset.reason : ""); })' .
                '(document.querySelector("[data-region=rumbos-container]"))'
            );
            if ($state === 'ready') {
                return;
            }
            $this->getSession()->wait(250);
        }
        throw new \Behat\Mink\Exception\ExpectationException(
            'The Rumbos host is "' . $state . '", not ready',
            $this->getSession()->getDriver()
        );
    }

    /**
     * Forgets the stored signing keys, so the next launch generates new ones.
     *
     * The baseline database holds keys that were encrypted at install time with an encryption
     * key file the test site does not keep, so they cannot be decrypted.
     *
     * @Given /^the Rumbos signing keys are generated afresh$/
     */
    public function the_rumbos_signing_keys_are_generated_afresh(): void {
        foreach (['privatekey', 'publickey', 'kid', 'hmacsecret'] as $name) {
            unset_config($name, 'format_rumbos');
        }
    }

    /**
     * The app asks the host to open an activity of the course.
     *
     * @When /^the Rumbos app asks to open the activity "(?P<name_string>(?:[^"]|\\")*)"$/
     * @param string $name Activity name.
     */
    public function the_rumbos_app_asks_to_open_the_activity(string $name): void {
        $cmid = $this->find_page_cmid($name);
        $this->getSession()->wait(2000);
        $this->getSession()->executeScript(
            'window.rumbosAppSays({type: "rumbos:open-activity", cmid: ' . (int) $cmid . '});'
        );
        $this->wait_for_pending_js();
    }

    /**
     * Sends the popup frame to the course page, as the "Continue" of an activity does.
     *
     * @When /^the activity in the popup goes to the course page$/
     */
    public function the_activity_in_the_popup_goes_to_the_course_page(): void {
        $this->getSession()->executeScript(
            'document.querySelector(".format-rumbos-popup-frame").contentWindow.location.href = ' .
            'M.cfg.wwwroot + "/course/view.php?id=" + document.querySelector("[data-region=rumbos-container]")' .
            '.dataset.courseid;'
        );
        $this->wait_for_pending_js();
    }

    /**
     * Waits for the popup to go away (leaving through the course page loads a whole page first).
     *
     * @Then /^the Rumbos popup should close$/
     */
    public function the_rumbos_popup_should_close(): void {
        for ($attempt = 0; $attempt < 120; $attempt++) {
            if (!$this->getSession()->evaluateScript('document.querySelector(".format-rumbos-popup") !== null')) {
                return;
            }
            $this->getSession()->wait(250);
        }
        throw new \Behat\Mink\Exception\ExpectationException(
            'The Rumbos popup is still open after 30 seconds',
            $this->getSession()->getDriver()
        );
    }

    /**
     * Checks the messages the host sent to the app, in order, after the last initialisation.
     *
     * @Then /^the Rumbos app should have been sent "(?P<types_string>(?:[^"]|\\")*)" last$/
     * @param string $types Comma separated message types ending the list.
     */
    public function the_rumbos_app_should_have_been_sent(string $types): void {
        $expected = array_map('trim', explode(',', $types));
        $sent = [];
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $sent = $this->getSession()->evaluateScript('return window.rumbosSent;');
            if (array_slice($sent, -count($expected)) === $expected) {
                return;
            }
            $this->getSession()->wait(250);
        }
        throw new \Behat\Mink\Exception\ExpectationException(
            'Expected the app to be sent [' . implode(', ', $expected) . '] last, got [' . implode(', ', $sent) . ']',
            $this->getSession()->getDriver()
        );
    }

    /**
     * Checks that the host asked the browser for a new tab with the activity.
     *
     * @Then /^a new tab should have been requested for the activity "(?P<name_string>(?:[^"]|\\")*)"$/
     * @param string $name Activity name.
     */
    public function a_new_tab_should_have_been_requested(string $name): void {
        $cmid = $this->find_page_cmid($name);
        $opened = $this->getSession()->evaluateScript('return window.rumbosOpened;');
        foreach ($opened as $url) {
            if (preg_match('~/mod/[a-z0-9]+/view\.php\?id=' . (int) $cmid . '$~', $url)) {
                return;
            }
        }
        throw new \Behat\Mink\Exception\ExpectationException(
            'No new tab was requested for ' . $name . '; requested: [' . implode(', ', $opened) . ']',
            $this->getSession()->getDriver()
        );
    }

    /**
     * Gets the id of the course module with an activity name, which must exist.
     *
     * @param string $name Activity name.
     * @return int
     */
    protected function find_page_cmid(string $name): int {
        global $DB;

        $records = $DB->get_records_sql(
            "SELECT cm.id
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
               JOIN {page} p ON p.id = cm.instance AND m.name = 'page'
              WHERE p.name = :name",
            ['name' => $name]
        );
        if (!$records) {
            throw new \Behat\Mink\Exception\ExpectationException(
                'There is no page activity called ' . $name,
                $this->getSession()->getDriver()
            );
        }
        return (int) reset($records)->id;
    }
}
