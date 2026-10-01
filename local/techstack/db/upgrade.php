<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Plugin upgrade steps are defined here.
 *
 * @package     local_techstack
 * @category    upgrade
 * @copyright   2025 Your Name <you@example.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__.'/upgradelib.php');

/**
 * Execute local_techstack upgrade from the given old version.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_techstack_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    // For further information please read {@link https://docs.moodle.org/dev/Upgrade_API}.
    //
    // You will also have to create the db/install.xml file by using the XMLDB Editor.
    // Documentation for the XMLDB Editor can be found at {@link https://docs.moodle.org/dev/XMLDB_editor}.
         
     if ($oldversion < 2025071527) {

        // Define table local_quizcustomfield.
        $table = new xmldb_table('local_quizcustomfield');

        // Define fields.
        $table->add_field(
            'id',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            XMLDB_SEQUENCE,
            null
        );

        $table->add_field(
            'cmid',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            null
        );

        $table->add_field(
            'customtext',
            XMLDB_TYPE_TEXT,
            null,
            null,
            null,
            null,
            null
        );

        // Define keys.
        $table->add_key(
            'primary',
            XMLDB_KEY_PRIMARY,
            ['id']
        );

        $table->add_key(
            'cmid',
            XMLDB_KEY_UNIQUE,
            ['cmid']
        );

        // Create table if it does not already exist.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(
            true,
            2025071527,
            'local',
            'techstack'
        );
    }



   if ($oldversion < 2025071531) {

        $table = new xmldb_table('local_techstack_course');

        // Fields.
        $table->add_field(
            'id',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            XMLDB_SEQUENCE,
            null
        );

        $table->add_field(
            'courseid',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            null
        );

        $table->add_field(
            'customtext',
            XMLDB_TYPE_TEXT,
            null,
            null,
            null,
            null,
            null
        );

        // Keys.
        $table->add_key(
            'primary',
            XMLDB_KEY_PRIMARY,
            ['id']
        );
        
         $table->add_key(
            'courseid',
            XMLDB_KEY_UNIQUE,
            ['courseid']
        );

        // Create table.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Savepoint.
        upgrade_plugin_savepoint(
            true,
            2025071531,
            'local',
            'techstack'
        );
    }




    return true;
}
