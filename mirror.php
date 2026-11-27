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

/**
 * The mirror receiver: where the Rumbos backend posts a signed exam definition or exam attempt for the native quiz mirror.
 *
 * No session, no cookies and no user: the only thing that opens this script is a message signed with a key the
 * administrator configured, and it never grants a grade or a completion. The browser never calls it. See
 * docs/NATIVE-QUIZ-MIRROR.md.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_MOODLE_COOKIES', true);
define('AJAX_SCRIPT', true);
define('NO_DEBUG_DISPLAY', true);

require(__DIR__ . '/../../../config.php');

$reply = \format_rumbos\local\mirror\mirror_receiver::handle(
    $_SERVER['REQUEST_METHOD'] ?? '',
    (string) file_get_contents('php://input', false, null, 0, \format_rumbos\local\mirror\mirror_receiver::MAX_BODY + 1)
);

http_response_code($reply['status']);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
foreach ($reply['headers'] as $name => $value) {
    header($name . ': ' . $value);
}
echo json_encode($reply['body']);
