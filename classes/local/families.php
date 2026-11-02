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
 * The family records as the school's pages read them: the list with its search, and one family with everything in it.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class families {
    /** Families per page of the list. */
    public const PERPAGE = 25;

    /**
     * How many there are of each.
     *
     * @return int[] active and closed (families), adults and children (of the active ones).
     */
    public static function counts(): array {
        global $DB;

        $counts = [
            'active' => $DB->count_records('local_oksigeniafamily_family', ['status' => api::ACTIVE]),
            'closed' => $DB->count_records('local_oksigeniafamily_family', ['status' => api::CLOSED]),
        ];
        foreach (['adults' => 'adult', 'children' => 'child'] as $name => $table) {
            $counts[$name] = $DB->count_records_sql(
                "SELECT COUNT(1)
                   FROM {local_oksigeniafamily_$table} t
                   JOIN {local_oksigeniafamily_family} f ON f.id = t.familyid AND f.status = :factive
                  WHERE t.status = :active",
                ['factive' => api::ACTIVE, 'active' => api::ACTIVE]
            );
        }

        return $counts;
    }

    /**
     * A page of the list, with the names of who is in each family, and nothing else about them: the phones, the e-mails
     * and the codes stay in each family's own page.
     *
     * @param string $query What was typed: names of adults or students, a phone, an e-mail, a key.
     * @param string $status active or closed.
     * @param int $page The page, from 0.
     * @return array total, and families: each with id, status, source, adults and children (names).
     */
    public static function search(string $query, string $status, int $page): array {
        global $DB;

        [$where, $params] = self::where($query, $status);
        $total = $DB->count_records_sql("SELECT COUNT(1) FROM {local_oksigeniafamily_family} f WHERE $where", $params);
        $sql = "SELECT f.id, f.status, f.source, f.sourcekey, f.timecreated,
                       (SELECT MIN(sa.lastname) FROM {local_oksigeniafamily_adult} sa WHERE sa.familyid = f.id) AS sortname
                  FROM {local_oksigeniafamily_family} f
                 WHERE $where
              ORDER BY sortname, f.id";
        $records = $DB->get_records_sql($sql, $params, $page * self::PERPAGE, self::PERPAGE);

        $people = self::people(array_keys($records));
        $list = [];
        foreach ($records as $id => $family) {
            // A closed family has nobody active: it shows who was in it.
            $here = fn($person) => $family->status !== api::ACTIVE || $person->status === api::ACTIVE;
            $adults = array_filter($people['adults'][$id] ?? [], $here);
            $children = array_filter($people['children'][$id] ?? [], $here);
            $family->adults = array_values(array_map(fn($adult) => self::adult_name($adult), $adults));
            $family->children = array_values(array_map(fn($child) => self::child_name($child, $people['users']), $children));
            $list[] = $family;
        }

        return ['total' => $total, 'families' => $list];
    }

    /**
     * The condition of the list.
     *
     * @param string $query What was typed.
     * @param string $status active or closed.
     * @return array The SQL and its parameters.
     */
    private static function where(string $query, string $status): array {
        global $DB;

        $where = 'f.status = :status';
        $params = ['status' => $status];
        $query = trim($query);
        if ($query === '') {
            return [$where, $params];
        }
        $like = '%' . $DB->sql_like_escape($query) . '%';
        $adultname = $DB->sql_fullname('a.firstname', 'a.lastname');
        $childname = $DB->sql_fullname('u.firstname', 'u.lastname');
        $match = [
            $DB->sql_like($adultname, ':adultname', false, false),
            $DB->sql_like('a.email', ':email', false, false),
            $DB->sql_like('a.sourcekey', ':adultkey', false, false),
        ];
        $params += ['adultname' => $like, 'email' => $like, 'adultkey' => $like];
        // A phone is found however it is typed, by its digits.
        $digits = preg_replace('/\D/', '', $query);
        if (strlen($digits) >= 5 && !preg_match('/[a-z]/i', $query)) {
            $match[] = $DB->sql_like('a.phone', ':phone');
            $params['phone'] = '%' . $digits . '%';
        }
        $adults = implode(' OR ', $match);
        $children = $DB->sql_like($childname, ':childname', false, false) . ' OR '
            . $DB->sql_like('c.sourcekey', ':childkey', false, false);
        $params += ['childname' => $like, 'childkey' => $like, 'familykey' => $like];
        $where .= " AND (
            EXISTS (SELECT 1 FROM {local_oksigeniafamily_adult} a WHERE a.familyid = f.id AND ($adults))
            OR EXISTS (
                SELECT 1
                  FROM {local_oksigeniafamily_child} c
             LEFT JOIN {user} u ON u.id = c.userid
                 WHERE c.familyid = f.id AND ($children)
            )
            OR " . $DB->sql_like('f.sourcekey', ':familykey', false, false) . '
        )';

        return [$where, $params];
    }

    /**
     * Who is in some families: their adults and children, grouped by family, and the students' accounts.
     *
     * @param int[] $ids The families.
     * @return array adults and children (by family, then by id) and users (by id).
     */
    public static function people(array $ids): array {
        global $DB;

        $people = ['adults' => [], 'children' => [], 'users' => []];
        if (!$ids) {
            return $people;
        }
        [$in, $params] = $DB->get_in_or_equal($ids);
        $userids = [];
        $adults = $DB->get_records_select('local_oksigeniafamily_adult', "familyid $in", $params, 'lastname, firstname, id');
        foreach ($adults as $adult) {
            $people['adults'][$adult->familyid][$adult->id] = $adult;
            $userids[] = $adult->userid;
        }
        foreach ($DB->get_records_select('local_oksigeniafamily_child', "familyid $in", $params, 'id') as $child) {
            $people['children'][$child->familyid][$child->id] = $child;
            $userids[] = $child->userid;
        }
        $userids = array_filter(array_map('intval', $userids));
        if ($userids) {
            $fields = 'id, username, deleted, suspended, ' . implode(', ', \core_user\fields::get_name_fields());
            $people['users'] = $DB->get_records_list('user', 'id', $userids, '', $fields);
        }

        return $people;
    }

    /**
     * What to call an adult: their name, or how to reach them when they gave none.
     *
     * @param \stdClass $adult The adult.
     * @return string
     */
    public static function adult_name(\stdClass $adult): string {
        $name = trim(($adult->firstname ?? '') . ' ' . ($adult->lastname ?? ''));
        if ($name !== '') {
            return $name;
        }

        return $adult->phone ?: ($adult->email ?: get_string('notice:someone', 'local_oksigeniafamily'));
    }

    /**
     * What to call a child: their name, or their key when they have no account.
     *
     * @param \stdClass $child The child.
     * @param \stdClass[] $users The accounts, by id.
     * @return string
     */
    public static function child_name(\stdClass $child, array $users): string {
        if (!empty($child->userid) && isset($users[$child->userid])) {
            return fullname($users[$child->userid]);
        }

        return (string) ($child->sourcekey ?: get_string('family:gone', 'local_oksigeniafamily'));
    }
}
