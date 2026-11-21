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

        return self::is_student($userid);
    }

    /**
     * Whether an account is a student's: a child of a family, or a student somewhere and nothing that runs courses (a
     * teacher in a course for the staff is enrolled as a student there too).
     *
     * @param int $userid The account.
     * @return bool
     */
    public static function is_student(int $userid): bool {
        global $DB;

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
     * Whether the top bar shows «Family» to a person: eligible(), kept for a minute, since the bar asks on every page of
     * everyone. The page itself and the app ask eligible().
     *
     * @param int $userid The person.
     * @return bool
     */
    public static function in_bar(int $userid): bool {
        $cache = \cache::make('local_oksigeniafamily', 'joinbar');
        $kept = $cache->get($userid);
        if ($kept === 'y' || $kept === 'n') {
            return $kept === 'y';
        }
        $eligible = self::eligible($userid);
        $cache->set($userid, $eligible ? 'y' : 'n');

        return $eligible;
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
        if ($join['inapp'] > 0) {
            $status = get_string('join:inapp', $component);
        } else if ($join['adults'] > 0) {
            $status = get_string('join:joined', $component);
        } else {
            $status = '';
        }

        return self::build($join, 'join', $status);
    }

    /**
     * What an adult of a family shows another adult of the household to bring them in: the invitation of their family,
     * which joins it with all its children (so the step of adding another child is not there).
     *
     * @param int $userid The adult.
     * @return array|null The context; null for whoever is not an adult of a family with children.
     */
    public static function invite_context(int $userid): ?array {
        foreach (api::families_of_user($userid) as $family) {
            $invite = api::invite_for_family((int) $family->familyid);
            if ($invite) {
                foreach ($invite['steps'] as $phone => $steps) {
                    $invite['steps'][$phone] = array_values(array_filter($steps, fn($step) => $step['kind'] !== 'siblings'));
                }
                return self::build($invite, 'invite', '') + self::invite_extras($invite, (int) $family->familyid, $userid);
            }
        }

        return null;
    }

    /**
     * What only the invitation has: the message to send it in (by WhatsApp, or copied into any other app), and the adults
     * of the family who have already joined, so that the one who invites sees who is in.
     *
     * @param array $invite From api::invite_for_family().
     * @param int $familyid The family.
     * @param int $userid Who looks at it.
     * @return array share (text, whatsappurl and the words of the buttons), adults (each with initial, name and you) and
     *     hasadults, adultsheading.
     */
    private static function invite_extras(array $invite, int $familyid, int $userid): array {
        global $DB;

        $component = 'local_oksigeniafamily';
        $text = get_string('invite:message', $component, (object) [
            'site' => format_string(get_site()->fullname),
            'url' => $invite['url'],
            'code' => $invite['code'],
        ]);
        // Those who have joined: not those the school's data brought who have not come in yet.
        $records = $DB->get_records(
            'local_oksigeniafamily_adult',
            ['familyid' => $familyid, 'status' => api::ACTIVE, 'quiet' => 0],
            'id',
            'id, firstname, lastname, email, phone, userid'
        );
        $adults = [];
        foreach ($records as $adult) {
            $name = families::adult_name($adult);
            $adults[] = [
                'initial' => \core_text::strtoupper(\core_text::substr($name, 0, 1)),
                'name' => $name,
                'you' => (int) $adult->userid === $userid,
            ];
        }

        return [
            'share' => [
                'text' => $text,
                'whatsappurl' => 'https://wa.me/?text=' . rawurlencode($text),
                'whatsapp' => get_string('invite:whatsapp', $component),
                'copy' => get_string('invite:copy', $component),
                'copied' => get_string('invite:copied', $component),
            ],
            'adultsheading' => get_string('invite:adults', $component),
            'adults' => $adults,
            'hasadults' => !empty($adults),
            'youlabel' => get_string('invite:you', $component),
        ];
    }

    /**
     * The context of the templates, from what api gives.
     *
     * @param array $join From join_for_student() or invite_for_family().
     * @param string $prefix join or invite: its words.
     * @param string $status What the page says on top ('' for nothing).
     * @return array
     */
    private static function build(array $join, string $prefix, string $status): array {
        $component = 'local_oksigeniafamily';
        $phones = [];
        foreach (['ios' => 'iPhone', 'android' => 'Android'] as $key => $name) {
            $phones[] = ['name' => $name, 'steps' => $join['steps'][$key]];
        }
        $same = $join['steps']['ios'] === $join['steps']['android'];

        return [
            'heading' => get_string($prefix . ':title', $component),
            'intro' => get_string($prefix . ':intro', $component),
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
            'care' => get_string($prefix . ':care', $component),
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

        if (during_initial_install() || !isloggedin() || isguestuser() || !self::in_bar((int) $USER->id)) {
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
