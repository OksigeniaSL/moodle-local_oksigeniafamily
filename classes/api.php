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

namespace local_oksigeniafamily;

use local_oksigeniafamily\local\phone;
use local_oksigeniafamily\local\readers\stage_guesser;
use local_oksigeniafamily\local\roles;
use stdClass;

/**
 * The family records, for other plugins: who is family of whom, the codes that join a family to a student, and the
 * changes that keep the records up to date.
 *
 * Other plugins work without this one: they check `class_exists('\local_oksigeniafamily\api')` and then
 * `api::is_available()`. A child is a student, with a Moodle account (`userid`) or only a key in the school's data
 * (`sourcekey`, such as `cifra:123` or `oneroster:abc`). Being in a family gives an adult nothing by itself: each plugin
 * decides what it shows.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api {
    /** A family, an adult or a child in use. */
    public const ACTIVE = 'active';

    /** An adult or a child who left the family. */
    public const LEFT = 'left';

    /** A family with no children left. */
    public const CLOSED = 'closed';

    /** A claim code that no longer joins anyone. */
    public const REVOKED = 'revoked';

    /** The letters of a claim code: no 0, 1, I, L or O, which are read wrong. */
    private const CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    /** The length of a claim code. */
    private const CODE_LENGTH = 8;

    /**
     * Whether the family records can be used. Other plugins call it after checking that the class exists.
     *
     * @return bool
     */
    public static function is_available(): bool {
        return true;
    }

    /**
     * The children (their Moodle accounts) of an adult who has an account: a parent with an account, or the Aty
     * account of an adult. Only active children of active families.
     *
     * @param int $userid The adult's Moodle account.
     * @return int[] The children's user ids.
     */
    public static function children_of_user(int $userid): array {
        global $DB;

        $sql = "SELECT DISTINCT c.userid
                  FROM {local_oksigeniafamily_adult} a
                  JOIN {local_oksigeniafamily_family} f ON f.id = a.familyid AND f.status = :factive
                  JOIN {local_oksigeniafamily_child} c ON c.familyid = f.id AND c.status = :cactive
                 WHERE a.userid = :userid AND a.status = :aactive AND c.userid IS NOT NULL";
        $params = ['userid' => $userid, 'factive' => self::ACTIVE, 'cactive' => self::ACTIVE, 'aactive' => self::ACTIVE];

        return array_map('intval', $DB->get_fieldset_sql($sql, $params));
    }

    /**
     * The children of an adult, with or without an account (for adults without one, such as Meals' keys).
     *
     * @param int $adultid The adult.
     * @return stdClass[] Child records (id, familyid, userid, sourcekey, and firstname and lastname as the school's
     *     data gave them to a child without an account, or null), keyed by id.
     */
    public static function children_of_adult(int $adultid): array {
        global $DB;

        $sql = "SELECT c.id, c.familyid, c.userid, c.sourcekey, c.firstname, c.lastname
                  FROM {local_oksigeniafamily_adult} a
                  JOIN {local_oksigeniafamily_family} f ON f.id = a.familyid AND f.status = :factive
                  JOIN {local_oksigeniafamily_child} c ON c.familyid = f.id AND c.status = :cactive
                 WHERE a.id = :adultid AND a.status = :aactive";

        return $DB->get_records_sql($sql, [
            'adultid' => $adultid, 'factive' => self::ACTIVE, 'cactive' => self::ACTIVE, 'aactive' => self::ACTIVE,
        ]);
    }

    /**
     * Whether an adult (with or without an account) is family of a student.
     *
     * @param int $adultid The adult.
     * @param int $childuserid The student's account.
     * @return bool
     */
    public static function adult_has_child(int $adultid, int $childuserid): bool {
        foreach (self::children_of_adult($adultid) as $child) {
            if ((int) $child->userid === $childuserid) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an adult with an account is family of a student: the check other plugins' web services make before
     * showing a child's data.
     *
     * @param int $adultuserid The adult's account.
     * @param int $childuserid The student's account.
     * @return bool
     */
    public static function is_guardian(int $adultuserid, int $childuserid): bool {
        return in_array($childuserid, self::children_of_user($adultuserid), true);
    }

    /**
     * The adults of a student, to tell them something (a late book, a new adult). Not those the school's data brought who
     * have not joined nor been welcomed yet (quiet): nobody writes to a family before the school does.
     *
     * With $leftsince, also the adults of a student who left the family since then, while the family is still open: a
     * goodbye that goes out after the school's data took the student away (Ayvurenda's booklet of a whole school life).
     *
     * @param int $childuserid The student's account.
     * @param int $leftsince 0 for a student still in the family; a time, to count one who left at or after it too.
     * @return stdClass[] Adult records (id, familyid, firstname, lastname, email, phone, userid), keyed by id.
     */
    public static function adults_of_child(int $childuserid, int $leftsince = 0): array {
        global $DB;

        $params = [
            'userid' => $childuserid, 'factive' => self::ACTIVE, 'aactive' => self::ACTIVE, 'cactive' => self::ACTIVE,
        ];
        $child = 'c.status = :cactive';
        if ($leftsince > 0) {
            $child = '(c.status = :cactive OR (c.status = :cleft AND c.timeleft >= :leftsince))';
            $params += ['cleft' => self::LEFT, 'leftsince' => $leftsince];
        }
        $sql = "SELECT a.id, a.familyid, a.firstname, a.lastname, a.email, a.phone, a.userid
                  FROM {local_oksigeniafamily_child} c
                  JOIN {local_oksigeniafamily_family} f ON f.id = c.familyid AND f.status = :factive
                  JOIN {local_oksigeniafamily_adult} a ON a.familyid = f.id AND a.status = :aactive AND a.quiet = 0
                 WHERE c.userid = :userid AND $child";

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * The open families where an account is an adult in the family, so another plugin can go from a person to their
     * households (Eguahe's cards on the app's home), children without an account included, without the whole roll.
     *
     * @param int $userid The account.
     * @return stdClass[] By family id, each with familyid, source and sourcekey (the key of the household where it came
     *     from, such as eguahe:12), in the order they were made.
     */
    public static function families_of_user(int $userid): array {
        global $DB;

        $rows = $DB->get_records_sql(
            "SELECT DISTINCT f.id, f.source, f.sourcekey
               FROM {local_oksigeniafamily_adult} a
               JOIN {local_oksigeniafamily_family} f ON f.id = a.familyid AND f.status = :factive
              WHERE a.userid = :userid AND a.status = :aactive
           ORDER BY f.id",
            ['userid' => $userid, 'factive' => self::ACTIVE, 'aactive' => self::ACTIVE]
        );
        $families = [];
        foreach ($rows as $row) {
            $families[(int) $row->id] = (object) [
                'familyid' => (int) $row->id,
                'source' => $row->source,
                'sourcekey' => $row->sourcekey,
            ];
        }

        return $families;
    }

    /**
     * The accounts of the active adults of an open family, to tell them something through Moodle's messages (the app,
     * the site): Eguahe's notices about a household's visit or next school year. Adults without an account are not here.
     *
     * @param int $familyid The family.
     * @return int[] User ids, in the order the adults were added; none when the family is closed or has no accounts.
     */
    public static function adult_users_of_family(int $familyid): array {
        global $DB;

        $userids = $DB->get_fieldset_sql(
            "SELECT a.userid
               FROM {local_oksigeniafamily_adult} a
               JOIN {local_oksigeniafamily_family} f ON f.id = a.familyid AND f.status = :factive
               JOIN {user} u ON u.id = a.userid AND u.deleted = 0 AND u.suspended = 0
              WHERE a.familyid = :familyid AND a.status = :aactive AND a.quiet = 0
           ORDER BY a.id",
            ['familyid' => $familyid, 'factive' => self::ACTIVE, 'aactive' => self::ACTIVE]
        );

        return array_values(array_unique(array_map('intval', $userids)));
    }

    /**
     * The accounts an adult has in the school management programs that bring them, to help them sign in there: the
     * username (by the centre's rule, so said as derived), the e-mail where the program sends a new password, whether it
     * is active (null when the program does not say, as Cifra) and when the program last brought them. Whoever shows
     * them hides most of each.
     *
     * @param int $adultid The adult.
     * @return array[] Each with system, label, username, derived, email, active and checked; empty when the adult has
     *     left or no program brings them.
     */
    public static function external_accounts(int $adultid): array {
        return local\external::accounts($adultid);
    }

    /**
     * The adults of many students at once, as adults_of_child() gives them for one (without the quiet ones): for a request
     * to a whole school, where one query per student would be tens of thousands.
     *
     * @param int[] $childuserids The students' accounts.
     * @return array Student's user id => [adult id => adult record], with an empty list for a student with no adult.
     */
    public static function adults_of_children(array $childuserids): array {
        global $DB;

        $result = array_fill_keys(array_map('intval', $childuserids), []);
        foreach (array_chunk(array_keys($result), 1000) as $chunk) {
            [$in, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'child');
            $rows = $DB->get_recordset_sql(
                "SELECT c.userid AS childuserid, a.id, a.familyid, a.firstname, a.lastname, a.email, a.phone, a.userid
                   FROM {local_oksigeniafamily_child} c
                   JOIN {local_oksigeniafamily_family} f ON f.id = c.familyid AND f.status = :factive
                   JOIN {local_oksigeniafamily_adult} a ON a.familyid = f.id AND a.status = :aactive AND a.quiet = 0
                  WHERE c.userid $in AND c.status = :cactive
               ORDER BY a.id",
                $params + ['factive' => self::ACTIVE, 'aactive' => self::ACTIVE, 'cactive' => self::ACTIVE]
            );
            foreach ($rows as $row) {
                $childuserid = (int) $row->childuserid;
                unset($row->childuserid);
                $result[$childuserid][(int) $row->id] = $row;
            }
            $rows->close();
        }

        return $result;
    }

    /**
     * The whole roll of households, to reach every family of the centre at once (such as Eguahe asking who stays next
     * school year): each household with its children and its adults. All in memory: for a large site, each_household().
     *
     * Names, e-mail and phone are the adult's own record; an adult with an account and none of their own takes them from
     * the account. A child with an account carries the account's name; a child known only by a key in the school's data
     * (cifra:123) carries the name the school's data gave (set_child_details()), or none. The group is the school's own
     * name for it (INF 3ºA), when its data gave one; the stage, a guess from that name (INF), to group or to say, never to
     * decide.
     *
     * @param bool $activeonly Only active households, children and adults; false brings the closed and the gone too.
     * @return stdClass[] Households keyed by family id, each with familyid, status, source, sourcekey, timeclosed, children
     *     (childid, userid, sourcekey, firstname, lastname, group, stage, status, timeleft) and adults (adultid, userid,
     *     firstname, lastname, email, phone, phoneverified, status), in the order they were added.
     */
    public static function households(bool $activeonly = true): array {
        $households = [];
        foreach (self::each_household($activeonly) as $household) {
            $households[$household->familyid] = $household;
        }

        return $households;
    }

    /**
     * The roll of households one at a time, a few hundred loaded at once: for a site the size of a university, where the
     * whole roll in memory (households()) would not fit a web request. Each household as households() gives it; without
     * adults when only the children are wanted (to count them, or to ask about each child).
     *
     * @param bool $activeonly Only active households, children and adults; false brings the closed and the gone too.
     * @param bool $withadults Whether to bring the adults too.
     * @param int $batch How many households are loaded at once.
     * @return \Generator<int, stdClass> Households by family id, in the order they were made.
     */
    public static function each_household(bool $activeonly = true, bool $withadults = true, int $batch = 500): \Generator {
        global $DB;

        $last = 0;
        do {
            // Moodle refuses named parameters a query does not use: each query gets only its own.
            $rows = $DB->get_records_select(
                'local_oksigeniafamily_family',
                'id > :last' . ($activeonly ? ' AND status = :factive' : ''),
                ['last' => $last] + ($activeonly ? ['factive' => self::ACTIVE] : []),
                'id',
                'id, status, source, sourcekey, timeclosed',
                0,
                $batch
            );
            if (!$rows) {
                return;
            }
            $last = (int) array_key_last($rows);
            foreach (self::fill_households($rows, $activeonly, $withadults) as $familyid => $household) {
                yield $familyid => $household;
            }
        } while (count($rows) === $batch);
    }

    /**
     * One household, as households() gives each: for whoever starts from one family (a chat that found the adult who
     * writes, by phone or e-mail, and shows their children with their group) and should not walk the whole roll for it.
     *
     * @param int $familyid The family.
     * @param bool $withadults Whether to bring the adults too.
     * @param bool $activeonly Only an active household, with its active children and adults; false brings a closed one and
     *     the gone too.
     * @return stdClass|null The household, or null when there is no such family (or it is closed, with $activeonly).
     */
    public static function household(int $familyid, bool $withadults = false, bool $activeonly = true): ?stdClass {
        global $DB;

        $row = $DB->get_record('local_oksigeniafamily_family', ['id' => $familyid], 'id, status, source, sourcekey, timeclosed');
        if (!$row || ($activeonly && $row->status !== self::ACTIVE)) {
            return null;
        }

        return self::fill_households([(int) $row->id => $row], $activeonly, $withadults)[(int) $row->id];
    }

    /**
     * Households from their rows, with their children and, if asked, their adults: what each_household() and household()
     * give, in two queries for the lot.
     *
     * @param stdClass[] $rows Rows of the family table (id, status, source, sourcekey, timeclosed), by id.
     * @param bool $activeonly Only the active children and adults.
     * @param bool $withadults Whether to bring the adults too.
     * @return stdClass[] The households by family id, in the order of the rows.
     */
    private static function fill_households(array $rows, bool $activeonly, bool $withadults): array {
        global $DB;

        $households = [];
        foreach ($rows as $row) {
            $households[(int) $row->id] = (object) [
                'familyid' => (int) $row->id,
                'status' => $row->status,
                'source' => $row->source,
                'sourcekey' => $row->sourcekey,
                'timeclosed' => $row->timeclosed === null ? null : (int) $row->timeclosed,
                'children' => [],
                'adults' => [],
            ];
        }
        [$in, $inparams] = $DB->get_in_or_equal(array_keys($households), SQL_PARAMS_NAMED, 'fam');

        $children = $DB->get_records_sql(
            "SELECT c.id, c.familyid, c.userid, c.sourcekey, c.status, c.timeleft, c.groupname,
                    c.firstname AS cfirstname, c.lastname AS clastname, u.firstname, u.lastname
               FROM {local_oksigeniafamily_child} c
          LEFT JOIN {user} u ON u.id = c.userid AND u.deleted = 0
              WHERE c.familyid $in" . ($activeonly ? ' AND c.status = :cactive' : '') . "
           ORDER BY c.id",
            $inparams + ($activeonly ? ['cactive' => self::ACTIVE] : [])
        );
        foreach ($children as $child) {
            $households[(int) $child->familyid]->children[] = (object) [
                'childid' => (int) $child->id,
                'userid' => $child->userid === null ? null : (int) $child->userid,
                'sourcekey' => $child->sourcekey,
                'firstname' => $child->firstname ?? $child->cfirstname,
                'lastname' => $child->lastname ?? $child->clastname,
                'group' => $child->groupname,
                // The school's own name for the stage, when the group's name has one (PRI 5º B: PRI), or ''.
                'stage' => $child->groupname === null ? '' : stage_guesser::guess($child->groupname),
                'status' => $child->status,
                'timeleft' => $child->timeleft === null ? null : (int) $child->timeleft,
            ];
        }

        if ($withadults) {
            $adults = $DB->get_records_sql(
                "SELECT a.id, a.familyid, a.userid, a.firstname, a.lastname, a.email, a.phone, a.phoneverified, a.status,
                        a.quiet, u.firstname AS ufirstname, u.lastname AS ulastname, u.email AS uemail
                   FROM {local_oksigeniafamily_adult} a
              LEFT JOIN {user} u ON u.id = a.userid AND u.deleted = 0
                  WHERE a.familyid $in" . ($activeonly ? ' AND a.status = :aactive' : '') . "
               ORDER BY a.id",
                $inparams + ($activeonly ? ['aactive' => self::ACTIVE] : [])
            );
            foreach ($adults as $adult) {
                $households[(int) $adult->familyid]->adults[] = (object) [
                    'adultid' => (int) $adult->id,
                    'userid' => $adult->userid === null ? null : (int) $adult->userid,
                    'firstname' => $adult->firstname ?? $adult->ufirstname,
                    'lastname' => $adult->lastname ?? $adult->ulastname,
                    'email' => $adult->email ?? $adult->uemail,
                    'phone' => $adult->phone,
                    'phoneverified' => (bool) $adult->phoneverified,
                    'status' => $adult->status,
                    // Brought by the school's data, not yet joined nor welcomed: not to be written to yet.
                    'quiet' => (bool) $adult->quiet,
                ];
            }
        }

        return $households;
    }

    /**
     * Give the students of a key in the school's data the name and group that data has for them: for a nightly sync from
     * the school's management system, so that students without a Moodle account (the youngest, often) can be named and
     * placed. It only completes students the families already have, every record with that key (a child of two homes has
     * two); it creates no one and no account. Running it again with the same values changes nothing.
     *
     * @param string $sourcekey The student's key in the school's data (cifra:123).
     * @param string|null $firstname The first name; null or '' clears it.
     * @param string|null $lastname The last name; null or '' clears it.
     * @param string|null $group The group this school year, as the school names it (INF 3ºA); null or '' clears it.
     * @return int How many records changed.
     */
    public static function set_child_details(string $sourcekey, ?string $firstname, ?string $lastname, ?string $group): int {
        global $DB;

        $values = [];
        foreach (['firstname' => $firstname, 'lastname' => $lastname, 'groupname' => $group] as $field => $value) {
            $value = trim((string) $value);
            $values[$field] = $value === '' ? null : \core_text::substr($value, 0, 100);
        }

        $changed = 0;
        foreach ($DB->get_records('local_oksigeniafamily_child', ['sourcekey' => $sourcekey]) as $child) {
            $update = array_filter($values, fn($value, $field) => $child->$field !== $value, ARRAY_FILTER_USE_BOTH);
            if ($update) {
                $record = ['id' => $child->id, 'timemodified' => time()] + $update;
                $DB->update_record('local_oksigeniafamily_child', (object) $record);
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * The adult with a phone number, for WhatsApp (Marandu): who is writing. Only a number known to be theirs: one just
     * typed on the claim page identifies nobody.
     *
     * @param string $phone The number in E.164 form.
     * @return stdClass|null The adult record, or null.
     */
    public static function adult_by_phone(string $phone): ?stdClass {
        global $DB;

        $sql = "SELECT a.*
                  FROM {local_oksigeniafamily_adult} a
                  JOIN {local_oksigeniafamily_family} f ON f.id = a.familyid AND f.status = :factive
                 WHERE a.phone = :phone AND a.phoneverified = 1 AND a.status = :aactive
              ORDER BY a.id ASC";
        $records = $DB->get_records_sql($sql, ['phone' => $phone, 'factive' => self::ACTIVE, 'aactive' => self::ACTIVE], 0, 1);

        return $records ? reset($records) : null;
    }

    /**
     * Every adult with a phone number known to be theirs (the same number can stand for two adults, such as two families of
     * one person).
     *
     * @param string $phone The number in E.164 form.
     * @return stdClass[] Adult records, keyed by id.
     */
    public static function adults_by_phone(string $phone): array {
        global $DB;

        $sql = "SELECT a.*
                  FROM {local_oksigeniafamily_adult} a
                  JOIN {local_oksigeniafamily_family} f ON f.id = a.familyid AND f.status = :factive
                 WHERE a.phone = :phone AND a.phoneverified = 1 AND a.status = :aactive
              ORDER BY a.id ASC";

        return $DB->get_records_sql($sql, ['phone' => $phone, 'factive' => self::ACTIVE, 'aactive' => self::ACTIVE]);
    }

    /**
     * Every adult with a phone number in their record, whether or not it is known to be theirs: a hint for a person to
     * confirm (who may be the one writing on WhatsApp, for the staff who answer), never who is writing nor a reason to show
     * or send them anything, as adults_by_email(). A number typed on the claim page may be someone else's: each record
     * says whether it is vouched for (phoneverified) and whether it is still quiet (brought by the school's data, not yet
     * joined nor welcomed), and those vouched for come first. Their household, with household($record->familyid).
     *
     * @param string $phone The number in E.164 form (+34600000001), as the records keep it; spaces, signs and a leading 00
     *     are taken, a number without its country code is not.
     * @return stdClass[] Adult records, keyed by id: the vouched for first, then in the order they were added.
     */
    public static function adults_by_phone_hint(string $phone): array {
        global $DB;

        $phone = phone::normalise($phone);
        if ($phone === '') {
            return [];
        }
        $sql = "SELECT a.*
                  FROM {local_oksigeniafamily_adult} a
                  JOIN {local_oksigeniafamily_family} f ON f.id = a.familyid AND f.status = :factive
                 WHERE a.phone = :phone AND a.status = :aactive
              ORDER BY a.phoneverified DESC, a.id ASC";

        return $DB->get_records_sql($sql, ['phone' => $phone, 'factive' => self::ACTIVE, 'aactive' => self::ACTIVE]);
    }

    /**
     * Every adult with an e-mail address, in their record or in their account, written in any case. Nobody vouches for an
     * address, so this is a hint for a person to confirm (such as "already a family of the school" for the admissions
     * team), never who is writing.
     *
     * @param string $email The address.
     * @return stdClass[] Adult records, keyed by id.
     */
    public static function adults_by_email(string $email): array {
        global $DB;

        $email = trim($email);
        if ($email === '') {
            return [];
        }
        $sql = "SELECT a.*
                  FROM {local_oksigeniafamily_adult} a
                  JOIN {local_oksigeniafamily_family} f ON f.id = a.familyid AND f.status = :factive
             LEFT JOIN {user} u ON u.id = a.userid AND u.deleted = 0
                 WHERE a.status = :aactive
                   AND (" . $DB->sql_equal('a.email', ':email', false) . "
                        OR " . $DB->sql_equal('u.email', ':accountemail', false) . ")
              ORDER BY a.id ASC";

        return $DB->get_records_sql($sql, [
            'email' => $email, 'accountemail' => $email, 'factive' => self::ACTIVE, 'aactive' => self::ACTIVE,
        ]);
    }

    /**
     * The open family of a household in its source, so the plugin that started it finds it again without keeping its id
     * (a sibling who comes later joins the same family).
     *
     * @param string $source The source given to create_family(), such as `eguahe`.
     * @param string $sourcekey The household's key in it, such as `eguahe:12`.
     * @return int|null The family's id, or null.
     */
    public static function family_by_key(string $source, string $sourcekey): ?int {
        global $DB;

        $id = $DB->get_field(
            'local_oksigeniafamily_family',
            'id',
            ['source' => $source, 'sourcekey' => $sourcekey, 'status' => self::ACTIVE],
            IGNORE_MULTIPLE
        );

        return $id === false ? null : (int) $id;
    }

    /**
     * A student's key in a source of the school's data, to match readers or diners without an account.
     *
     * @param int $childuserid The student's account.
     * @param string $source The source, such as `cifra` or `oneroster`.
     * @return string|null The whole key (such as `cifra:123`), or null.
     */
    public static function child_source_key(int $childuserid, string $source): ?string {
        global $DB;

        $select = 'userid = :userid AND ' . $DB->sql_like('sourcekey', ':prefix');
        $key = $DB->get_field_select(
            'local_oksigeniafamily_child',
            'sourcekey',
            $select,
            ['userid' => $childuserid, 'prefix' => $DB->sql_like_escape($source . ':') . '%'],
            IGNORE_MULTIPLE
        );

        return $key === false ? null : $key;
    }

    /**
     * Start a family.
     *
     * @param string $source Where it comes from (manual, claim, csv, cifra, oneroster).
     * @param string|null $sourcekey The household in its source, if any.
     * @return int The family's id.
     */
    public static function create_family(string $source = 'manual', ?string $sourcekey = null): int {
        global $DB;

        $now = time();

        return (int) $DB->insert_record('local_oksigeniafamily_family', (object) [
            'status' => self::ACTIVE, 'source' => $source, 'sourcekey' => $sourcekey,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
    }

    /**
     * Add an adult to a family.
     *
     * @param int $familyid The family.
     * @param array $data Any of firstname, lastname, email, phone (E.164), phoneverified (the school or WhatsApp vouches for
     *     the number), sourcekey, userid and quiet (brought by the school's data: no plugin writes to them until they join
     *     or wake_adult()).
     * @return int The adult's id.
     */
    public static function add_adult(int $familyid, array $data): int {
        global $DB;

        $now = time();
        $record = (object) ['familyid' => $familyid, 'status' => self::ACTIVE, 'timecreated' => $now, 'timemodified' => $now];
        foreach (['firstname', 'lastname', 'email', 'phone', 'sourcekey', 'userid'] as $field) {
            $record->$field = isset($data[$field]) && $data[$field] !== '' ? $data[$field] : null;
        }
        $record->phoneverified = !empty($data['phoneverified']) && !empty($data['phone']) ? 1 : 0;
        $record->quiet = empty($data['quiet']) ? 0 : 1;
        $record->id = $DB->insert_record('local_oksigeniafamily_adult', $record);

        event\adult_added::create([
            'objectid' => $record->id,
            'context' => \context_system::instance(),
            'relateduserid' => $record->userid,
            'other' => ['familyid' => $familyid],
        ])->trigger();
        roles::sync_family($familyid);

        return (int) $record->id;
    }

    /**
     * Add a child to a family. A student already in the family keeps the record they have, and gains what it lacked: a
     * child known only by their key gets their account when they have one, and the other way round.
     *
     * @param int $familyid The family.
     * @param int|null $userid The student's account, if any.
     * @param string|null $sourcekey The student's key in the school's data, if any.
     * @return int The child's id.
     */
    public static function add_child(int $familyid, ?int $userid, ?string $sourcekey = null): int {
        global $DB;

        if (empty($userid) && empty($sourcekey)) {
            throw new \coding_exception('A child needs a Moodle account or a key in the school\'s data.');
        }
        $existing = null;
        if (!empty($userid)) {
            $existing = $DB->get_record('local_oksigeniafamily_child', ['familyid' => $familyid, 'userid' => $userid]);
        }
        if (!$existing && !empty($sourcekey)) {
            $select = 'familyid = :familyid AND sourcekey = :sourcekey' . (empty($userid) ? '' : ' AND userid IS NULL');
            $existing = $DB->get_record_select(
                'local_oksigeniafamily_child',
                $select,
                ['familyid' => $familyid, 'sourcekey' => $sourcekey],
                '*',
                IGNORE_MULTIPLE
            );
        }
        $now = time();
        $gainsaccount = $existing && !empty($userid) && empty($existing->userid);
        if ($existing && $existing->status === self::ACTIVE && !$gainsaccount) {
            if (!empty($sourcekey) && empty($existing->sourcekey)) {
                $DB->update_record('local_oksigeniafamily_child', (object) [
                    'id' => $existing->id, 'sourcekey' => $sourcekey, 'timemodified' => $now,
                ]);
            }
            return (int) $existing->id;
        }
        if ($existing) {
            $existing->status = self::ACTIVE;
            $existing->timeleft = null;
            $existing->leftreason = null;
            $existing->timemodified = $now;
            $existing->userid = $existing->userid ?: ($userid ?: null);
            $existing->sourcekey = $existing->sourcekey ?? $sourcekey;
            $DB->update_record('local_oksigeniafamily_child', $existing);
            $childid = (int) $existing->id;
        } else {
            $childid = (int) $DB->insert_record('local_oksigeniafamily_child', (object) [
                'familyid' => $familyid, 'userid' => $userid ?: null, 'sourcekey' => $sourcekey, 'status' => self::ACTIVE,
                'timecreated' => $now, 'timemodified' => $now,
            ]);
        }

        event\child_linked::create([
            'objectid' => $childid,
            'context' => \context_system::instance(),
            'relateduserid' => $userid ?: null,
            'other' => ['familyid' => $familyid],
        ])->trigger();
        roles::sync_family($familyid);

        return $childid;
    }

    /**
     * Change an adult's details. When anything changes, the event `adult_updated` carries the hash (SHA-256) of the phone
     * they had, so a plugin that linked the old number lets it go at once.
     *
     * @param int $adultid The adult.
     * @param array $data Any of firstname, lastname, email, phone (E.164) and sourcekey; and phoneverified, which only counts
     *     with the phone: true when the caller vouches for it, false when it says it is not proved.
     * @return bool Whether anything changed.
     */
    public static function update_adult(int $adultid, array $data): bool {
        global $DB;

        $adult = $DB->get_record('local_oksigeniafamily_adult', ['id' => $adultid], '*', MUST_EXIST);
        $changes = ['id' => $adultid];
        foreach (['firstname', 'lastname', 'email', 'phone', 'sourcekey'] as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $value = $data[$field] === '' ? null : $data[$field];
            if ($value !== $adult->$field) {
                $changes[$field] = $value;
            }
        }
        // A number stops counting as theirs when it changes, unless whoever changes it vouches for it. Only a caller that
        // gives the number can vouch for it: fixing a name, or an import that only brings an e-mail, says nothing about
        // a phone the adult typed themselves. And one that gives it may say, with false, that it is not proved.
        $verified = array_key_exists('phone', $changes) ? 0 : (int) $adult->phoneverified;
        if (array_key_exists('phoneverified', $data) && !empty($data['phone'])) {
            if (!empty($data['phoneverified'])) {
                $verified = 1;
            } else if ($data['phoneverified'] === false) {
                $verified = 0;
            }
        }
        if ($verified !== (int) $adult->phoneverified) {
            $changes['phoneverified'] = $verified;
        }
        if (count($changes) === 1) {
            return false;
        }
        $changes['timemodified'] = time();
        $DB->update_record('local_oksigeniafamily_adult', (object) $changes);

        event\adult_updated::create([
            'objectid' => $adultid,
            'context' => \context_system::instance(),
            'relateduserid' => $adult->userid ?: null,
            // Not the number itself: the log keeps events for as long as the site wants, and an adult without an account
            // has no way to have it removed. A plugin that keeps the number compares its hash.
            'other' => [
                'familyid' => (int) $adult->familyid,
                'oldphonehash' => $adult->phone ? hash('sha256', $adult->phone) : '',
            ],
        ])->trigger();

        return true;
    }

    /**
     * Give an adult a Moodle account (the parent's own, or the one Aty makes for the adult who opens the app).
     *
     * @param int $adultid The adult.
     * @param int|null $userid The account, or null to take it away.
     * @return void
     */
    public static function set_adult_user(int $adultid, ?int $userid): void {
        global $DB;

        $familyid = (int) $DB->get_field('local_oksigeniafamily_adult', 'familyid', ['id' => $adultid], MUST_EXIST);
        $DB->update_record('local_oksigeniafamily_adult', (object) [
            'id' => $adultid, 'userid' => $userid ?: null, 'timemodified' => time(),
        ]);
        roles::sync_family($familyid);
    }

    /**
     * An account of the site that the school gives to somebody else (the account of someone who leaves, given to who
     * comes): the family records let go of it, so that its new owner sees nobody's children. Every adult linked to it, in
     * a family or not any more, loses it, and stays as an adult without an account in their family; and the parent role
     * this plugin gave the account goes, in every family. Each adult that changes triggers `adult_updated`, so that the
     * other plugins check what they keep for them. A script of the server calls it when an account changes hands.
     *
     * An account that is a child of an open family is left as it is: a student who stays at the school is not an account
     * given to somebody else.
     *
     * @param int $userid The account.
     * @return void
     */
    public static function account_reassigned(int $userid): void {
        global $DB;

        if ($userid <= 0) {
            return;
        }
        $student = $DB->record_exists_sql(
            "SELECT 1
               FROM {local_oksigeniafamily_child} c
               JOIN {local_oksigeniafamily_family} f ON f.id = c.familyid AND f.status = :factive
              WHERE c.userid = :userid AND c.status = :cactive",
            ['userid' => $userid, 'factive' => self::ACTIVE, 'cactive' => self::ACTIVE]
        );
        if ($student) {
            return;
        }
        $adults = $DB->get_records('local_oksigeniafamily_adult', ['userid' => $userid], 'id', 'id, familyid, phone');
        $now = time();
        foreach ($adults as $adult) {
            $DB->update_record('local_oksigeniafamily_adult', (object) [
                'id' => $adult->id, 'userid' => null, 'timemodified' => $now,
            ]);
            event\adult_updated::create([
                'objectid' => $adult->id,
                'context' => \context_system::instance(),
                'relateduserid' => $userid,
                'other' => [
                    'familyid' => (int) $adult->familyid,
                    'oldphonehash' => $adult->phone ? hash('sha256', $adult->phone) : '',
                ],
            ])->trigger();
        }
        // The families of those adults, and any other where this plugin gave the account the parent role.
        $familyids = array_map(fn($adult) => (int) $adult->familyid, $adults);
        $assigned = $DB->get_fieldset_select(
            'role_assignments',
            'DISTINCT itemid',
            'component = :component AND userid = :userid',
            ['component' => roles::COMPONENT, 'userid' => $userid]
        );
        foreach (array_unique(array_merge($familyids, array_map('intval', $assigned))) as $familyid) {
            roles::sync_family($familyid);
        }
    }

    /**
     * An adult the school's data brought stops being quiet: they joined, or the school welcomed them (its own e-mail, sent
     * by whoever sends it). From then on the other plugins reach them as any other adult.
     *
     * @param int $adultid The adult.
     * @return bool Whether they were quiet.
     */
    public static function wake_adult(int $adultid): bool {
        global $DB;

        $adult = $DB->get_record('local_oksigeniafamily_adult', ['id' => $adultid], 'id, quiet', MUST_EXIST);
        if (!$adult->quiet) {
            return false;
        }
        $DB->update_record('local_oksigeniafamily_adult', (object) [
            'id' => $adultid, 'quiet' => 0, 'timemodified' => time(),
        ]);

        return true;
    }

    /**
     * A child leaves a family: the student left the school, or the school took them out of it.
     *
     * @param int $childid The child.
     * @param string $reason Why: deleted, suspended, cohort, import (a complete list without them), manual (a person
     *     took them out, here or in another plugin, such as the admissions team when a family leaves) or merged (the
     *     family was the same adult twice, see local\claimmail::merge()).
     * @return void
     */
    public static function unlink_child(int $childid, string $reason): void {
        global $DB;

        $child = $DB->get_record('local_oksigeniafamily_child', ['id' => $childid], '*', MUST_EXIST);
        if ($child->status !== self::ACTIVE) {
            return;
        }
        $now = time();
        $DB->update_record('local_oksigeniafamily_child', (object) [
            'id' => $childid, 'status' => self::LEFT, 'timeleft' => $now, 'leftreason' => substr($reason, 0, 20),
            'timemodified' => $now,
        ]);

        event\child_unlinked::create([
            'objectid' => $childid,
            'context' => \context_system::instance(),
            'relateduserid' => $child->userid ?: null,
            // No adult: the child leaves the whole family, so every adult of it loses the child.
            'other' => ['familyid' => (int) $child->familyid, 'adultid' => null, 'reason' => $reason],
        ])->trigger();
        roles::sync_family((int) $child->familyid);
        // Whoever of that family saw the code the child's own page shows must not come back with it, nor, when a person
        // took the child out, with the family's invitation; the pages make new ones when they are opened. Not for the
        // same adult twice (merged), who stays with the child.
        if ($reason !== 'merged') {
            $familyid = $reason === 'manual' ? (int) $child->familyid : 0;
            self::revoke_shown_codes($familyid, $child->userid ? [(int) $child->userid] : []);
        }
    }

    /**
     * An adult leaves a family. Taken out by a person (manual), the family's invitation and the codes its children's
     * own pages show stop working, since the adult may have seen them; taken out by the school's data (import), only
     * the invitation, which is all an adult who never joined can have seen. The pages make new ones when opened.
     *
     * @param int $adultid The adult.
     * @param string $reason Why: manual, import (the school's data no longer has them in the household), closed or
     *     merged (the same adult twice, see local\claimmail::merge()).
     * @return void
     */
    public static function remove_adult(int $adultid, string $reason = 'manual'): void {
        global $DB;

        $adult = $DB->get_record('local_oksigeniafamily_adult', ['id' => $adultid], '*', MUST_EXIST);
        if ($adult->status !== self::ACTIVE) {
            return;
        }
        $DB->update_record('local_oksigeniafamily_adult', (object) [
            'id' => $adultid, 'status' => self::LEFT, 'timemodified' => time(),
        ]);

        event\adult_removed::create([
            'objectid' => $adultid,
            'context' => \context_system::instance(),
            'relateduserid' => $adult->userid ?: null,
            'other' => ['familyid' => (int) $adult->familyid, 'reason' => $reason],
        ])->trigger();
        roles::sync_family((int) $adult->familyid);
        if ($reason === 'manual' || $reason === 'import') {
            $children = $reason !== 'manual' ? [] : $DB->get_fieldset_select(
                'local_oksigeniafamily_child',
                'userid',
                'familyid = :familyid AND status = :active AND userid IS NOT NULL',
                ['familyid' => (int) $adult->familyid, 'active' => self::ACTIVE]
            );
            self::revoke_shown_codes((int) $adult->familyid, array_map('intval', $children));
        }
    }

    /**
     * Stop the codes the pages show and show again: a family's invitation, and the codes of some students' own pages. A
     * letter's code is never touched here (the school withdraws it, from the family's page). Nothing is logged: these
     * are made again as soon as the page is opened, as shown_code() replaces them.
     *
     * @param int $familyid The family whose invitation stops; 0 for none.
     * @param int[] $childuserids The students whose page code stops.
     * @return void
     */
    private static function revoke_shown_codes(int $familyid, array $childuserids): void {
        global $DB;

        if ($familyid > 0) {
            $DB->set_field_select(
                'local_oksigeniafamily_claim',
                'status',
                self::REVOKED,
                'familyid = :familyid AND purpose = :purpose AND status = :active',
                ['familyid' => $familyid, 'purpose' => 'invite', 'active' => self::ACTIVE]
            );
        }
        $childuserids = array_values(array_filter($childuserids));
        if ($childuserids) {
            [$in, $params] = $DB->get_in_or_equal($childuserids, SQL_PARAMS_NAMED, 'child');
            $DB->set_field_select(
                'local_oksigeniafamily_claim',
                'status',
                self::REVOKED,
                "userid $in AND purpose = :purpose AND status = :active",
                $params + ['purpose' => 'page', 'active' => self::ACTIVE]
            );
        }
    }

    /**
     * Close a family with no children left: its adults leave it too.
     *
     * @param int $familyid The family.
     * @return void
     */
    public static function close_family(int $familyid): void {
        global $DB;

        $family = $DB->get_record('local_oksigeniafamily_family', ['id' => $familyid], '*', MUST_EXIST);
        if ($family->status !== self::ACTIVE) {
            return;
        }
        foreach ($DB->get_records('local_oksigeniafamily_adult', ['familyid' => $familyid, 'status' => self::ACTIVE]) as $adult) {
            self::remove_adult((int) $adult->id, 'closed');
        }
        $now = time();
        $DB->update_record('local_oksigeniafamily_family', (object) [
            'id' => $familyid, 'status' => self::CLOSED, 'timeclosed' => $now, 'timemodified' => $now,
        ]);

        event\family_closed::create(['objectid' => $familyid, 'context' => \context_system::instance()])->trigger();
        roles::sync_family($familyid);
    }

    /**
     * A new code for a student, for a letter, a QR at enrolment or a WhatsApp sign-up. Only its hash is kept: it exists
     * in the clear only in what this returns, so whoever asks for it shows it at once, and a lost one is replaced by a
     * new one. Do not put it in logs, events, caches or the session.
     *
     * @param int $childuserid The student's account.
     * @param int $days How long it works, or 0 for the site's setting.
     * @return string The code (eight letters and digits that are not read wrong).
     */
    public static function issue_claim_code(int $childuserid, int $days = 0): string {
        global $DB, $USER;

        $days = $days ?: max(1, (int) get_config('local_oksigeniafamily', 'claimdays') ?: 60);
        $alphabet = self::CODE_ALPHABET;
        do {
            $code = '';
            for ($i = 0; $i < self::CODE_LENGTH; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $hash = hash('sha256', $code);
        } while ($DB->record_exists('local_oksigeniafamily_claim', ['codehash' => $hash]));

        $now = time();
        $claimid = $DB->insert_record('local_oksigeniafamily_claim', (object) [
            'userid' => $childuserid, 'codehash' => $hash, 'status' => self::ACTIVE, 'claims' => 0,
            'expires' => $now + $days * DAYSECS, 'usermodified' => (int) ($USER->id ?? 0), 'timecreated' => $now,
        ]);

        event\code_issued::create([
            'objectid' => $claimid,
            'context' => \context_system::instance(),
            'relateduserid' => $childuserid,
        ])->trigger();

        return $code;
    }

    /**
     * Claim a student's code: the adult joins the student's family. An adult already known brings the student into their
     * own family; a new one starts a family of their own, so two parents who live apart each have theirs. The student's
     * other adults and the school are told.
     *
     * An adult is known by their account, which the caller has to have checked, or by their phone only when `$phoneverified`
     * says that someone vouches for the number, as WhatsApp does for who writes from it. A phone that was only typed proves
     * nothing: anyone with a code who knows another parent's number would get into that adult's family. So it never joins
     * an adult who is already there, nor changes anything of theirs: it is kept with a new adult, in a new family.
     *
     * A household the school's data brought, while nobody of it has joined, is joined with every child in it only by
     * one of its own adults: their account, their number vouched for, or an account with the e-mail the school's data
     * has for them. The code alone gives its student alone, in a new family. A student, signed in, never joins as an
     * adult a family they or a brother or sister are a child of.
     *
     * It does not slow down guessing by itself: the claim page does, by the visitor's address. A caller that gets codes
     * from a shared address, such as WhatsApp (everything comes from Meta's addresses), slows down by its own key, such
     * as the phone number.
     *
     * @param string $code The code, as typed (spaces, dashes and case do not matter).
     * @param array $adult Any of firstname, lastname, email, phone (E.164) and userid of who claims it; and adultid, the record
     *     of an adult the caller already knows to be them, such as a Telegram chat they have joined before. Never from anything
     *     a visitor can type.
     * @param bool $phoneverified Whether the phone is proved to belong to who claims.
     * @return stdClass The adult record, with `childuserid` and `childid` of the student joined.
     * @throws \moodle_exception When the code does not exist, has expired or was revoked.
     */
    public static function claim(string $code, array $adult, bool $phoneverified = false): stdClass {
        $code = strtoupper(preg_replace('/[\s-]+/', '', $code));
        // One claim at a time for the same person or the same code: a double tap, or the app trying again, would otherwise
        // find no adult yet in both, and make two families.
        $who = !empty($adult['userid']) ? 'u' . (int) $adult['userid'] : 'c' . substr(hash('sha256', $code), 0, 32);
        $lock = \core\lock\lock_config::get_lock_factory('local_oksigeniafamily_claim')->get_lock($who, 10);
        if (!$lock) {
            throw new \moodle_exception('claimbusy', 'local_oksigeniafamily');
        }
        try {
            return self::claim_locked($code, $adult, $phoneverified);
        } finally {
            $lock->release();
        }
    }

    /**
     * The claim itself, once nobody else is claiming for the same person or code.
     *
     * @param string $code The code, already cleaned.
     * @param array $adult As for claim().
     * @param bool $phoneverified As for claim().
     * @return stdClass As for claim().
     */
    private static function claim_locked(string $code, array $adult, bool $phoneverified): stdClass {
        global $DB;

        $claim = $DB->get_record('local_oksigeniafamily_claim', ['codehash' => hash('sha256', $code)]);
        if (!$claim || $claim->status !== self::ACTIVE || $claim->expires < time()) {
            throw new \moodle_exception('claiminvalid', 'local_oksigeniafamily');
        }
        // The letter goes home in the student's bag: the student, signed in, must not join their own family as an adult
        // (and then answer the requests about themselves).
        if (!empty($adult['userid']) && (int) $adult['userid'] === (int) $claim->userid) {
            throw new \moodle_exception('claimself', 'local_oksigeniafamily');
        }
        if (!empty($claim->familyid)) {
            return self::claim_invite($claim, $adult, $phoneverified);
        }

        $known = null;
        if (!empty($adult['adultid'])) {
            $known = $DB->get_record(
                'local_oksigeniafamily_adult',
                ['id' => $adult['adultid'], 'status' => self::ACTIVE],
                '*',
                IGNORE_MISSING
            ) ?: null;
        }
        if (!$known && !empty($adult['userid'])) {
            $known = $DB->get_record(
                'local_oksigeniafamily_adult',
                ['userid' => $adult['userid'], 'status' => self::ACTIVE],
                '*',
                IGNORE_MULTIPLE
            ) ?: null;
        }
        if (!$known && $phoneverified && !empty($adult['phone'])) {
            // Also a quiet adult of a household the school's data brought: the school vouched for their number.
            $known = self::adult_by_phone($adult['phone']);
        }
        if (!$known && empty($adult['userid'])) {
            // The same page sent again (a reload): the adult it made a moment ago, in a family that has only this child,
            // so that it gives nothing the code alone would not.
            $known = self::same_recent_adult(self::families_of_only((int) $claim->userid), $adult);
        }
        // A household the school's data brought where nobody has joined yet is joined, with every child in it, brothers
        // and sisters too, only by one of its own adults: by their account or their number vouched for (above), or by
        // an account with the e-mail the school's data has for them. The code alone proves nothing about who holds it:
        // the student sees it on their own page. Whoever else claims it gets that student alone, in a family of their
        // own, and the household goes on waiting for its adults.
        $waiting = $known ? [] : self::waiting_families((int) $claim->userid);
        if ($waiting && !empty($adult['userid'])) {
            $known = self::quiet_adult_of_user($waiting, (int) $adult['userid']);
        }
        // The letter goes home in the bag of any of the brothers and sisters: a student, signed in, never becomes an
        // adult of a family they are a child of, nor of a brother's or a sister's.
        if (!empty($adult['userid']) && self::is_child_near((int) $adult['userid'], (int) $claim->userid, $known)) {
            throw new \moodle_exception('claimself', 'local_oksigeniafamily');
        }
        if ($known && $waiting && empty($known->userid)) {
            self::set_adult_user((int) $known->id, (int) $adult['userid']);
        }
        $isnew = !$known;
        if ($isnew) {
            $familyid = self::create_family('claim');
            $data = ['phoneverified' => $phoneverified] + array_diff_key($adult, ['quiet' => 0]);
            $adultid = self::add_adult($familyid, $data);
        } else {
            $familyid = (int) $known->familyid;
            $adultid = (int) $known->id;
            self::wake_adult($adultid);
            // An adult known by their account who now gives a phone keeps it, so WhatsApp finds them. A number nobody
            // vouches for (typed on a page, or carried by the link sent to an account) never takes the place of one
            // somebody vouched for.
            $replaces = $phoneverified || empty($known->phoneverified);
            if (!empty($adult['phone']) && $adult['phone'] !== $known->phone && $replaces) {
                self::update_adult($adultid, ['phone' => $adult['phone'], 'phoneverified' => $phoneverified]);
            }
        }
        $others = self::adults_of_child((int) $claim->userid);
        $had = !$isnew && $DB->record_exists(
            'local_oksigeniafamily_child',
            ['familyid' => $familyid, 'userid' => (int) $claim->userid, 'status' => self::ACTIVE]
        );
        $childid = self::add_child($familyid, (int) $claim->userid);
        $DB->set_field('local_oksigeniafamily_claim', 'claims', (int) $claim->claims + 1, ['id' => $claim->id]);

        $record = $DB->get_record('local_oksigeniafamily_adult', ['id' => $adultid], '*', MUST_EXIST);
        // The others hear of it once: an adult who already had the child (a reload, the same code again) is no news.
        if (!$had) {
            $others = array_filter($others, fn($other) => (int) $other->id !== $adultid);
            local\notices::new_adult($record, (int) $claim->userid, $others);
        }
        $record->childuserid = (int) $claim->userid;
        $record->childid = $childid;

        return $record;
    }

    /**
     * An adult without an account who joined one of these families a moment ago, with the same name and the same phone
     * or e-mail: the same person sending the page again (a reload), not a new one. Only within a day, only one a claim
     * made (never one the school's data brought, nor a quiet one), and never one with an account: a phone typed proves
     * nothing, so this never takes anyone into somebody else's record that the school or an account vouches for.
     *
     * @param int[] $familyids The families to look in.
     * @param array $adult As for claim().
     * @return stdClass|null The adult record, or null.
     */
    private static function same_recent_adult(array $familyids, array $adult): ?stdClass {
        global $DB;

        $name = local\names::fold(($adult['firstname'] ?? '') . ' ' . ($adult['lastname'] ?? ''));
        $phone = (string) ($adult['phone'] ?? '');
        $email = \core_text::strtolower(trim((string) ($adult['email'] ?? '')));
        if (!$familyids || $name === '' || ($phone === '' && $email === '')) {
            return null;
        }
        [$in, $params] = $DB->get_in_or_equal(array_values(array_unique($familyids)), SQL_PARAMS_NAMED, 'family');
        $recent = $DB->get_records_select(
            'local_oksigeniafamily_adult',
            "familyid $in AND status = :active AND userid IS NULL AND sourcekey IS NULL AND quiet = 0
                AND timecreated >= :since",
            $params + ['active' => self::ACTIVE, 'since' => time() - DAYSECS],
            'id DESC'
        );
        foreach ($recent as $one) {
            $samephone = $phone !== '' && (string) $one->phone === $phone;
            $samemail = $email !== '' && \core_text::strtolower(trim((string) $one->email)) === $email;
            if (($samephone || $samemail) && local\names::fold($one->firstname . ' ' . $one->lastname) === $name) {
                return $one;
            }
        }

        return null;
    }

    /**
     * The open families of a student where they are the only child.
     *
     * @param int $childuserid The student.
     * @return int[] Family ids.
     */
    private static function families_of_only(int $childuserid): array {
        global $DB;

        return array_map('intval', $DB->get_fieldset_sql(
            "SELECT c.familyid
               FROM {local_oksigeniafamily_child} c
               JOIN {local_oksigeniafamily_family} f ON f.id = c.familyid AND f.status = :factive
              WHERE c.userid = :userid AND c.status = :cactive
                AND NOT EXISTS (SELECT 1 FROM {local_oksigeniafamily_child} o
                                 WHERE o.familyid = c.familyid AND o.status = :oactive AND o.id <> c.id)",
            ['userid' => $childuserid, 'factive' => self::ACTIVE, 'cactive' => self::ACTIVE, 'oactive' => self::ACTIVE]
        ));
    }

    /**
     * The open households of a student that the school's data brought and nobody has joined yet: every adult in them is
     * quiet.
     *
     * @param int $childuserid The student.
     * @return int[] Family ids, the oldest first.
     */
    private static function waiting_families(int $childuserid): array {
        global $DB;

        $ids = $DB->get_fieldset_sql(
            "SELECT f.id
               FROM {local_oksigeniafamily_child} c
               JOIN {local_oksigeniafamily_family} f ON f.id = c.familyid AND f.status = :factive
              WHERE c.userid = :userid AND c.status = :cactive
                AND EXISTS (SELECT 1 FROM {local_oksigeniafamily_adult} q
                             WHERE q.familyid = f.id AND q.status = :qactive AND q.quiet = 1)
                AND NOT EXISTS (SELECT 1 FROM {local_oksigeniafamily_adult} a
                                 WHERE a.familyid = f.id AND a.status = :aactive AND a.quiet = 0)
           ORDER BY f.id",
            [
                'userid' => $childuserid, 'factive' => self::ACTIVE, 'cactive' => self::ACTIVE, 'qactive' => self::ACTIVE,
                'aactive' => self::ACTIVE,
            ]
        );

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Whether an account is a child of an open family of a student (a brother or a sister, or the student themselves),
     * or of the family an adult already known to be them is in: then it never joins as an adult, as claim_invite()
     * refuses a child of the family that invites.
     *
     * @param int $userid The account that claims.
     * @param int $childuserid The student of the code.
     * @param stdClass|null $known The adult record the claim would join, if any.
     * @return bool
     */
    private static function is_child_near(int $userid, int $childuserid, ?stdClass $known): bool {
        global $DB;

        return $DB->record_exists_sql(
            "SELECT 1
               FROM {local_oksigeniafamily_child} c
               JOIN {local_oksigeniafamily_family} f ON f.id = c.familyid AND f.status = :factive
              WHERE c.userid = :userid AND c.status = :cactive
                AND (c.familyid = :familyid
                     OR c.familyid IN (SELECT s.familyid
                                         FROM {local_oksigeniafamily_child} s
                                        WHERE s.userid = :childuserid AND s.status = :sactive))",
            [
                'userid' => $userid, 'childuserid' => $childuserid, 'familyid' => $known ? (int) $known->familyid : 0,
                'factive' => self::ACTIVE, 'cactive' => self::ACTIVE, 'sactive' => self::ACTIVE,
            ]
        );
    }

    /**
     * The quiet adult of some households who is an account, by that account's e-mail as the school's data has it, and
     * with its first name: an address the school's data has for a parent may be a teacher's, typed by mistake.
     *
     * @param int[] $familyids Households.
     * @param int $userid The account.
     * @return stdClass|null The adult record, or null.
     */
    private static function quiet_adult_of_user(array $familyids, int $userid): ?stdClass {
        global $DB;

        $user = \core_user::get_user($userid);
        $email = $user && empty($user->deleted) ? \core_text::strtolower(trim((string) $user->email)) : '';
        if ($email === '' || !$familyids) {
            return null;
        }
        [$in, $params] = $DB->get_in_or_equal($familyids, SQL_PARAMS_NAMED, 'family');
        $quiet = $DB->get_records_select(
            'local_oksigeniafamily_adult',
            "familyid $in AND status = :active AND quiet = 1",
            $params + ['active' => self::ACTIVE],
            'id'
        );
        foreach ($quiet as $adult) {
            $theirs = empty($adult->userid) || (int) $adult->userid === $userid;
            $named = local\names::same_first((string) $adult->firstname, (string) $user->firstname);
            if ($theirs && $named && \core_text::strtolower(trim((string) $adult->email)) === $email) {
                return $adult;
            }
        }

        return null;
    }

    /**
     * What a student's family needs to join, for a page of the student's own («For your family» in Meals): their code, its
     * QR and the steps. The code is one for these pages, kept until it is near its end, and then a new one; a letter's
     * code is never touched. Shown to the student, as the letter they take home is. The caller checks that whoever looks
     * is the student or the centre's staff.
     *
     * @param int $userid The student.
     * @return array|null code (as people read it), url (the claim page with the code), address (short, to type), qrsvg (no
     *     size of its own and currentColor: the page sizes and colours it, on a light ground), expires (a time), adults
     *     (how many of the family have joined), inapp (how many of them use the app), app (whether families get into the
     *     app here) and steps (letters::join_steps()); null without Family, or for an account that is not there.
     */
    public static function join_for_student(int $userid): ?array {
        $user = \core_user::get_user($userid);
        if (!self::is_available() || !$user || $user->deleted || isguestuser($user)) {
            return null;
        }

        [$code, $expires] = self::shown_code($userid, 'page', null);

        $shown = local\letters::show_code($code);
        $url = (new \moodle_url('/local/oksigeniafamily/claim.php'))->out(false) . '#code=' . $shown;
        $address = local\letters::claim_address();
        $adults = self::adults_of_child($userid);
        $inapp = 0;
        // Whether they use the app, Oksigenia Aty knows, where it is installed.
        $aty = '\\local_oksigeniaaty\\local\\familyaccount';
        $canask = class_exists($aty) && method_exists($aty, 'in_app');
        foreach ($adults as $adult) {
            if ($canask && !empty($adult->userid) && $aty::in_app((int) $adult->userid)) {
                $inapp++;
            }
        }

        return [
            'code' => $shown,
            'url' => $url,
            'address' => $address,
            'qrsvg' => local\letters::qr_svg($url, get_string('letter:qr', 'local_oksigeniafamily'), 'currentColor'),
            'expires' => $expires,
            'adults' => count($adults),
            'inapp' => $inapp,
            'app' => local\letters::app() !== null,
            'steps' => local\letters::join_steps($address),
        ];
    }

    /**
     * A code a page shows, and can show again: one at a time for the purpose (and family), kept until a week before its
     * end and then a new one; sealed with the site's key, which lives outside the database.
     *
     * @param int $userid The student it is for (for an invitation, a child of the family).
     * @param string $purpose page or invite.
     * @param int|null $familyid For an invitation, the family.
     * @return array [the code, when it expires]
     */
    private static function shown_code(int $userid, string $purpose, ?int $familyid): array {
        global $DB;

        $where = ['userid' => $userid, 'purpose' => $purpose, 'status' => self::ACTIVE];
        $select = 'userid = :userid AND purpose = :purpose AND status = :status';
        if ($familyid !== null) {
            unset($where['userid']);
            $select = 'familyid = :familyid AND purpose = :purpose AND status = :status';
            $where['familyid'] = $familyid;
        }
        $claims = $DB->get_records_select(
            'local_oksigeniafamily_claim',
            $select . ' AND expires > :soon',
            $where + ['soon' => time() + 7 * DAYSECS],
            'expires DESC'
        );
        foreach ($claims as $claim) {
            try {
                $plain = \core\encryption::decrypt((string) $claim->codesealed);
            } catch (\Throwable $e) {
                continue;
            }
            if (hash('sha256', $plain) === $claim->codehash) {
                return [$plain, (int) $claim->expires];
            }
        }

        // One at a time: the one before goes; the letters' stay.
        $DB->set_field_select('local_oksigeniafamily_claim', 'status', self::REVOKED, $select, $where);
        $code = self::issue_claim_code($userid, $purpose === 'invite' ? 14 : 0);
        $claim = $DB->get_record('local_oksigeniafamily_claim', ['codehash' => hash('sha256', $code)], '*', MUST_EXIST);
        $DB->update_record('local_oksigeniafamily_claim', (object) [
            'id' => $claim->id, 'purpose' => $purpose, 'familyid' => $familyid,
            'codesealed' => \core\encryption::encrypt($code),
        ]);

        return [$code, (int) $claim->expires];
    }

    /**
     * What an adult of a family shows another adult of the same household (a parent without an account of the site, a
     * grandmother) to bring them in: a code that joins this family, with all its children, rather than starting a family
     * of its own as a student's code does. The caller checks that whoever looks is an adult of the family.
     *
     * @param int $familyid The family.
     * @return array|null As join_for_student(), without adults and inapp; null if the family has no child to join.
     */
    public static function invite_for_family(int $familyid): ?array {
        global $DB;

        $active = $DB->record_exists('local_oksigeniafamily_family', ['id' => $familyid, 'status' => self::ACTIVE]);
        if (!self::is_available() || !$active) {
            return null;
        }
        $children = $DB->get_records_select(
            'local_oksigeniafamily_child',
            'familyid = :familyid AND status = :status AND userid IS NOT NULL',
            ['familyid' => $familyid, 'status' => self::ACTIVE],
            'id',
            'id, userid',
            0,
            1
        );
        if (!$children) {
            return null;
        }
        [$code, $expires] = self::shown_code((int) reset($children)->userid, 'invite', $familyid);
        $shown = local\letters::show_code($code);
        $url = (new \moodle_url('/local/oksigeniafamily/claim.php'))->out(false) . '#code=' . $shown;
        $address = local\letters::claim_address();

        return [
            'code' => $shown,
            'url' => $url,
            'address' => $address,
            'qrsvg' => local\letters::qr_svg($url, get_string('letter:qr', 'local_oksigeniafamily'), 'currentColor'),
            'expires' => $expires,
            'app' => local\letters::app() !== null,
            'steps' => local\letters::join_steps($address),
        ];
    }

    /**
     * Where «For your family» is: the student's own page, or, with a student, the office's view of theirs.
     *
     * @param int $userid A student, for the office's view; 0 for whoever looks.
     * @return string
     */
    public static function family_page_url(int $userid = 0): string {
        return (new \moodle_url('/local/oksigeniafamily/join.php', $userid ? ['id' => $userid] : []))->out(false);
    }

    /**
     * «For your family», short, for a message to families (they open it signed in as the student): made once with the
     * site's link shortener and kept; the full address where there is none.
     *
     * @return string
     */
    public static function family_page_short(): string {
        $long = self::family_page_url();
        $short = local\letters::kept_short('joinshort', $long, get_string('join:title', 'local_oksigeniafamily'));

        return $short !== '' ? $short : $long;
    }

    /**
     * The student a code is for, while it still joins: to check it before anything is sent, without claiming it.
     *
     * @param string $code The code, as typed.
     * @return int|null The student's account; null if the code does not join anyone.
     */
    public static function code_student(string $code): ?int {
        global $DB;

        $code = strtoupper(preg_replace('/[\s-]+/', '', $code));
        $claim = $DB->get_record('local_oksigeniafamily_claim', ['codehash' => hash('sha256', $code)]);

        return $claim && $claim->status === self::ACTIVE && $claim->expires >= time() ? (int) $claim->userid : null;
    }

    /**
     * The claim of an invitation: the adult joins the family that invited them, with all its children; an adult of that
     * family already is not added again, by their account or, without one, when it is the same page sent again a moment
     * later (same_recent_adult()).
     *
     * @param stdClass $claim The invitation.
     * @param array $adult As for claim().
     * @param bool $phoneverified As for claim().
     * @return stdClass As for claim(), with the child of the invitation when it is still in the family, or another.
     */
    private static function claim_invite(stdClass $claim, array $adult, bool $phoneverified): stdClass {
        global $DB;

        $familyid = (int) $claim->familyid;
        if (!$DB->record_exists('local_oksigeniafamily_family', ['id' => $familyid, 'status' => self::ACTIVE])) {
            throw new \moodle_exception('claiminvalid', 'local_oksigeniafamily');
        }
        $children = $DB->get_records('local_oksigeniafamily_child', ['familyid' => $familyid, 'status' => self::ACTIVE], 'id');
        $childuserids = array_values(array_filter(array_map(fn($child) => (int) $child->userid, $children)));
        // A family with no child left to see: the invitation joins nobody.
        if (!$childuserids) {
            throw new \moodle_exception('claiminvalid', 'local_oksigeniafamily');
        }
        if (!empty($adult['userid']) && in_array((int) $adult['userid'], $childuserids, true)) {
            throw new \moodle_exception('claimself', 'local_oksigeniafamily');
        }

        $known = empty($adult['userid']) ? self::same_recent_adult([$familyid], $adult) : $DB->get_record(
            'local_oksigeniafamily_adult',
            ['familyid' => $familyid, 'userid' => $adult['userid'], 'status' => self::ACTIVE],
            '*',
            IGNORE_MULTIPLE
        );
        $others = $DB->get_records(
            'local_oksigeniafamily_adult',
            ['familyid' => $familyid, 'status' => self::ACTIVE, 'quiet' => 0]
        );
        $adultid = $known ? (int) $known->id
            : self::add_adult($familyid, ['phoneverified' => $phoneverified] + array_diff_key($adult, ['quiet' => 0]));
        self::wake_adult($adultid);
        $DB->set_field('local_oksigeniafamily_claim', 'claims', (int) $claim->claims + 1, ['id' => $claim->id]);

        $record = $DB->get_record('local_oksigeniafamily_adult', ['id' => $adultid], '*', MUST_EXIST);
        // The child of the invitation, while they are in the family; else the first who is.
        $first = in_array((int) $claim->userid, $childuserids, true) ? (int) $claim->userid : $childuserids[0];
        if (!$known) {
            local\notices::new_adult($record, $first, array_filter($others, fn($other) => (int) $other->id !== $adultid));
        }
        $record->childuserid = $first;
        $record->childid = (int) $DB->get_field(
            'local_oksigeniafamily_child',
            'id',
            ['familyid' => $familyid, 'userid' => $first, 'status' => self::ACTIVE],
            IGNORE_MULTIPLE
        );

        return $record;
    }

    /**
     * Stop a student's codes from joining anyone (a letter that went astray). Who already joined stays.
     *
     * @param int $childuserid The student whose codes are revoked.
     * @return int How many codes it stopped.
     */
    public static function revoke_claim_codes(int $childuserid): int {
        global $DB;

        $conditions = ['userid' => $childuserid, 'status' => self::ACTIVE];
        $count = $DB->count_records('local_oksigeniafamily_claim', $conditions);
        if (!$count) {
            return 0;
        }
        $DB->set_field('local_oksigeniafamily_claim', 'status', self::REVOKED, $conditions);

        event\codes_revoked::create([
            'context' => \context_system::instance(),
            'relateduserid' => $childuserid,
            'other' => ['count' => $count],
        ])->trigger();

        return $count;
    }

    /**
     * For a plugin that gives a student's family a key of its own (the canteen's link with its PIN): the requests for
     * consent about that student still waiting for an answer, each with a signed link to answer them that dies with the
     * request. Show them only behind the key. The page asks who answers, from the student's adults in the records; a
     * request that needs every adult is not in the list.
     *
     * @param int $childuserid The student.
     * @param int $keyid The id of your key (never the key itself): it is kept with the answer as what it was answered with.
     * @return array Each with requestid, title (plain text), deadline (a time) and url (a moodle_url), soonest first.
     */
    public static function pending_consents(int $childuserid, int $keyid): array {
        return local\consent::pending_for_child($childuserid, $keyid, time());
    }

    /**
     * Whether this site keeps students' photos in Family (a switch in its settings). When it does not, the photo
     * functions give nothing and the photo_allowed_for_* functions say false for everyone: a plugin with photos of its
     * own keeps using those.
     *
     * @return bool
     */
    public static function photos_enabled(): bool {
        return local\photos::enabled();
    }

    /**
     * Whether this site asks families for consent through Family (a switch in its settings). When it does not, the school
     * sees no requests and families have none to answer: a plugin that links to them offers nothing.
     *
     * @return bool
     */
    public static function consents_enabled(): bool {
        return local\consent::enabled();
    }

    /**
     * A student's photo, by the child in Family, for a plugin that shows children (a screen, the dining room, the
     * library). It is not given when the student's image may not be shown (photo_allowed_for_*): then null, as if there
     * were none. A student with an account shows the picture of their account if it has one, and the photo the
     * school uploaded otherwise. Serving the file (and to whom) is the calling plugin's business.
     *
     * @param int $childid The child.
     * @return \stored_file|null A small JPEG or the account's picture, or null.
     */
    public static function photo_for_child(int $childid): ?\stored_file {
        return local\photos::for_child($childid);
    }

    /**
     * A student's photo by their account (see photo_for_child).
     *
     * @param int $userid The student's account.
     * @return \stored_file|null
     */
    public static function photo_for_user(int $userid): ?\stored_file {
        return local\photos::for_user($userid);
    }

    /**
     * A student's photo by their key in the school's data, such as cifra:123 (see photo_for_child).
     *
     * @param string $sourcekey The key.
     * @return \stored_file|null
     */
    public static function photo_for_key(string $sourcekey): ?\stored_file {
        return local\photos::for_key($sourcekey);
    }

    /**
     * Whether a student's image may be shown: yes unless a no counts, from an adult of the family or from the school
     * (whose answer counts until an adult answers after it). With no answer, true. Use it to tell "no photo" from "not to
     * be shown".
     *
     * @param int $childid The child.
     * @return bool
     */
    public static function photo_allowed_for_child(int $childid): bool {
        return local\photos::enabled() && local\standing::may_show(local\standing::keys_for_child($childid));
    }

    /**
     * Whether a student's image may be shown, by their account (see photo_allowed_for_child).
     *
     * @param int $userid The student's account.
     * @return bool
     */
    public static function photo_allowed_for_user(int $userid): bool {
        return local\photos::enabled() && local\standing::may_show(local\standing::keys_for_user($userid));
    }

    /**
     * Whether a student's image may be shown, by their key in the school's data (see photo_allowed_for_child).
     *
     * @param string $sourcekey The key.
     * @return bool
     */
    public static function photo_allowed_for_key(string $sourcekey): bool {
        return local\photos::enabled() && local\standing::may_show(local\standing::keys_for_key($sourcekey));
    }

    /**
     * Store a student's photo, replacing the one before: for a plugin that brings photos from elsewhere (a list it
     * kept). The image is made small, as JPEG and without its EXIF data. It is erased when the student has not been seen
     * here for the grace period.
     *
     * @param string $sourcekey The student's key in the school's data, such as cifra:123.
     * @param string $contents The image (JPEG, PNG, GIF or WebP).
     * @return bool False if it is not an image or the site keeps no photos.
     */
    public static function set_photo(string $sourcekey, string $contents): bool {
        return local\photos::enabled() && local\photos::save($sourcekey, $contents);
    }

    /**
     * Store the photo of a student with an account (see set_photo). It is used when their account has no picture.
     *
     * @param int $userid The student's account.
     * @param string $contents The image.
     * @return bool
     */
    public static function set_photo_for_user(int $userid, string $contents): bool {
        return local\photos::enabled() && local\photos::save(local\photos::user_key($userid), $contents);
    }

    /**
     * The key of the student a number stands for, to call the functions above with what a list or a file name says:
     * the account with that ID number, or a child of Family with that key in a source.
     *
     * @param string $number The number.
     * @param string $kind 'idnumber', or 'source:' and the source (source:cifra).
     * @return string|null The key (user:45 or cifra:123), or null if it matches no student.
     */
    public static function student_key(string $number, string $kind): ?string {
        return local\photos::key_for_number($number, $kind);
    }
}
