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
            'firstname' => 'privacy:metadata:child:firstname',
            'lastname' => 'privacy:metadata:child:lastname',
            'groupname' => 'privacy:metadata:child:groupname',
            'status' => 'privacy:metadata:child:status',
            'timeleft' => 'privacy:metadata:child:timeleft',
        ], 'privacy:metadata:child');
        $collection->add_database_table('local_oksigeniafamily_claim', [
            'userid' => 'privacy:metadata:claim:userid',
            'usermodified' => 'privacy:metadata:claim:usermodified',
            'timecreated' => 'privacy:metadata:timecreated',
        ], 'privacy:metadata:claim');
        $collection->add_database_table('local_oksigeniafamily_request', [
            'usercreated' => 'privacy:metadata:request:usercreated',
            'timecreated' => 'privacy:metadata:timecreated',
        ], 'privacy:metadata:request');
        $collection->add_database_table('local_oksigeniafamily_target', [
            'userid' => 'privacy:metadata:target:userid',
        ], 'privacy:metadata:target');
        $collection->add_database_table('local_oksigeniafamily_answer', [
            'childuserid' => 'privacy:metadata:answer:childuserid',
            'adultname' => 'privacy:metadata:answer:adultname',
            'answer' => 'privacy:metadata:answer:answer',
            'comment' => 'privacy:metadata:answer:comment',
            'userid' => 'privacy:metadata:answer:userid',
            'address' => 'privacy:metadata:answer:address',
            'timecreated' => 'privacy:metadata:timecreated',
        ], 'privacy:metadata:answer');
        $collection->add_database_table('local_oksigeniafamily_photo', [
            'photokey' => 'privacy:metadata:photo:photokey',
            'timecreated' => 'privacy:metadata:timecreated',
        ], 'privacy:metadata:photo');
        $collection->add_database_table('local_oksigeniafamily_standing', [
            'childkey' => 'privacy:metadata:standing:childkey',
            'answer' => 'privacy:metadata:standing:answer',
            'adultid' => 'privacy:metadata:standing:adultid',
            'userid' => 'privacy:metadata:standing:userid',
            'timecreated' => 'privacy:metadata:timecreated',
        ], 'privacy:metadata:standing');
        $collection->add_subsystem_link('core_files', [], 'privacy:metadata:core_files');

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
            || $DB->record_exists('local_oksigeniafamily_request', ['usercreated' => $userid])
            || $DB->record_exists('local_oksigeniafamily_target', ['userid' => $userid])
            || $DB->record_exists('local_oksigeniafamily_answer', ['childuserid' => $userid])
            || $DB->record_exists('local_oksigeniafamily_answer', ['userid' => $userid])
            || self::photo_keys($userid)
            || self::standing_select($userid)[0] !== ''
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
        global $DB;

        if ($userlist->get_context()->contextlevel != CONTEXT_SYSTEM) {
            return;
        }
        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_oksigeniafamily_adult} WHERE userid IS NOT NULL', []);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_oksigeniafamily_child} WHERE userid IS NOT NULL', []);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_oksigeniafamily_claim}', []);
        $sql = 'SELECT usermodified FROM {local_oksigeniafamily_claim} WHERE usermodified > 0';
        $userlist->add_from_sql('usermodified', $sql, []);
        $sql = 'SELECT usercreated FROM {local_oksigeniafamily_request} WHERE usercreated > 0';
        $userlist->add_from_sql('usercreated', $sql, []);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_oksigeniafamily_target}', []);
        $userlist->add_from_sql('childuserid', 'SELECT childuserid FROM {local_oksigeniafamily_answer}', []);
        $sql = 'SELECT userid FROM {local_oksigeniafamily_answer} WHERE userid IS NOT NULL';
        $userlist->add_from_sql('userid', $sql, []);

        // Photos and standing consents: the students they are about (user:45), who wrote them down, and the adults with an
        // account who answered.
        $photolike = $DB->sql_like('photokey', ':k');
        $standinglike = $DB->sql_like('childkey', ':k');
        $keys = array_merge(
            $DB->get_fieldset_select('local_oksigeniafamily_photo', 'photokey', $photolike, ['k' => 'user:%']),
            $DB->get_fieldset_select('local_oksigeniafamily_standing', 'childkey', $standinglike, ['k' => 'user:%'])
        );
        $userids = [];
        foreach ($keys as $key) {
            if (preg_match('/^user:(\d+)$/', (string) $key, $match)) {
                $userids[] = (int) $match[1];
            }
        }
        $userlist->add_users(array_unique($userids));
        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_oksigeniafamily_standing} WHERE userid IS NOT NULL', []);
        $sql = 'SELECT a.userid
                  FROM {local_oksigeniafamily_standing} s
                  JOIN {local_oksigeniafamily_adult} a ON a.id = s.adultid
                 WHERE a.userid IS NOT NULL';
        $userlist->add_from_sql('userid', $sql, []);
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
            'consentrequests' => array_values($DB->get_records(
                'local_oksigeniafamily_request',
                ['usercreated' => $userid],
                '',
                'id, title, deadline, status, timecreated'
            )),
            'consentanswers' => array_values($DB->get_records_select(
                'local_oksigeniafamily_answer',
                'childuserid = :child OR userid = :user',
                ['child' => $userid, 'user' => $userid],
                'id',
                'id, requestid, childuserid, adultname, answer, comment, channel, address, timecreated'
            )),
        ];
        [$select, $params] = self::standing_select($userid);
        $data->standing = $select === '' ? [] : array_values($DB->get_records_select(
            'local_oksigeniafamily_standing',
            $select,
            $params,
            'id',
            'id, childkey, topic, answer, adultid, channel, timecreated'
        ));
        $writer = writer::with_context($context);
        $writer->export_data([get_string('pluginname', 'local_oksigeniafamily')], $data);
        foreach (self::photo_keys($userid) as $key) {
            $file = \local_oksigeniafamily\local\photos::stored($key);
            if ($file) {
                $writer->export_file(
                    [get_string('pluginname', 'local_oksigeniafamily'), get_string('privacy:photo', 'local_oksigeniafamily')],
                    $file
                );
            }
        }
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
        foreach (['adult', 'child', 'claim', 'family', 'answer', 'target', 'request', 'photo', 'standing'] as $table) {
            $DB->delete_records('local_oksigeniafamily_' . $table);
        }
        get_file_storage()->delete_area_files($context->id, 'local_oksigeniafamily', \local_oksigeniafamily\local\photos::AREA);
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

        // The photos and the standing consents about the person as a student, and what they answered as an adult; what
        // they only wrote down for the school stays, without who wrote it.
        foreach (self::photo_keys($userid) as $key) {
            \local_oksigeniafamily\local\photos::delete($key);
        }
        $keys = \local_oksigeniafamily\local\standing::keys_for_user($userid);
        [$in, $params] = $DB->get_in_or_equal($keys, SQL_PARAMS_NAMED);
        $DB->delete_records_select('local_oksigeniafamily_standing', "childkey $in", $params);
        $adultids = $DB->get_fieldset_select('local_oksigeniafamily_adult', 'id', 'userid = :userid', ['userid' => $userid]);
        if ($adultids) {
            [$in, $params] = $DB->get_in_or_equal($adultids, SQL_PARAMS_NAMED);
            $DB->delete_records_select('local_oksigeniafamily_standing', "adultid $in", $params);
        }
        $DB->set_field('local_oksigeniafamily_standing', 'userid', null, ['userid' => $userid]);

        $DB->delete_records('local_oksigeniafamily_adult', ['userid' => $userid]);
        $DB->delete_records('local_oksigeniafamily_child', ['userid' => $userid]);
        $DB->delete_records('local_oksigeniafamily_claim', ['userid' => $userid]);
        $DB->set_field('local_oksigeniafamily_claim', 'usermodified', 0, ['usermodified' => $userid]);

        // Requests for consent: what is about the student, and what the person answered, goes; a paper answer the person
        // only wrote down for the school stays, without who wrote it.
        $DB->delete_records('local_oksigeniafamily_answer', ['childuserid' => $userid]);
        $DB->delete_records('local_oksigeniafamily_target', ['userid' => $userid]);
        $DB->delete_records_select(
            'local_oksigeniafamily_answer',
            'userid = :userid AND channel <> :school',
            ['userid' => $userid, 'school' => 'school']
        );
        $DB->set_field('local_oksigeniafamily_answer', 'userid', null, ['userid' => $userid]);
        $DB->set_field('local_oksigeniafamily_request', 'usercreated', 0, ['usercreated' => $userid]);
    }

    /**
     * The keys of the photos about a person as a student that are kept here.
     *
     * @param int $userid The person.
     * @return string[]
     */
    private static function photo_keys(int $userid): array {
        global $DB;

        $keys = \local_oksigeniafamily\local\standing::keys_for_user($userid);
        [$in, $params] = $DB->get_in_or_equal($keys, SQL_PARAMS_NAMED);

        return $DB->get_fieldset_select('local_oksigeniafamily_photo', 'photokey', "photokey $in", $params);
    }

    /**
     * The standing consents of a person: about them as a student, answered by them as an adult, or written down by them.
     *
     * @param int $userid The person.
     * @return array The WHERE clause ('' if there are none) and its parameters.
     */
    private static function standing_select(int $userid): array {
        global $DB;

        $keys = \local_oksigeniafamily\local\standing::keys_for_user($userid);
        [$keyin, $params] = $DB->get_in_or_equal($keys, SQL_PARAMS_NAMED, 'key');
        $select = "childkey $keyin OR userid = :recorder";
        $params['recorder'] = $userid;
        $adultids = $DB->get_fieldset_select('local_oksigeniafamily_adult', 'id', 'userid = :userid', ['userid' => $userid]);
        if ($adultids) {
            [$adultin, $adultparams] = $DB->get_in_or_equal($adultids, SQL_PARAMS_NAMED, 'adult');
            $select .= " OR adultid $adultin";
            $params += $adultparams;
        }

        return $DB->record_exists_select('local_oksigeniafamily_standing', $select, $params) ? [$select, $params] : ['', []];
    }
}
