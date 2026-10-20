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

/**
 * The values an operator needs to register this site in the Rumbos service.
 *
 * Every value is read from the code that signs the assertions, never re-derived,
 * so what the admin panel shows is what the service will see in the assertion.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class registration {
    /** @var string The only placeholder the registration command contains. */
    public const NAME_PLACEHOLDER = '<site name>';

    /** @var string File name used for the public key in the command. */
    private const KEY_FILE = 'moodle.pub.pem';

    /**
     * The values to register.
     *
     * @return array{issuer: string, hostorigin: string, audience: string, kid: string,
     *     fingerprint: string, publickey: string}
     */
    public static function values(): array {
        return [
            'issuer' => launch_assertion::issuer(),
            'hostorigin' => config::get_host_origin(),
            'audience' => config::get_audience(),
            'kid' => key_manager::get_kid(),
            'fingerprint' => key_manager::get_fingerprint(),
            'publickey' => key_manager::get_public_key(),
        ];
    }

    /**
     * A copy-ready shell command that registers this site with the Rumbos CLI.
     *
     * It writes the public key to a file and calls `python -m app.scripts.tenants add`.
     * The key id is always passed: without it the service derives its own default from
     * the key, which is not the id this plugin puts in the assertion header, and every
     * launch would fail with an unknown key. The audience is not a flag; it is a
     * setting of the service and must equal the one shown here.
     *
     * The flags follow the backend CLI at the time of writing and may change until it
     * is final, which is why the panel asks the operator to check them with `--help`.
     *
     * @return string
     */
    public static function command(): string {
        $values = self::values();

        return "cat > " . self::KEY_FILE . " <<'EOF'\n"
            . trim($values['publickey']) . "\n"
            . "EOF\n"
            . 'python -m app.scripts.tenants add --name "' . self::NAME_PLACEHOLDER . "\" \\\n"
            . '  --issuer ' . self::shell_word($values['issuer']) . " \\\n"
            . '  --host-origin ' . self::shell_word($values['hostorigin']) . " \\\n"
            . '  --kid ' . self::shell_word($values['kid']) . " \\\n"
            . '  --public-key-file ' . self::KEY_FILE;
    }

    /**
     * Quotes a value for a POSIX shell, leaving it bare when it is plainly safe.
     *
     * @param string $value
     * @return string
     */
    private static function shell_word(string $value): string {
        if (preg_match('#^[A-Za-z0-9._~:/@%+=,-]+$#', $value)) {
            return $value;
        }
        return "'" . str_replace("'", "'\\''", $value) . "'";
    }
}
