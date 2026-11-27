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

namespace format_rumbos;

/**
 * The upgrade from the version before the native quiz mirror was merged.
 *
 * The mirror was written under the version 2026112300. The ES256 receiver then took 2026112401, so a step kept under
 * the old number would never run on a site that has it, and that site would lack the mirror's tables.
 *
 * @package    format_rumbos
 * @copyright  2026 OBJ Digital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class upgrade_test extends \advanced_testcase {
    /** @var int The version of the ES256 receiver: the newest a site can have without the mirror. */
    private const BEFORE_MIRROR = 2026112401;

    /** @var string[] The tables the mirror adds. */
    private const TABLES = [
        'format_rumbos_mirror_quiz', 'format_rumbos_mirror_item', 'format_rumbos_mirror_attempt',
        'format_rumbos_mirror_log',
    ];

    /**
     * The columns and indexes of a table, in a form that two ways of making it can be compared in.
     *
     * @param string $table
     * @return array
     */
    private function shape(string $table): array {
        global $DB;

        $columns = [];
        foreach ($DB->get_columns($table, false) as $name => $column) {
            $columns[$name] = [$column->meta_type, $column->max_length, $column->scale, $column->not_null];
        }
        $indexes = [];
        foreach ($DB->get_indexes($table) as $index) {
            $indexes[] = ($index['unique'] ? 'unique ' : '') . implode(',', $index['columns']);
        }
        ksort($columns);
        sort($indexes);
        return ['columns' => $columns, 'indexes' => $indexes];
    }

    /**
     * Runs the plugin's upgrade function as the site upgrade would, from a version.
     *
     * @param int $from
     */
    private function upgrade_from(int $from): void {
        global $CFG;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/course/format/rumbos/db/upgrade.php');

        set_config('version', $from, 'format_rumbos');
        $this->assertTrue(xmldb_format_rumbos_upgrade($from));
    }

    /**
     * A site on the ES256 receiver gets the mirror's tables, the same ones a new installation gets.
     */
    public function test_a_site_on_the_es256_receiver_gets_the_mirror_tables(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $manager = $DB->get_manager();

        $installed = [];
        foreach (self::TABLES as $table) {
            $installed[$table] = $this->shape($table);
            $manager->drop_table(new \xmldb_table($table));
            $this->assertFalse($manager->table_exists($table));
        }

        $this->upgrade_from(self::BEFORE_MIRROR);

        foreach (self::TABLES as $table) {
            $this->assertTrue($manager->table_exists($table), $table);
            $this->assertSame($installed[$table], $this->shape($table), $table);
        }

        $plugin = new \stdClass();
        require($CFG->dirroot . '/course/format/rumbos/version.php');
        $this->assertGreaterThan(self::BEFORE_MIRROR, (int) get_config('format_rumbos', 'version'));
        $this->assertLessThanOrEqual($plugin->version, (int) get_config('format_rumbos', 'version'));
    }
}
