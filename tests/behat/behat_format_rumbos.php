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
                    var detail = {'rumbos:host-overlay': message.open, 'rumbos:host-fullscreen': message.on}[message.type];
                    window.rumbosSent.push(message.type + (detail === undefined ? '' : ':' + detail));
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
     * The app tells the host it has nothing to show this user (contract v5.5).
     *
     * @When /^the Rumbos app reports that it has no content because "(?P<reason_string>(?:[^"]|\\")*)"$/
     * @param string $reason Reason code, such as no_saved_map or empty_map.
     */
    public function the_rumbos_app_reports_that_it_has_no_content(string $reason): void {
        $this->getSession()->executeScript(
            'window.rumbosAppSays({type: "rumbos:no-content", reason: ' . json_encode($reason) . '});'
        );
        $this->wait_for_pending_js();
    }

    /**
     * Checks that the host replaced the app with the standard content, and why.
     *
     * @Then /^the Rumbos host should have fallen back because "(?P<reason_string>(?:[^"]|\\")*)"$/
     * @param string $reason The data-reason of the container.
     */
    public function the_rumbos_host_should_have_fallen_back(string $reason): void {
        $state = '';
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $state = $this->getSession()->evaluateScript(
                '(function(c) { return c.dataset.state + ":" + (c.dataset.reason || ""); })' .
                '(document.querySelector("[data-region=rumbos-container]"))'
            );
            if ($state === 'degraded:' . $reason) {
                return;
            }
            $this->getSession()->wait(250);
        }
        throw new \Behat\Mink\Exception\ExpectationException(
            'Expected the host to be degraded:' . $reason . ', it is ' . $state,
            $this->getSession()->getDriver()
        );
    }

    /**
     * Checks the height of the Rumbos area, in CSS pixels.
     *
     * @Then /^the Rumbos area should be (?P<pixels>[0-9]+) pixels tall$/
     * @param string $pixels
     */
    public function the_rumbos_area_should_be_pixels_tall(string $pixels): void {
        $height = $this->rumbos_area_height();
        if (abs($height - (int) $pixels) > 1) {
            throw new \Behat\Mink\Exception\ExpectationException(
                'Expected the Rumbos area to be ' . $pixels . 'px tall, it is ' . $height . 'px',
                $this->getSession()->getDriver()
            );
        }
    }

    /**
     * Checks the height of the Rumbos area as a percentage of the viewport height.
     *
     * @Then /^the Rumbos area should be (?P<percent>[0-9]+) percent of the screen height$/
     * @param string $percent
     */
    public function the_rumbos_area_should_be_percent_of_the_screen(string $percent): void {
        $viewport = (float) $this->getSession()->evaluateScript('return window.innerHeight;');
        $height = $this->rumbos_area_height();
        $expected = $viewport * (int) $percent / 100;
        // Browsers round the viewport units differently by a pixel or two.
        if (abs($height - $expected) > 3) {
            throw new \Behat\Mink\Exception\ExpectationException(
                'Expected the Rumbos area to be ' . $percent . '% of ' . $viewport . 'px (' . round($expected) .
                'px), it is ' . $height . 'px',
                $this->getSession()->getDriver()
            );
        }
    }

    /**
     * The app tells the host the proportions of the level on display (contract v5.5).
     *
     * @When /^the Rumbos app prefers an aspect ratio of (?P<ratio>[0-9.]+)$/
     * @param string $ratio Width divided by height.
     */
    public function the_rumbos_app_prefers_an_aspect_ratio(string $ratio): void {
        $this->getSession()->executeScript(
            'window.rumbosAppSays({type: "rumbos:preferred-size", aspectRatio: ' . (float) $ratio . '});'
        );
        $this->wait_for_pending_js();
        // The height changes with a short transition.
        $this->getSession()->wait(1000);
    }

    /**
     * Checks that the area is as tall as its width and an aspect ratio give, within the site limits.
     *
     * @Then /^the Rumbos area should follow an aspect ratio of (?P<ratio>[0-9.]+)$/
     * @param string $ratio Width divided by height.
     */
    public function the_rumbos_area_should_follow_an_aspect_ratio(string $ratio): void {
        [$width, $min, $max] = $this->getSession()->evaluateScript(<<<'JS'
            return (function() {
                var container = document.querySelector('[data-region=rumbos-container]');
                var style = getComputedStyle(container);
                return [container.getBoundingClientRect().width,
                    parseFloat(style.getPropertyValue('--format-rumbos-min')),
                    parseFloat(style.getPropertyValue('--format-rumbos-max'))];
            })();
JS
        );
        $expected = max($min, min($max, $width / (float) $ratio));
        $height = $this->rumbos_area_height();
        if (abs($height - $expected) > 2) {
            throw new \Behat\Mink\Exception\ExpectationException(
                'Expected the Rumbos area to be ' . round($expected) . 'px tall (width ' . round($width) . ', ratio ' . $ratio .
                ', limits ' . $min . '-' . $max . '), it is ' . $height . 'px',
                $this->getSession()->getDriver()
            );
        }
    }

    /**
     * Reads the height of the frame wrapper.
     *
     * @return float
     */
    protected function rumbos_area_height(): float {
        return (float) $this->getSession()->evaluateScript(
            'return document.querySelector("[data-region=rumbos-frame-wrap]").getBoundingClientRect().height;'
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
     * The app asks the host to open the settings page of an activity (contract v5.4).
     *
     * @When /^the Rumbos app asks to open the settings of the activity "(?P<name_string>(?:[^"]|\\")*)"$/
     * @param string $name Activity name.
     */
    public function the_rumbos_app_asks_to_open_the_settings_of_the_activity(string $name): void {
        $this->ask_to_open_settings($this->find_page_cmid($name));
    }

    /**
     * The app asks for the settings of an activity that does not exist.
     *
     * @When /^the Rumbos app asks to open the settings of an activity that does not exist$/
     */
    public function the_rumbos_app_asks_to_open_the_settings_of_an_unknown_activity(): void {
        $this->ask_to_open_settings(999999);
    }

    /**
     * Sends open-activity-settings and gives the host time to ask Moodle and answer.
     *
     * @param int $cmid
     */
    protected function ask_to_open_settings(int $cmid): void {
        $this->getSession()->wait(2000);
        $this->getSession()->executeScript(
            'window.rumbosAppSays({type: "rumbos:open-activity-settings", cmid: ' . $cmid . '});'
        );
        $this->wait_for_pending_js();
        $this->getSession()->wait(2000);
    }

    /**
     * Checks that the host asked for a new tab with the settings page of an activity, on this site.
     *
     * @Then /^a new tab should have been requested for the settings of the activity "(?P<name_string>(?:[^"]|\\")*)"$/
     * @param string $name Activity name.
     */
    public function a_new_tab_should_have_been_requested_for_the_settings(string $name): void {
        global $CFG;

        $cmid = $this->find_page_cmid($name);
        $opened = $this->getSession()->evaluateScript('return window.rumbosOpened;');
        $expected = $CFG->wwwroot . '/course/modedit.php?update=' . $cmid . '&showonly=activitycompletionheader';
        if ($opened !== [$expected]) {
            throw new \Behat\Mink\Exception\ExpectationException(
                'Expected exactly one new tab, for ' . $expected . '; requested: [' . implode(', ', $opened) . ']',
                $this->getSession()->getDriver()
            );
        }
    }

    /**
     * Checks that the host has not asked the browser for any new tab.
     *
     * @Then /^no new tab should have been requested$/
     */
    public function no_new_tab_should_have_been_requested(): void {
        $opened = $this->getSession()->evaluateScript('return window.rumbosOpened;');
        if ($opened) {
            throw new \Behat\Mink\Exception\ExpectationException(
                'No new tab was expected; requested: [' . implode(', ', $opened) . ']',
                $this->getSession()->getDriver()
            );
        }
    }

    /**
     * The app asks the host to turn the full screen mode on or off.
     *
     * @When /^the Rumbos app asks for full screen (?P<state>on|off)$/
     * @param string $state "on" or "off".
     */
    public function the_rumbos_app_asks_for_full_screen(string $state): void {
        $this->getSession()->executeScript(
            'window.rumbosAppSays({type: "rumbos:request-fullscreen", on: ' . ($state === 'on' ? 'true' : 'false') . '});'
        );
        $this->wait_for_pending_js();
    }

    /**
     * The app asks for full screen after a real click, which browsers require before they grant their own full screen.
     *
     * A button is added to the page and clicked through the browser driver; its handler plays the app's message.
     *
     * @When /^the Rumbos app asks for full screen on after a real click$/
     */
    public function the_rumbos_app_asks_for_full_screen_after_a_real_click(): void {
        $this->getSession()->executeScript(<<<'JS'
            (function() {
                var button = document.createElement('button');
                button.id = 'rumbos-test-click';
                button.textContent = 'Test click';
                button.style.cssText = 'position:fixed;left:0;bottom:0;z-index:5000';
                button.addEventListener('click', function() {
                    window.rumbosAppSays({type: 'rumbos:request-fullscreen', on: true});
                });
                document.body.appendChild(button);
            })();
JS
        );
        $this->getSession()->getPage()->find('css', '#rumbos-test-click')->click();
        $this->wait_for_pending_js();
        $this->getSession()->wait(1000);
    }

    /**
     * Checks whether the browser's own full screen is on the map's wrapper.
     *
     * @Then /^the browser's full screen should (?P<state>be|not be) on the Rumbos map$/
     * @param string $state "be" or "not be".
     */
    public function the_browsers_full_screen_should_be_on_the_map(string $state): void {
        $on = (bool) $this->getSession()->evaluateScript(
            'return !!document.fullscreenElement && document.fullscreenElement.classList.contains("format-rumbos-frame-wrap");'
        );
        if ($on !== ($state === 'be')) {
            throw new \Behat\Mink\Exception\ExpectationException(
                'Expected the browser full screen ' . $state . ' on the map, but it ' . ($on ? 'is' : 'is not'),
                $this->getSession()->getDriver()
            );
        }
    }

    /**
     * Checks that the activity popup is inside the element that is in the browser's full screen.
     *
     * @Then /^the Rumbos popup should be inside the browser's full screen$/
     */
    public function the_rumbos_popup_should_be_inside_the_browsers_full_screen(): void {
        $inside = (bool) $this->getSession()->evaluateScript(
            '(function() { var p = document.querySelector(".format-rumbos-popup"); ' .
            'return !!p && !!document.fullscreenElement && document.fullscreenElement.contains(p); })()'
        );
        if (!$inside) {
            throw new \Behat\Mink\Exception\ExpectationException(
                'The activity popup is not inside the browser full screen element',
                $this->getSession()->getDriver()
            );
        }
    }

    /**
     * Checks the host contract: a 64 x 64 CSS px exit control in the top-right corner of the viewport, above the app.
     *
     * @Then /^the Rumbos exit control should own the top-right corner of the screen$/
     */
    public function the_rumbos_exit_control_should_own_the_top_right_corner(): void {
        $result = $this->getSession()->evaluateScript(<<<'JS'
            return (function() {
                var button = document.querySelector('[data-action="rumbos-exit-fullscreen"]');
                var rect = button.getBoundingClientRect();
                var width = document.documentElement.clientWidth;
                var points = [[width - 4, 4], [width - 60, 60], [width - 32, 32]];
                var hit = [];
                var above = points.every(function(point) {
                    var element = document.elementFromPoint(point[0], point[1]);
                    hit.push(element === null ? 'null' : element.tagName + '.' + element.className);
                    return element !== null && button.contains(element);
                });
                return [Math.round(rect.width), Math.round(rect.height), Math.round(rect.top),
                    Math.round(width - rect.right), above ? 1 : hit.join(','), button.getAttribute('aria-label')].join('|');
            })();
JS
        );
        if ($result !== '64|64|0|0|1|Exit full screen') {
            throw new \Behat\Mink\Exception\ExpectationException(
                'Expected the exit control to be 64x64 at the top-right corner, above the app and labelled; got ' . $result,
                $this->getSession()->getDriver()
            );
        }
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
     * Completes an activity for a user the way a package reporting from inside its own frame would:
     * Moodle changes, and no page of the activity is loaded.
     *
     * @When /^the activity "(?P<name_string>(?:[^"]|\\")*)" is completed for "(?P<username_string>(?:[^"]|\\")*)" in the background$/
     * @param string $name Activity name.
     * @param string $username
     */
    public function the_activity_is_completed_in_the_background(string $name, string $username): void {
        global $DB;

        $cm = get_coursemodule_from_id('page', $this->find_page_cmid($name), 0, false, MUST_EXIST);
        $course = get_course($cm->course);
        $user = $DB->get_record('user', ['username' => $username], '*', MUST_EXIST);
        $completion = new \completion_info($course);
        $data = $completion->get_data($cm, false, $user->id);
        $data->completionstate = COMPLETION_COMPLETE;
        $completion->internal_set_data($cm, $data);
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
