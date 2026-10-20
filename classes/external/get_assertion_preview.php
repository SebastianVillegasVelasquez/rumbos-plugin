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

namespace format_rumbos\external;

use core\context\system as context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use format_rumbos\local\launch_assertion;

/**
 * External function for administrators: the decoded header and claims of a fresh
 * assertion, for diagnosing a registration problem.
 *
 * It signs a real assertion for the calling administrator, decodes it on the
 * server and returns only the header and the claims. The compact token and the
 * signature never leave the server, and the token is discarded, so nothing
 * returned can be replayed.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_assertion_preview extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(
                PARAM_INT,
                'Course the sample is for. 0 picks a Rumbos course, or the site if there is none',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    /**
     * Builds the sample for the current administrator.
     *
     * @param int $courseid
     * @return array
     */
    public static function execute(int $courseid = 0): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        $token = launch_assertion::create(self::pick_course($params['courseid']), (int) $USER->id)['token'];

        return self::decode($token);
    }

    /**
     * The course the sample assertion is for.
     *
     * @param int $courseid Requested course, or 0 to choose one.
     * @return int
     */
    private static function pick_course(int $courseid): int {
        global $DB;

        if ($courseid > 0) {
            if (!$DB->record_exists('course', ['id' => $courseid])) {
                throw new \invalid_parameter_exception('Unknown course');
            }
            return $courseid;
        }
        $rumbos = $DB->get_records_select('course', 'format = :format', ['format' => 'rumbos'], 'id DESC', 'id', 0, 1);
        return $rumbos ? (int) reset($rumbos)->id : SITEID;
    }

    /**
     * Decodes the header and the claims of a compact token, never touching the signature.
     *
     * The result is built field by field, so nothing else can slip into it.
     *
     * @param string $token A compact JWS.
     * @return array
     */
    private static function decode(string $token): array {
        $parts = explode('.', $token);
        $header = json_decode(\Firebase\JWT\JWT::urlsafeB64Decode($parts[0]), true);
        $claims = json_decode(\Firebase\JWT\JWT::urlsafeB64Decode($parts[1]), true);

        return [
            'header' => [
                'alg' => (string) $header['alg'],
                'typ' => (string) $header['typ'],
                'kid' => (string) $header['kid'],
            ],
            'claims' => [
                'iss' => (string) $claims['iss'],
                'aud' => (string) $claims['aud'],
                'sub' => (string) $claims['sub'],
                'course' => (int) $claims['course'],
                'role' => (string) $claims['role'],
                'iat' => (int) $claims['iat'],
                'exp' => (int) $claims['exp'],
                'jti' => (string) $claims['jti'],
            ],
            'lifetime' => (int) $claims['exp'] - (int) $claims['iat'],
        ];
    }

    /**
     * Return structure: the decoded header and claims, and nothing else.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'header' => new external_single_structure([
                'alg' => new external_value(PARAM_ALPHANUM, 'Signing algorithm'),
                'typ' => new external_value(PARAM_ALPHA, 'Token type'),
                'kid' => new external_value(PARAM_RAW, 'Key id'),
            ]),
            'claims' => new external_single_structure([
                'iss' => new external_value(PARAM_RAW, 'Issuer'),
                'aud' => new external_value(PARAM_RAW, 'Audience'),
                'sub' => new external_value(PARAM_ALPHANUM, 'Pseudonymous user id'),
                'course' => new external_value(PARAM_INT, 'Course id'),
                'role' => new external_value(PARAM_ALPHA, 'editor or student'),
                'iat' => new external_value(PARAM_INT, 'Issued at'),
                'exp' => new external_value(PARAM_INT, 'Expires at'),
                'jti' => new external_value(PARAM_ALPHANUM, 'Unique id of the assertion'),
            ]),
            'lifetime' => new external_value(PARAM_INT, 'Seconds between issue and expiry'),
        ]);
    }
}
