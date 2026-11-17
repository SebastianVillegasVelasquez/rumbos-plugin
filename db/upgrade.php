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
 * Upgrade steps of the Rumbos course format.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrades the plugin.
 *
 * @param int $oldversion The version installed so far.
 * @return bool
 */
function xmldb_format_rumbos_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026111100) {
        // The progress of each user through a welcome flow.
        $table = new xmldb_table('format_rumbos_flow');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('mapid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
        $table->add_field('stepkey', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, null);
        $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('method', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecompleted', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('coursemapuserstep', XMLDB_KEY_UNIQUE, ['courseid', 'userid', 'mapid', 'stepkey']);
        $table->add_index('courseuser', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'userid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026111100, 'format', 'rumbos');
    }

    if ($oldversion < 2026111700) {
        // The results applied from verified statements of the Rumbos service, and the audit of every statement.
        $table = new xmldb_table('format_rumbos_result');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('purpose', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, null);
        $table->add_field('attemptid', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('jti', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('payloadhash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('percent', XMLDB_TYPE_CHAR, '6', null, XMLDB_NOTNULL, null, null);
        $table->add_field('passed', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timefinished', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timeapplied', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('attemptid', XMLDB_KEY_UNIQUE, ['attemptid']);
        $table->add_key('jti', XMLDB_KEY_UNIQUE, ['jti']);
        $table->add_index('courseuser', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'userid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $table = new xmldb_table('format_rumbos_audit');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('status', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('reason', XMLDB_TYPE_CHAR, '48', null, XMLDB_NOTNULL, null, null);
        $table->add_field('kid', XMLDB_TYPE_CHAR, '32', null, null, null, null);
        $table->add_field('jti', XMLDB_TYPE_CHAR, '64', null, null, null, null);
        $table->add_field('attemptid', XMLDB_TYPE_CHAR, '64', null, null, null, null);
        $table->add_field('subject', XMLDB_TYPE_CHAR, '64', null, null, null, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('purpose', XMLDB_TYPE_CHAR, '12', null, null, null, null);
        $table->add_field('percent', XMLDB_TYPE_CHAR, '6', null, null, null, null);
        $table->add_field('passed', XMLDB_TYPE_INTEGER, '1', null, null, null, null);
        $table->add_field('policy', XMLDB_TYPE_CHAR, '24', null, null, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('timecreated', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
        $table->add_index('courseuser', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'userid']);
        $table->add_index('jti', XMLDB_INDEX_NOTUNIQUE, ['jti']);
        $table->add_index('attemptid', XMLDB_INDEX_NOTUNIQUE, ['attemptid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026111700, 'format', 'rumbos');
    }

    return true;
}
