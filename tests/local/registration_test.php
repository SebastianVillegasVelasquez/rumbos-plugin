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

namespace format_rumbos\local;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Tests for the registration values and command.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_rumbos\local\registration::class)]
final class registration_test extends \advanced_testcase {
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
     * Signs an assertion for a student and returns its header and claims.
     *
     * @return array [header, claims]
     */
    private function signed(): array {
        $course = $this->getDataGenerator()->create_course(['format' => 'rumbos']);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $token = launch_assertion::create($course->id, $user->id)['token'];

        $header = json_decode(JWT::urlsafeB64Decode(explode('.', $token)[0]), true);
        $claims = JWT::decode($token, new Key(key_manager::get_public_key(), 'RS256'));
        return [$header, $claims];
    }

    /**
     * The panel's issuer, audience and key id are the ones the assertion carries.
     */
    public function test_values_are_what_the_assertion_carries(): void {
        global $CFG;
        $CFG->wwwroot = 'https://LMS.Example.com:443/moodle/';
        set_config('audience', 'rumbos-staging', 'format_rumbos');

        $values = registration::values();
        [$header, $claims] = $this->signed();

        $this->assertSame($claims->iss, $values['issuer']);
        $this->assertSame('https://lms.example.com/moodle', $values['issuer']);
        $this->assertSame($claims->aud, $values['audience']);
        $this->assertSame($header['kid'], $values['kid']);
    }

    /**
     * The host origin has no path and the fingerprint matches the stored key.
     */
    public function test_host_origin_and_fingerprint(): void {
        global $CFG;
        $CFG->wwwroot = 'https://lms.example.com/moodle';

        $values = registration::values();

        $this->assertSame('https://lms.example.com', $values['hostorigin']);
        $stored = get_config('format_rumbos', 'publickey');
        $this->assertSame($stored, $values['publickey']);
        $der = base64_decode(preg_replace('/-----[A-Z ]+-----|\s+/', '', $stored), true);
        $this->assertSame(hash('sha256', $der), $values['fingerprint']);
    }

    /**
     * The command carries every value, passes the key id and has a single placeholder.
     */
    public function test_command_is_complete_and_has_one_placeholder(): void {
        global $CFG;
        $CFG->wwwroot = 'https://lms.example.com/moodle';

        $values = registration::values();
        $command = registration::command();

        $this->assertStringContainsString('python -m app.scripts.tenants add --name "<site name>"', $command);
        $this->assertStringContainsString('--issuer https://lms.example.com/moodle ', $command);
        $this->assertStringContainsString('--host-origin https://lms.example.com ', $command);
        $this->assertStringContainsString('--kid ' . $values['kid'] . ' ', $command);
        $this->assertStringContainsString('--public-key-file moodle.pub.pem', $command);
        $this->assertStringContainsString(trim($values['publickey']), $command);
        $this->assertSame(1, preg_match_all('/<[^>\n]+>/', $command), 'only the site name is a placeholder');
    }

    /**
     * The key file is written before the command that reads it.
     */
    public function test_command_writes_the_key_first(): void {
        $command = registration::command();

        $this->assertStringStartsWith("cat > moodle.pub.pem <<'EOF'\n-----BEGIN PUBLIC KEY-----", $command);
        $this->assertLessThan(strpos($command, 'python -m'), strpos($command, "\nEOF\n"));
    }

    /**
     * The command never contains the private key.
     */
    public function test_command_has_no_secret(): void {
        $command = registration::command();

        $this->assertStringNotContainsString('PRIVATE', $command);
        $this->assertStringNotContainsString(key_manager::get_private_key(), $command);
        $this->assertStringNotContainsString(key_manager::get_hmac_secret(), $command);
    }

    /**
     * Values that are not plainly safe are quoted for the shell.
     */
    public function test_unusual_values_are_quoted(): void {
        global $CFG;
        $CFG->wwwroot = "https://lms.example.com/it's here";

        $command = registration::command();

        $this->assertStringContainsString("--issuer 'https://lms.example.com/it'\\''s here'", $command);
    }
}
