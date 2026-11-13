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

namespace local_oksigeniafamily\local;

use local_oksigeniafamily\api;

/**
 * Moodle's parent role, kept in step with the family records: each adult with an account gets the role the site chooses
 * over each of their children, and loses it when either leaves. The assignments carry this plugin as their component
 * and the family as their item, so the ones the school gives by hand are never touched.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class roles {
    /** The component of the role assignments this plugin makes. */
    public const COMPONENT = 'local_oksigeniafamily';

    /**
     * Make a family's role assignments match the family: add the missing ones, remove the rest.
     *
     * @param int $familyid The family.
     * @return void
     */
    public static function sync_family(int $familyid): void {
        global $DB;

        $roleid = (int) get_config('local_oksigeniafamily', 'parentroleid');
        $wanted = [];
        $family = $DB->get_record('local_oksigeniafamily_family', ['id' => $familyid]);
        if ($roleid && $family && $family->status === api::ACTIVE) {
            $active = 'familyid = :familyid AND status = :active AND userid IS NOT NULL';
            $params = ['familyid' => $familyid, 'active' => api::ACTIVE];
            $adults = $DB->get_fieldset_select('local_oksigeniafamily_adult', 'userid', $active, $params);
            $children = $DB->get_fieldset_select('local_oksigeniafamily_child', 'userid', $active, $params);
            // An account that is itself a child in a family (a student who claimed a classmate's code) is never a
            // parent of anyone.
            if ($adults) {
                [$insql, $inparams] = $DB->get_in_or_equal(array_map('intval', $adults), SQL_PARAMS_NAMED, 'adult');
                $students = $DB->get_fieldset_select(
                    'local_oksigeniafamily_child',
                    'userid',
                    "status = :childactive AND userid $insql",
                    ['childactive' => api::ACTIVE] + $inparams
                );
                $adults = array_diff(array_map('intval', $adults), array_map('intval', $students));
            }
            foreach ($children as $childuserid) {
                $context = \context_user::instance((int) $childuserid, IGNORE_MISSING);
                if (!$context) {
                    continue;
                }
                foreach ($adults as $adultuserid) {
                    if ((int) $adultuserid !== (int) $childuserid) {
                        $wanted["{$adultuserid}:{$context->id}"] = [(int) $adultuserid, (int) $context->id];
                    }
                }
            }
        }

        $existing = $DB->get_records(
            'role_assignments',
            ['component' => self::COMPONENT, 'itemid' => $familyid],
            '',
            'id, roleid, userid, contextid'
        );
        foreach ($existing as $assignment) {
            $key = "{$assignment->userid}:{$assignment->contextid}";
            if ((int) $assignment->roleid === $roleid && isset($wanted[$key])) {
                unset($wanted[$key]);
                continue;
            }
            role_unassign($assignment->roleid, $assignment->userid, $assignment->contextid, self::COMPONENT, $familyid);
        }
        foreach ($wanted as [$userid, $contextid]) {
            role_assign($roleid, $userid, $contextid, self::COMPONENT, $familyid);
        }
    }

    /**
     * Bring every family's role assignments up to date, also those of families that are gone (after the site changes
     * the role, for instance).
     *
     * @return void
     */
    public static function sync_all(): void {
        global $DB;

        $families = $DB->get_fieldset_select('local_oksigeniafamily_family', 'id', '');
        $assigned = $DB->get_fieldset_sql(
            'SELECT DISTINCT itemid FROM {role_assignments} WHERE component = :component',
            ['component' => self::COMPONENT]
        );
        foreach (array_unique(array_merge($families, $assigned)) as $familyid) {
            self::sync_family((int) $familyid);
        }
    }
}
