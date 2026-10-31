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

namespace local_oksigeniafamily\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for local_oksigeniafamily.
 *
 * The records hold the adults of each household (with or without a Moodle account) and the students who are their
 * children. What belongs to a Moodle account is exported and deleted with it; adults without an account are erased with
 * their family once it has been closed for the retention period.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {

    /**
     * What the plugin stores.
     *
     * @param collection $collection The collection to add to.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_oksigeniafamily_adult', [
            'firstname' => 'privacy:metadata:adult:firstname',
            'lastname' => 'privacy:metadata:adult:lastname',
            'email' => 'privacy:metadata:adult:email',
            'phone' => 'privacy:metadata:adult:phone',
            'sourcekey' => 'privacy:metadata:sourcekey',
            'userid' => 'privacy:metadata:adult:userid',
            'timecreated' => 'privacy:metadata:timecreated',
        ], 'privacy:metadata:adult');
        $collection->add_database_table('local_oksigeniafamily_child', [
            'userid' => 'privacy:metadata:child:userid',
            'sourcekey' => 'privacy:metadata:sourcekey',
            'status' => 'privacy:metadata:child:status',
            'timeleft' => 'privacy:metadata:child:timeleft',
        ], 'privacy:metadata:child');
        $collection->add_database_table('local_oksigeniafamily_claim', [
            'userid' => 'privacy:metadata:claim:userid',
            'usermodified' => 'privacy:metadata:claim:usermodified',
            'timecreated' => 'privacy:metadata:timecreated',
        ], 'privacy:metadata:claim');

        return $collection;
    }

    /**
     * The contexts with data of a user: the system context, where the records live.
     *
     * @param int $userid The user.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $contextlist = new contextlist();
        if (
            $DB->record_exists('local_oksigeniafamily_adult', ['userid' => $userid])
            || $DB->record_exists('local_oksigeniafamily_child', ['userid' => $userid])
            || $DB->record_exists('local_oksigeniafamily_claim', ['userid' => $userid])
            || $DB->record_exists('local_oksigeniafamily_claim', ['usermodified' => $userid])
        ) {
            $contextlist->add_system_context();
        }

        return $contextlist;
    }

    /**
     * The users with data in a context.
     *
     * @param userlist $userlist The list to fill.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        if ($userlist->get_context()->contextlevel != CONTEXT_SYSTEM) {
            return;
        }
        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_oksigeniafamily_adult} WHERE userid IS NOT NULL', []);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_oksigeniafamily_child} WHERE userid IS NOT NULL', []);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_oksigeniafamily_claim}', []);
        $sql = 'SELECT usermodified FROM {local_oksigeniafamily_claim} WHERE usermodified > 0';
        $userlist->add_from_sql('usermodified', $sql, []);
    }

    /**
     * Export a user's family records.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $context = \context_system::instance();
        if (!in_array($context->id, $contextlist->get_contextids())) {
            return;
        }
        $userid = (int) $contextlist->get_user()->id;
        $fields = 'familyid, firstname, lastname, email, phone, sourcekey, status, timecreated';
        $data = (object) [
            'adult' => array_values($DB->get_records('local_oksigeniafamily_adult', ['userid' => $userid], '', 'id, ' . $fields)),
            'child' => array_values($DB->get_records(
                'local_oksigeniafamily_child',
                ['userid' => $userid],
                '',
                'id, familyid, sourcekey, status, timecreated, timeleft'
            )),
            'claims' => array_values($DB->get_records(
                'local_oksigeniafamily_claim',
                ['userid' => $userid],
                '',
                'id, status, claims, expires, timecreated'
            )),
        ];
        writer::with_context($context)->export_data([get_string('pluginname', 'local_oksigeniafamily')], $data);
    }

    /**
     * Delete everything in a context: all the family records, for the system context.
     *
     * @param \context $context The context.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if ($context->contextlevel != CONTEXT_SYSTEM) {
            return;
        }
        foreach (['adult', 'child', 'claim', 'family'] as $table) {
            $DB->delete_records('local_oksigeniafamily_' . $table);
        }
    }

    /**
     * Delete a user's data.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        if (in_array(\context_system::instance()->id, $contextlist->get_contextids())) {
            self::delete_user((int) $contextlist->get_user()->id);
        }
    }

    /**
     * Delete the data of several users in a context.
     *
     * @param approved_userlist $userlist The approved users.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        if ($userlist->get_context()->contextlevel != CONTEXT_SYSTEM) {
            return;
        }
        foreach ($userlist->get_userids() as $userid) {
            self::delete_user((int) $userid);
        }
    }

    /**
     * Remove a user from the records: as an adult, as a child, and the codes for them.
     *
     * @param int $userid The user.
     * @return void
     */
    private static function delete_user(int $userid): void {
        global $DB;

        $DB->delete_records('local_oksigeniafamily_adult', ['userid' => $userid]);
        $DB->delete_records('local_oksigeniafamily_child', ['userid' => $userid]);
        $DB->delete_records('local_oksigeniafamily_claim', ['userid' => $userid]);
        $DB->set_field('local_oksigeniafamily_claim', 'usermodified', 0, ['usermodified' => $userid]);
    }
}
