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

namespace format_rumbos\admin;

use format_rumbos\local\key_manager;
use format_rumbos\local\launch_assertion;
use format_rumbos\local\registration;

/**
 * Tests for the admin settings.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\admin\setting_appurl::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\admin\setting_registration::class)]
final class settings_test extends \advanced_testcase {
    /**
     * Starts every test without stored keys.
     *
     * The baseline database holds keys that were encrypted at install time with
     * a key file PHPUnit discards between tests, so they could not be decrypted.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        foreach (['privatekey', 'publickey', 'kid', 'hmacsecret'] as $name) {
            unset_config($name, 'format_rumbos');
        }
    }

    /**
     * The new-tab list is stored clean and drives the popup policy.
     */
    public function test_newtabmodules_is_stored_clean(): void {
        $this->setAdminUser();
        $setting = new setting_newtabmodules('format_rumbos/newtabmodules', 'New tab', '');

        $this->assertSame('', $setting->write_setting(" LTI,
zoom, lti "));

        $this->assertSame('lti,zoom', get_config('format_rumbos', 'newtabmodules'));
        $this->assertSame(['lti', 'zoom'], \format_rumbos\local\popup::get_newtab_modules());
    }

    /**
     * An empty list is valid (everything opens in the popup); names that are not modules are refused.
     */
    public function test_newtabmodules_validation(): void {
        $this->setAdminUser();
        $setting = new setting_newtabmodules('format_rumbos/newtabmodules', 'New tab', '');

        $this->assertSame('', $setting->write_setting(''));
        $this->assertSame([], \format_rumbos\local\popup::get_newtab_modules());

        $this->assertNotSame('', $setting->write_setting('lti,../etc'));
        $this->assertNotSame(true, $setting->validate('mod_lti'));
        $this->assertSame([], \format_rumbos\local\popup::get_newtab_modules(), 'a refused value is not stored');
    }

    /**
     * The polled modules use the same list setting with their own default, and are stored clean.
     */
    public function test_pollmodules_is_stored_clean(): void {
        $this->setAdminUser();
        $setting = new setting_newtabmodules(
            'format_rumbos/pollmodules',
            'Polled',
            '',
            \format_rumbos\local\popup::DEFAULT_POLL_MODULES
        );
        $this->assertSame('scorm,h5pactivity', $setting->get_defaultsetting());

        $this->assertSame('', $setting->write_setting(' H5PActivity, scorm '));
        $this->assertSame(['h5pactivity', 'scorm'], \format_rumbos\local\popup::get_poll_modules());
        $this->assertNotSame('', $setting->write_setting('scorm,../etc'));
        $this->assertSame(['h5pactivity', 'scorm'], \format_rumbos\local\popup::get_poll_modules());
    }

    /**
     * The URL setting stores the normalised https URL.
     */
    public function test_appurl_is_stored_normalised(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $setting = new setting_appurl('format_rumbos/appurl', 'App URL', '');

        $this->assertSame('', $setting->write_setting('  HTTPS://Rumbos.Example.com/app/ '));

        $this->assertSame('https://rumbos.example.com/app', get_config('format_rumbos', 'appurl'));
    }

    /**
     * Anything that is not https is refused and nothing is stored.
     */
    public function test_appurl_rejects_http(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $setting = new setting_appurl('format_rumbos/appurl', 'App URL', '');

        $this->assertNotSame(true, $setting->validate('http://rumbos.example.com'));
        $this->assertNotSame('', $setting->write_setting('http://rumbos.example.com'));

        $this->assertEmpty(get_config('format_rumbos', 'appurl'));
    }

    /**
     * An empty value is allowed: the app is simply not configured.
     */
    public function test_appurl_may_be_empty(): void {
        $this->resetAfterTest();
        $setting = new setting_appurl('format_rumbos/appurl', 'App URL', '');

        $this->assertTrue($setting->validate(''));
    }

    /**
     * Renders the registration panel as the current user.
     *
     * @return string
     */
    private function render_panel(): string {
        $setting = new setting_registration('format_rumbos/registration', 'Registration', '');
        return $setting->output_html(true);
    }

    /**
     * The panel is read-only: it writes nothing under its own name.
     */
    public function test_registration_setting_writes_nothing(): void {
        $this->setAdminUser();
        $setting = new setting_registration('format_rumbos/registration', 'Registration', '');

        $this->assertSame('', $setting->write_setting('anything'));
        $this->assertFalse(get_config('format_rumbos', 'registration'));
    }

    /**
     * An administrator sees every value to register, taken from the signing code.
     */
    public function test_administrator_sees_the_values_to_register(): void {
        global $CFG;
        $CFG->wwwroot = 'https://LMS.Example.com:443/moodle/';
        $this->setAdminUser();

        $html = $this->render_panel();
        $values = registration::values();

        $this->assertStringContainsString('https://lms.example.com/moodle', $html);
        $this->assertStringContainsString('https://lms.example.com', $html);
        $this->assertStringContainsString(launch_assertion::issuer(), $html);
        $this->assertStringContainsString($values['audience'], $html);
        $this->assertStringContainsString($values['kid'], $html);
        $this->assertStringContainsString($values['fingerprint'], $html);
        $this->assertStringContainsString('BEGIN PUBLIC KEY', $html);
        $this->assertStringContainsString('--kid ' . $values['kid'], $html);
        $this->assertStringContainsString('python -m app.scripts.tenants add', $html);
    }

    /**
     * The fingerprint on the panel matches the stored key.
     */
    public function test_the_fingerprint_matches_the_stored_key(): void {
        $this->setAdminUser();

        $html = $this->render_panel();

        $this->assertStringContainsString(key_manager::fingerprint_of(get_config('format_rumbos', 'publickey')), $html);
    }

    /**
     * The panel offers copy buttons, the diagnostic and the rotation, and never a secret.
     */
    public function test_the_panel_has_its_actions_and_never_leaks_a_secret(): void {
        $this->setAdminUser();

        $html = $this->render_panel();

        $this->assertStringContainsString('data-action="copytoclipboard"', $html);
        $this->assertStringContainsString('data-action="format-rumbos-preview"', $html);
        $this->assertStringContainsString('rotatekey.php', $html);
        $this->assertStringNotContainsString('PRIVATE KEY', $html);
        $this->assertStringNotContainsString(key_manager::get_private_key(), $html);
        $this->assertStringNotContainsString(key_manager::get_hmac_secret(), $html);
    }

    /**
     * Nothing is rendered for someone who cannot configure the site.
     */
    public function test_the_panel_is_empty_without_the_site_configuration_capability(): void {
        $course = $this->getDataGenerator()->create_course(['format' => 'rumbos']);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $this->assertSame('', $this->render_panel());
    }

    /**
     * Unreadable keys show an alert with the reset link, and the public values still render.
     */
    public function test_unhealthy_keys_show_the_reset_alert(): void {
        $this->setAdminUser();
        key_manager::ensure_initialised();
        set_config('hmacsecret', base64_encode(random_bytes(80)), 'format_rumbos');

        $html = $this->render_panel();

        $this->assertStringContainsString('rotatekey.php?reset=1', $html);
        $this->assertStringContainsString('BEGIN PUBLIC KEY', $html);
    }
}
