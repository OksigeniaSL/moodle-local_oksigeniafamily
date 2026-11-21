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

    /** What a family may be missing, in the order of the tiles: key => [group, icon]; first what to call about. */
    public const NEEDS = [
        'pending' => ['missing', 'letter'],
        'noaccount' => ['missing', 'person'],
        'code' => ['missing', 'code'],
        'phone' => ['missing', 'phone'],
        'student' => ['know', 'student'],
        'image' => ['know', 'image'],
        'review' => ['know', 'review'],
    ];

    /** Where the adults to look at are kept: what the last round of the school's data could not settle by itself. */
    private const REVIEW = 'cifrareview';

    /** Why an adult is to be looked at, in the order the pages say it. */
    public const REVIEWS = ['gone'];

    /**
     * The adults to look at, by why: gone (the school's data no longer has them in their household, and they had joined,
     * so a person decides). The round of the school's data writes each list; an adult who left is no longer in the pages.
     *
     * @return int[][] By reason, the adult ids.
     */
    public static function review(): array {
        $kept = json_decode((string) get_config('local_oksigeniafamily', self::REVIEW), true);
        $review = [];
        foreach (self::REVIEWS as $why) {
            $review[$why] = is_array($kept) && is_array($kept[$why] ?? null) ? array_map('intval', $kept[$why]) : [];
        }

        return $review;
    }

    /**
     * Write one list of adults to look at, as the last round found them, leaving the other as it is.
     *
     * @param string $why A reason of REVIEWS.
     * @param int[] $adultids The adults.
     * @return void
     */
    public static function set_review(string $why, array $adultids): void {
        $review = self::review();
        $review[$why] = array_values(array_unique(array_map('intval', $adultids)));
        set_config(self::REVIEW, json_encode($review), 'local_oksigeniafamily');
    }

    /**
     * Why an adult is to be looked at.
     *
     * @param int $adultid The adult.
     * @return string A reason of REVIEWS, or '' for none.
     */
    public static function review_reason(int $adultid): string {
        foreach (self::review() as $why => $ids) {
            if (in_array($adultid, $ids, true)) {
                return $why;
            }
        }

        return '';
    }

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
     * @param string $need Only the families with this need (a key of NEEDS), among the active ones.
     * @param int $now The time, for the needs.
     * @return array total, families (each with id, status, source, adults and children (names), and kids (names with their
     *     group)), and people (as people() returns it for them).
     */
    public static function search(string $query, string $status, int $page, string $need = '', int $now = 0): array {
        global $DB;

        [$where, $params] = self::where($query, $status, $need, $now ?: time());
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
            $family->kids = array_values(array_map(function ($child) use ($people) {
                $name = self::child_name($child, $people['users']);
                $group = trim((string) ($child->groupname ?? ''));
                return $group === '' ? $name : $name . ' · ' . $group;
            }, $children));
            $list[] = $family;
        }

        return ['total' => $total, 'families' => $list, 'people' => $people];
    }

    /**
     * The condition of the list.
     *
     * @param string $query What was typed.
     * @param string $status active or closed.
     * @param string $need Only the families with this need, among the active ones: it goes with the search.
     * @param int $now The time, for the needs.
     * @return array The SQL and its parameters.
     */
    private static function where(string $query, string $status, string $need = '', int $now = 0): array {
        global $DB;

        $where = 'f.status = :status';
        $params = ['status' => $status];
        if ($need !== '' && $status === api::ACTIVE && in_array($need, self::needs(), true)) {
            [$needsql, $needparams] = self::need_condition($need, $now ?: time());
            $where .= " AND $needsql";
            $params += $needparams;
        }
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

    /**
     * The needs that apply on this site: the consents only when they are on.
     *
     * @return string[]
     */
    public static function needs(): array {
        $review = (bool) array_merge(...array_values(self::review()));

        return array_values(array_filter(
            array_keys(self::NEEDS),
            fn($need) => ($need !== 'pending' || consent::enabled()) && ($need !== 'review' || $review)
        ));
    }

    /**
     * The condition, on the family f, of having a need. Each one is an EXISTS or a NOT EXISTS on an indexed column,
     * with parameter names of its own, so that it can go with the search.
     *
     * - pending: a student with a request still open (open and before its deadline) and no answer at all to it yet.
     *   With «every adult» (needall), a request half answered is not counted: the report of the request says it.
     * - noaccount: no active adult with an account that is not deleted.
     * - code: a student with a letter's code in use (active, not expired) that nobody has used, and no code of theirs
     *   used (a letter's, their own page's or an invitation). The code a student's own page shows is not a letter given.
     * - phone: no active adult with a phone confirmed (phoneverified). The card says «Sin teléfono» when there is none.
     * - student: a student only with the school's key, without an account.
     * - image: a student whose image may not be shown (see image_keys()).
     * - review: an active adult the last round of the school's data left for a person to look at (see review()).
     *
     * @param string $need A key of NEEDS.
     * @param int $now The time.
     * @return array The SQL and its parameters.
     */
    public static function need_condition(string $need, int $now): array {
        global $DB;

        $active = api::ACTIVE;
        switch ($need) {
            case 'pending':
                return ["EXISTS (
                    SELECT 1
                      FROM {local_oksigeniafamily_child} pc
                      JOIN {local_oksigeniafamily_target} pt ON pt.userid = pc.userid
                      JOIN {local_oksigeniafamily_request} pr ON pr.id = pt.requestid
                     WHERE pc.familyid = f.id AND pc.status = :pcactive
                       AND pr.status = :propen AND pr.deadline >= :prnow
                       AND NOT EXISTS (
                           SELECT 1 FROM {local_oksigeniafamily_answer} pn
                            WHERE pn.requestid = pr.id AND pn.childuserid = pc.userid
                       )
                )", ['pcactive' => $active, 'propen' => consent::OPEN, 'prnow' => $now]];
            case 'noaccount':
                return ["NOT EXISTS (
                    SELECT 1
                      FROM {local_oksigeniafamily_adult} na
                      JOIN {user} nu ON nu.id = na.userid AND nu.deleted = 0
                     WHERE na.familyid = f.id AND na.status = :naactive
                )", ['naactive' => $active]];
            case 'code':
                return ["EXISTS (
                    SELECT 1
                      FROM {local_oksigeniafamily_child} cc
                      JOIN {local_oksigeniafamily_claim} cl ON cl.userid = cc.userid
                     WHERE cc.familyid = f.id AND cc.status = :ccactive
                       AND cl.status = :clactive AND cl.claims = 0 AND cl.expires >= :clnow AND cl.purpose = :clletter
                       AND NOT EXISTS (
                           SELECT 1 FROM {local_oksigeniafamily_claim} cu WHERE cu.userid = cc.userid AND cu.claims > 0
                       )
                )", ['ccactive' => $active, 'clactive' => $active, 'clnow' => $now, 'clletter' => 'letter']];
            case 'phone':
                return ["NOT EXISTS (
                    SELECT 1
                      FROM {local_oksigeniafamily_adult} pa
                     WHERE pa.familyid = f.id AND pa.status = :paactive AND pa.phoneverified = 1
                )", ['paactive' => $active]];
            case 'student':
                return ["EXISTS (
                    SELECT 1
                      FROM {local_oksigeniafamily_child} sc
                     WHERE sc.familyid = f.id AND sc.status = :scactive AND (sc.userid IS NULL OR sc.userid = 0)
                )", ['scactive' => $active]];
            case 'image':
                [$userids, $sourcekeys] = self::image_keys();
                if (!$userids && !$sourcekeys) {
                    return ['1 = 0', []];
                }
                $match = [];
                $params = ['icactive' => $active];
                if ($userids) {
                    [$in, $p] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'icu');
                    $match[] = "ic.userid $in";
                    $params += $p;
                }
                if ($sourcekeys) {
                    [$in, $p] = $DB->get_in_or_equal($sourcekeys, SQL_PARAMS_NAMED, 'ick');
                    $match[] = "ic.sourcekey $in";
                    $params += $p;
                }
                return ["EXISTS (
                    SELECT 1
                      FROM {local_oksigeniafamily_child} ic
                     WHERE ic.familyid = f.id AND ic.status = :icactive AND (" . implode(' OR ', $match) . ')
                )', $params];
            case 'review':
                $ids = array_merge(...array_values(self::review()));
                if (!$ids) {
                    return ['1 = 0', []];
                }
                [$in, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'rva');
                return ["EXISTS (
                    SELECT 1
                      FROM {local_oksigeniafamily_adult} ra
                     WHERE ra.familyid = f.id AND ra.status = :raactive AND ra.id $in
                )", $params + ['raactive' => $active]];
        }
        throw new \coding_exception('Unknown need: ' . $need);
    }

    /**
     * The students whose image may not be shown, as standing::state() decides it, in one query: the last answer of each
     * adult counts, and the school's only when no adult answered after it; a «no» that counts is enough. Few students
     * have one, so the list is short. (It looks at each key on its own: a «no» under the account and a later «yes» of
     * the same adult under the school's key would still count as no. It does not happen in practice.)
     *
     * @return array [user ids, school keys]
     */
    public static function image_keys(): array {
        global $DB;

        $sql = "SELECT DISTINCT s.childkey
                  FROM {local_oksigeniafamily_standing} s
                 WHERE s.topic = :topic AND s.answer = :no
                   AND NOT EXISTS (
                       SELECT 1 FROM {local_oksigeniafamily_standing} s2
                        WHERE s2.childkey = s.childkey AND s2.topic = s.topic AND s2.adultid = s.adultid AND s2.id > s.id
                   )
                   AND (s.adultid <> 0 OR NOT EXISTS (
                       SELECT 1 FROM {local_oksigeniafamily_standing} s3
                        WHERE s3.childkey = s.childkey AND s3.topic = s.topic AND s3.adultid <> 0 AND s3.id > s.id
                   ))";
        $userids = [];
        $sourcekeys = [];
        foreach ($DB->get_fieldset_sql($sql, ['topic' => standing::IMAGE, 'no' => standing::NO]) as $key) {
            if (preg_match('/^user:(\d+)$/', $key, $m)) {
                $userids[] = (int) $m[1];
            } else {
                $sourcekeys[] = $key;
            }
        }

        return [$userids, $sourcekeys];
    }

    /**
     * How many active families have each need: one COUNT for each, on the indexes. With 30.000 families it is a few
     * tens of milliseconds in all; if a site ever finds it slow, the counts can go in a cache of a few minutes.
     *
     * @param int $now The time.
     * @return int[] By need.
     */
    public static function need_counts(int $now): array {
        global $DB;

        $counts = [];
        foreach (self::needs() as $need) {
            [$sql, $params] = self::need_condition($need, $now);
            $counts[$need] = $DB->count_records_sql(
                "SELECT COUNT(1) FROM {local_oksigeniafamily_family} f WHERE f.status = :fstatus AND $sql",
                $params + ['fstatus' => api::ACTIVE]
            );
        }

        return $counts;
    }

    /**
     * What the families of a page are missing, each said in words with the names it concerns. The same conditions as
     * the tiles, on the 25 families shown, plus what the names need.
     *
     * @param \stdClass[] $families The families of the page, as search() returns them, with their people.
     * @param array $people As people() returns it for them.
     * @param string $current The need being shown, to mark it.
     * @param int $now The time.
     * @return array By family id: each with flagtext, flagkind, flagicon and flagcurrent.
     */
    public static function flags(array $families, array $people, string $current, int $now): array {
        global $DB;

        $component = 'local_oksigeniafamily';
        $ids = array_map(fn($family) => (int) $family->id, $families);
        $flags = array_fill_keys($ids, []);
        if (!$ids) {
            return $flags;
        }
        [$in, $inparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'fid');
        $has = [];
        foreach (self::needs() as $need) {
            [$sql, $params] = self::need_condition($need, $now);
            $found = $DB->get_fieldset_sql(
                "SELECT f.id FROM {local_oksigeniafamily_family} f WHERE f.id $in AND $sql",
                $params + $inparams
            );
            $has[$need] = array_flip($found);
        }

        // How many open requests are still unanswered in each family, for «2 autorizaciones sin contestar».
        $pending = [];
        if (isset($has['pending']) && $has['pending']) {
            $pending = $DB->get_records_sql_menu(
                "SELECT pc.familyid, COUNT(1)
                   FROM {local_oksigeniafamily_child} pc
                   JOIN {local_oksigeniafamily_target} pt ON pt.userid = pc.userid
                   JOIN {local_oksigeniafamily_request} pr ON pr.id = pt.requestid
                  WHERE pc.familyid $in AND pc.status = :pcactive AND pr.status = :propen AND pr.deadline >= :prnow
                    AND NOT EXISTS (
                        SELECT 1
                          FROM {local_oksigeniafamily_answer} pn
                         WHERE pn.requestid = pr.id AND pn.childuserid = pc.userid
                    )
               GROUP BY pc.familyid",
                $inparams + ['pcactive' => api::ACTIVE, 'propen' => consent::OPEN, 'prnow' => $now]
            );
        }

        // The students the names are about: whose code is unused, without an account, without their image.
        $children = [];
        foreach ($ids as $id) {
            foreach ($people['children'][$id] ?? [] as $child) {
                if ($child->status === api::ACTIVE) {
                    $children[$id][] = $child;
                }
            }
        }
        $unused = [];
        $userids = [];
        foreach ($children as $list) {
            foreach ($list as $child) {
                if (!empty($child->userid)) {
                    $userids[] = (int) $child->userid;
                }
            }
        }
        if ($userids && isset($has['code']) && $has['code']) {
            [$uin, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'cu');
            $found = $DB->get_fieldset_sql(
                "SELECT DISTINCT cl.userid
                   FROM {local_oksigeniafamily_claim} cl
                  WHERE cl.userid $uin AND cl.status = :active AND cl.claims = 0 AND cl.expires >= :now
                    AND cl.purpose = :letter
                    AND NOT EXISTS (
                        SELECT 1 FROM {local_oksigeniafamily_claim} cu2 WHERE cu2.userid = cl.userid AND cu2.claims > 0
                    )",
                $uparams + ['active' => api::ACTIVE, 'now' => $now, 'letter' => 'letter']
            );
            $unused = array_flip($found);
        }
        [$nouserids, $nokeys] = isset($has['image']) && $has['image'] ? self::image_keys() : [[], []];
        $nouserids = array_flip($nouserids);
        $nokeys = array_flip($nokeys);

        $name = fn($child) => self::child_name($child, $people['users']);
        $names = fn(array $list) => implode(', ', array_map($name, $list));
        $reachable = fn($adult) => $adult->status === api::ACTIVE && trim((string) $adult->phone) !== '';
        $noimage = function ($child) use ($nouserids, $nokeys): bool {
            if (isset($nouserids[(int) $child->userid])) {
                return true;
            }
            return (string) $child->sourcekey !== '' && isset($nokeys[(string) $child->sourcekey]);
        };
        foreach ($ids as $id) {
            $add = function (string $need, string $kind, string $text) use (&$flags, $id, $current) {
                $flags[$id][] = [
                    'flagtext' => $text,
                    'flagkind' => $kind,
                    'flagicon' => self::NEEDS[$need][1],
                    'flagcurrent' => $need === $current,
                ];
            };
            $list = $children[$id] ?? [];
            if (isset($has['pending'][$id])) {
                $n = (int) ($pending[$id] ?? 1);
                $text = $n === 1 ? get_string('flag:pendingone', $component) : get_string('flag:pending', $component, $n);
                $add('pending', 'warn', $text);
            }
            if (isset($has['noaccount'][$id])) {
                $add('noaccount', 'warn', get_string('flag:noaccount', $component));
            }
            if (isset($has['code'][$id])) {
                $who = array_filter($list, fn($child) => isset($unused[(int) $child->userid]));
                $add('code', 'warn', get_string('flag:code', $component, $names($who)));
            }
            if (isset($has['phone'][$id])) {
                $phones = array_filter($people['adults'][$id] ?? [], $reachable);
                $add('phone', 'warn', get_string($phones ? 'flag:phone' : 'flag:nophone', $component));
            }
            if (isset($has['student'][$id])) {
                $who = array_filter($list, fn($child) => empty($child->userid));
                $add('student', 'info', get_string('flag:student', $component, $names($who)));
            }
            if (isset($has['image'][$id])) {
                $who = array_filter($list, $noimage);
                $add('image', 'info', get_string('flag:image', $component, $names($who)));
            }
            if (isset($has['review'][$id])) {
                foreach (self::review() as $why => $adultids) {
                    $who = array_filter(
                        $people['adults'][$id] ?? [],
                        fn($adult) => $adult->status === api::ACTIVE && in_array((int) $adult->id, $adultids, true)
                    );
                    if ($who) {
                        $add('review', 'warn', get_string('flag:review', $component, (object) [
                            'why' => get_string('review:' . $why, $component),
                            'names' => implode(', ', array_map(fn($adult) => self::adult_name($adult), $who)),
                        ]));
                    }
                }
            }
        }

        return $flags;
    }
}
