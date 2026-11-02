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
 * Upgrades of the plugin's tables.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the plugin.
 *
 * @param int $oldversion The version installed.
 * @return bool
 */
function xmldb_local_oksigeniafamily_upgrade(int $oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026110201) {
        // Whether the phone is known to be theirs: from the school or WhatsApp, not just typed on the claim page.
        $table = new xmldb_table('local_oksigeniafamily_adult');
        $field = new xmldb_field('phoneverified', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'phone');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026110201, 'local', 'oksigeniafamily');
    }

    if ($oldversion < 2026110203) {
        // Requests for consent: the request, the students it is about and every answer.
        foreach (['local_oksigeniafamily_request', 'local_oksigeniafamily_target', 'local_oksigeniafamily_answer'] as $name) {
            if (!$dbman->table_exists($name)) {
                $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', $name);
            }
        }
        upgrade_plugin_savepoint(true, 2026110203, 'local', 'oksigeniafamily');
    }

    return true;
}
