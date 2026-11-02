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
     * @return stdClass[] Child records (id, familyid, userid, sourcekey), keyed by id.
     */
    public static function children_of_adult(int $adultid): array {
        global $DB;

        $sql = "SELECT c.id, c.familyid, c.userid, c.sourcekey
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
     * The adults of a student, to tell them something (a late book, a new adult).
     *
     * @param int $childuserid The student's account.
     * @return stdClass[] Adult records (id, familyid, firstname, lastname, email, phone, userid), keyed by id.
     */
    public static function adults_of_child(int $childuserid): array {
        global $DB;

        $sql = "SELECT a.id, a.familyid, a.firstname, a.lastname, a.email, a.phone, a.userid
                  FROM {local_oksigeniafamily_child} c
                  JOIN {local_oksigeniafamily_family} f ON f.id = c.familyid AND f.status = :factive
                  JOIN {local_oksigeniafamily_adult} a ON a.familyid = f.id AND a.status = :aactive
                 WHERE c.userid = :userid AND c.status = :cactive";

        return $DB->get_records_sql($sql, [
            'userid' => $childuserid, 'factive' => self::ACTIVE, 'aactive' => self::ACTIVE, 'cactive' => self::ACTIVE,
        ]);
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
     *     the number), sourcekey and userid.
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
     * Change an adult's details. When anything changes, the event `adult_updated` carries the phone they had, so a plugin
     * that linked the old number lets it go at once.
     *
     * @param int $adultid The adult.
     * @param array $data Any of firstname, lastname, email, phone (E.164) and sourcekey.
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
        // A number stops counting as theirs when it changes, unless whoever changes it vouches for the new one.
        $verified = array_key_exists('phone', $changes) ? 0 : (int) $adult->phoneverified;
        if (!empty($data['phoneverified']) && ($data['phone'] ?? $adult->phone)) {
            $verified = 1;
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
            'other' => ['familyid' => (int) $adult->familyid, 'oldphone' => $adult->phone],
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
     * A child leaves a family: the student left the school, or the school took them out of it.
     *
     * @param int $childid The child.
     * @param string $reason Why: deleted, suspended, cohort, import (a complete list without them) or manual.
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
            'id' => $childid, 'status' => self::LEFT, 'timeleft' => $now, 'timemodified' => $now,
        ]);

        event\child_unlinked::create([
            'objectid' => $childid,
            'context' => \context_system::instance(),
            'relateduserid' => $child->userid ?: null,
            // No adult: the child leaves the whole family, so every adult of it loses the child.
            'other' => ['familyid' => (int) $child->familyid, 'adultid' => null, 'reason' => $reason],
        ])->trigger();
        roles::sync_family((int) $child->familyid);
    }

    /**
     * An adult leaves a family.
     *
     * @param int $adultid The adult.
     * @param string $reason Why: manual or closed.
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
        global $DB;

        $code = strtoupper(preg_replace('/[\s-]+/', '', $code));
        $claim = $DB->get_record('local_oksigeniafamily_claim', ['codehash' => hash('sha256', $code)]);
        if (!$claim || $claim->status !== self::ACTIVE || $claim->expires < time()) {
            throw new \moodle_exception('claiminvalid', 'local_oksigeniafamily');
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
            );
        }
        if (!$known && $phoneverified && !empty($adult['phone'])) {
            $known = self::adult_by_phone($adult['phone']);
        }
        $isnew = !$known;
        if ($isnew) {
            $familyid = self::create_family('claim');
            $adultid = self::add_adult($familyid, ['phoneverified' => $phoneverified] + $adult);
        } else {
            $familyid = (int) $known->familyid;
            $adultid = (int) $known->id;
            // An adult known by their account who now gives a phone keeps it, so WhatsApp finds them.
            if (!empty($adult['phone']) && $adult['phone'] !== $known->phone) {
                self::update_adult($adultid, ['phone' => $adult['phone'], 'phoneverified' => $phoneverified]);
            }
        }
        $others = self::adults_of_child((int) $claim->userid);
        $childid = self::add_child($familyid, (int) $claim->userid);
        $DB->set_field('local_oksigeniafamily_claim', 'claims', (int) $claim->claims + 1, ['id' => $claim->id]);

        $record = $DB->get_record('local_oksigeniafamily_adult', ['id' => $adultid], '*', MUST_EXIST);
        local\notices::new_adult($record, (int) $claim->userid, array_filter($others, fn($other) => (int) $other->id !== $adultid));
        $record->childuserid = (int) $claim->userid;
        $record->childid = $childid;

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
}
