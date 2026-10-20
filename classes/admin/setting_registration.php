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

use core\context\system as context_system;
use format_rumbos\local\key_manager;
use format_rumbos\local\registration;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/adminlib.php');

/**
 * Read-only panel with everything needed to register this site in the Rumbos
 * service: the issuer exactly as it is signed, the audience, the key id, the
 * public key and its fingerprint, a registration command and a diagnostic.
 *
 * It never writes anything and never reaches the private key or the pseudonym
 * secret. It renders nothing for anyone without moodle/site:config.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_registration extends \admin_setting {
    /**
     * Constructor.
     *
     * @param string $name Setting name including the component.
     * @param string $visiblename
     * @param string $description
     */
    public function __construct(string $name, string $visiblename, string $description) {
        parent::__construct($name, $visiblename, $description, '');
    }

    /**
     * Nothing is stored under this setting's own name.
     *
     * @return bool
     */
    public function get_setting() {
        return true;
    }

    /**
     * Read-only: ignores submitted data.
     *
     * @param mixed $data
     * @return string
     */
    public function write_setting($data) {
        return '';
    }

    /**
     * Renders the panel, for site administrators only.
     *
     * @param mixed $data
     * @param string $query
     * @return string
     */
    public function output_html($data, $query = '') {
        global $OUTPUT;

        if (!has_capability('moodle/site:config', context_system::instance())) {
            return '';
        }

        $html = $OUTPUT->render_from_template('format_rumbos/registration', $this->template_context());

        return format_admin_setting($this, $this->visiblename, $html, $this->description, true, '', null, $query);
    }

    /**
     * The data for the template.
     *
     * @return array
     */
    private function template_context(): array {
        $values = registration::values();
        $s = fn(string $id): string => get_string($id, 'format_rumbos');
        $healthy = key_manager::is_healthy();

        return [
            'unhealthy' => !$healthy,
            'unhealthymessage' => $s('keyunhealthy'),
            'reseturl' => (new \moodle_url('/course/format/rumbos/rotatekey.php', ['reset' => 1]))->out(false),
            'resetlabel' => $s('resetkeys'),
            'rows' => [
                ['id' => 'issuer', 'label' => $s('regissuer'), 'value' => $values['issuer'], 'note' => $s('regissuer_note')],
                [
                    'id' => 'hostorigin',
                    'label' => $s('reghostorigin'),
                    'value' => $values['hostorigin'],
                    'note' => $s('reghostorigin_note'),
                ],
                [
                    'id' => 'audience',
                    'label' => $s('regaudience'),
                    'value' => $values['audience'],
                    'note' => $s('regaudience_note'),
                ],
                ['id' => 'kid', 'label' => $s('regkid'), 'value' => $values['kid'], 'note' => $s('regkid_note')],
                [
                    'id' => 'fingerprint',
                    'label' => $s('regfingerprint'),
                    'value' => $values['fingerprint'],
                    'note' => $s('regfingerprint_note'),
                ],
            ],
            'publickeylabel' => $s('regpublickey'),
            'publickey' => trim($values['publickey']),
            'commandlabel' => $s('regcommand'),
            'command' => registration::command(),
            'commandnote' => $s('regcommand_note'),
            'copylabel' => $s('copyvalue'),
            'copiedmessage' => $s('copied'),
            'rotateurl' => (new \moodle_url('/course/format/rumbos/rotatekey.php'))->out(false),
            'rotatelabel' => $s('rotatekey'),
            'previewlabel' => $s('previewassertion'),
            'previewwarning' => $s('previewwarning'),
            'issuedlabel' => $s('previewissued'),
            'expireslabel' => $s('previewexpires'),
        ];
    }
}
