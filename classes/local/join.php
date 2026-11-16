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
 * «For your family»: a student's own page with the QR their family joins with, the code and the steps, on the site and
 * in the app. Every student has it, not only those who eat at the canteen, and it waits in the top bar, on the profile
 * and in the app's menu; in Oksigenia Aty, a card on the home until somebody of the family joins.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class join {
    /**
     * Whether this person gets the page: a student of the site, or a child of a family. Not a teacher or anyone who runs
     * courses, nor an adult of a family, nor a family's own account in the app; and only where the centre has it on.
     *
     * @param int $userid The person.
     * @return bool
     */
    public static function eligible(int $userid): bool {
        global $DB;

        if (!get_config('local_oksigeniafamily', 'studentpage') || !api::is_available() || is_siteadmin($userid)) {
            return false;
        }
        $user = \core_user::get_user($userid);
        $out = !$user || $user->deleted || $user->suspended || isguestuser($user)
            || in_array($user->auth, ['webservice', 'nologin'], true);
        if ($out) {
            return false;
        }
        if ($DB->record_exists('local_oksigeniafamily_adult', ['userid' => $userid, 'status' => api::ACTIVE])) {
            return false;
        }
        $archetypes = "archetype IN ('editingteacher', 'teacher', 'manager', 'coursecreator')";
        $staff = $DB->get_fieldset_select('role', 'id', $archetypes);
        if ($staff) {
            [$in, $params] = $DB->get_in_or_equal($staff, SQL_PARAMS_NAMED);
            if ($DB->record_exists_select('role_assignments', "userid = :userid AND roleid $in", ['userid' => $userid] + $params)) {
                return false;
            }
        }
        if ($DB->record_exists('local_oksigeniafamily_child', ['userid' => $userid, 'status' => api::ACTIVE])) {
            return true;
        }
        $students = $DB->get_fieldset_select('role', 'id', "archetype = 'student'");
        if (!$students) {
            return false;
        }
        [$in, $params] = $DB->get_in_or_equal($students, SQL_PARAMS_NAMED);

        return $DB->record_exists_select('role_assignments', "userid = :userid AND roleid $in", ['userid' => $userid] + $params);
    }

    /**
     * What the page shows, for the site's template and the app's.
     *
     * @param int $userid The student.
     * @return array|null The context; null when there is nothing to show.
     */
    public static function context(int $userid): ?array {
        $join = api::join_for_student($userid);
        if (!$join) {
            return null;
        }
        $component = 'local_oksigeniafamily';
        $phones = [];
        foreach (['ios' => 'iPhone', 'android' => 'Android'] as $key => $name) {
            $phones[] = ['name' => $name, 'steps' => $join['steps'][$key]];
        }
        $same = $join['steps']['ios'] === $join['steps']['android'];
        if ($join['inapp'] > 0) {
            $status = get_string('join:inapp', $component);
        } else if ($join['adults'] > 0) {
            $status = get_string('join:joined', $component);
        } else {
            $status = '';
        }

        return [
            'heading' => get_string('join:title', $component),
            'intro' => get_string('join:intro', $component),
            'qrsvg' => $join['qrsvg'],
            'codelabel' => get_string('letter:code', $component),
            'code' => $join['code'],
            'address' => $join['address'],
            'addresslabel' => get_string('join:address', $component),
            'expires' => get_string('letter:expires', $component, userdate(
                $join['expires'],
                get_string('strftimedate', 'langconfig')
            )),
            'status' => $status,
            'hasstatus' => $status !== '',
            'phones' => $same ? [] : $phones,
            'twophones' => !$same,
            'steps' => $same ? $join['steps']['ios'] : [],
            'care' => get_string('join:care', $component),
        ];
    }

    /**
     * The top bar of the site: «Family», for whoever has the page.
     *
     * @param \core\hook\navigation\primary_extend $hook The hook.
     * @return void
     */
    public static function primary_extend(\core\hook\navigation\primary_extend $hook): void {
        global $USER;

        if (during_initial_install() || !isloggedin() || isguestuser() || !self::eligible((int) $USER->id)) {
            return;
        }
        $hook->get_primaryview()->add(
            get_string('join:nav', 'local_oksigeniafamily'),
            new \moodle_url('/local/oksigeniafamily/join.php'),
            \navigation_node::TYPE_CUSTOM,
            null,
            'local_oksigeniafamily_join'
        );
    }

    /**
     * The card on the home of Oksigenia Aty, until somebody of the family joins: it opens the page.
     *
     * @param int $userid The student.
     * @return array The card, or none.
     */
    public static function home_card(int $userid): array {
        if (!self::eligible($userid) || api::adults_of_child($userid)) {
            return [];
        }
        $component = 'local_oksigeniafamily';

        return [[
            'heading' => get_string('join:title', $component),
            'items' => [[
                'title' => get_string('join:cardtitle', $component),
                'detail' => get_string('join:carddetail', $component),
                'method' => 'mobile_join_view',
                'args' => [],
                'pagetitle' => get_string('join:title', $component),
            ]],
        ]];
    }
}
