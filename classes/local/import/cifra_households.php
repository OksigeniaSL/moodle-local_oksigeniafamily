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

namespace local_oksigeniafamily\local\import;

use local_oksigeniafamily\api;
use local_oksigeniafamily\local\cifra;
use local_oksigeniafamily\local\families;
use local_oksigeniafamily\local\names;
use local_oksigeniafamily\local\phone;
use local_oksigeniafamily\local\readers\cifra_people;

/**
 * The households of the school's management program, every night, when the school uses Cifra: one source next to a CSV
 * file, and the same importer takes them. Brothers and sisters come in together, and a child who starts school years
 * later joins the household their family already has, on their own.
 *
 * How a household is made: the students who have the same legal guardians in Cifra share one, with those guardians as
 * its adults; a relative who is not one (a grandmother, a parent without custody) is not an adult of it. Only for a
 * child for whom Cifra marks no guardian are all their relatives. A parent of two households (a child with each
 * partner) is in both. Brothers and sisters that Cifra lists among the relatives are children, never adults.
 *
 * Where it goes, in this order, so that a household is never made twice:
 * - the open family that already has its children (by their key in Cifra or their account), made by this source or
 *   some other way (a letter's code, by hand, admissions), which takes the household as it is and keeps its own source;
 * - the household this source made for the same guardians;
 * - a household this source made whose adults are all among the new child's, and that has all of its guardians (by
 *   their id in Cifra, then their e-mail, then their mobile): a child who starts later with the same parents;
 * - otherwise, a new one.
 *
 * A student's account is the one with their e-mail in Cifra, when only one has it; an adult's, likewise, when it is not
 * a student's and its first name is the adult's (names::same_first()): the e-mail Cifra has for a parent may be a
 * teacher's, typed by mistake, and then it is never linked, and is left for a person to look at (unmatched). Without
 * one, the child is known by their key and the adult has no account: nothing is created or sent.
 * The list of Cifra is complete, so a child without an account who is no longer in it leaves, with the importer's brake.
 * So does an adult it brought who is no longer in their household in Cifra while they are still quiet (gone_adults()).
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class cifra_households {
    /** The source of the families it makes. */
    public const SOURCE = 'cifra';

    /** The key of a household it makes: this and the signature of its guardians. */
    public const PREFIX = 'cifra:hogar:';

    /** The setting that turns the nightly round on. */
    public const SETTING = 'cifrahouseholds';

    /** Where the last round says how it went. */
    public const STATUS = 'cifrahouseholdsstatus';

    /** Microseconds between two records of Cifra: one student at a time, gently. */
    public const PAUSE = 150000;

    /** Seconds all the records may take: a task of cron never hangs. */
    public const BUDGET = 7200;

    /** Families per batch when applying, each batch in its own transaction. */
    public const BATCH = 200;

    /**
     * Whether the nightly round is on.
     *
     * @return bool
     */
    public static function on(): bool {
        return !empty(get_config('local_oksigeniafamily', self::SETTING));
    }

    /**
     * The students of Cifra who are there now, and the record of each one, with their relatives.
     *
     * @param cifra $client The connection.
     * @param int $now
     * @param int $pause Microseconds between two records.
     * @return array students (as Cifra lists them, by id) and records (by id; a student whose record is gone has none).
     * @throws \moodle_exception When Cifra does not answer well: then nothing is taken.
     */
    public static function read(cifra $client, int $now, int $pause = self::PAUSE): array {
        $students = [];
        foreach (cifra::present($client->people(), $now) as $id => $person) {
            $id = (string) ($person['id'] ?? $id);
            $traits = (array) ($person['caracteristicas'] ?? []);
            if (!preg_match('/^\d+$/', $id) || ($traits && empty($traits['alumno']))) {
                continue;
            }
            $students[$id] = $person;
        }
        $records = [];
        $until = time() + self::BUDGET;
        foreach (array_keys($students) as $index => $id) {
            if (time() > $until) {
                throw new \moodle_exception('cifraerrorincomplete', 'local_oksigeniafamily', '', 'alumnos');
            }
            if ($index > 0 && $pause > 0) {
                usleep($pause);
            }
            $record = $client->get('alumnos', [], (string) $id);
            if (is_array($record) && isset($record['data']['id'])) {
                $record = $record['data'];
            }
            if (is_array($record)) {
                $records[(string) $id] = $record + ['id' => $id];
            }
        }

        return ['students' => $students, 'records' => $records];
    }

    /**
     * The households of some records: the children with the same guardians together, with those guardians as their
     * adults. A relative Cifra does not mark as a legal guardian of the child (a grandmother, an uncle, a parent
     * without custody) is not an adult of the household, who would see the child: only when Cifra marks nobody for the
     * child are all their relatives.
     *
     * @param array[] $records The records of the students, by id.
     * @param int $now
     * @return array[] Each with signature, guardians (keys), children (keys) and adults (the guardians, as
     *     cifra_people::families() gives them, by key, in the order of Cifra), in the order of their signatures.
     */
    public static function groups(array $records, int $now): array {
        $adults = [];
        $ofchild = [];
        $guardiansof = [];
        foreach (cifra_people::families(array_values($records), $now) as $adult) {
            $adults[$adult['key']] = $adult;
            foreach ($adult['children'] as $child) {
                $ofchild[$child][] = $adult['key'];
            }
            foreach ($adult['guardianof'] as $child) {
                $guardiansof[$child][] = $adult['key'];
            }
        }
        $groups = [];
        foreach ($ofchild as $child => $keys) {
            $members = array_values(array_unique($guardiansof[$child] ?? $keys));
            $guardians = $members;
            sort($guardians, SORT_STRING);
            $signature = sha1(implode('|', $guardians));
            $groups[$signature] = $groups[$signature] ?? [
                'signature' => $signature, 'guardians' => $guardians, 'children' => [], 'adults' => [],
            ];
            $groups[$signature]['children'][] = $child;
            foreach ($members as $key) {
                $groups[$signature]['adults'][$key] = $adults[$key];
            }
        }
        ksort($groups, SORT_STRING);
        foreach ($groups as $signature => $group) {
            $children = array_values(array_unique($group['children']));
            sort($children, SORT_NATURAL);
            $groups[$signature]['children'] = $children;
        }

        return array_values($groups);
    }

    /**
     * The rows of some households for the importer, and the families they are already in.
     *
     * @param array[] $groups From groups().
     * @param array[] $students The students of Cifra, by id, as read() gives them.
     * @return array rows (for importer::plan()), joined (family key => the open family made some other way that takes
     *     it), names (each child's key => their name in Cifra, for whoever reads the plan) and unmatched (the adults whose
     *     e-mail in Cifra is that of an account with another first name, each with key, name, email, userid, username
     *     and accountname; never linked by it).
     */
    public static function rows(array $groups, array $students): array {
        global $DB;

        $prefix = importer::phone_prefix();
        $names = [];
        $studentemails = [];
        foreach ($students as $id => $student) {
            $names['cifra:' . $id] = self::student_name($student);
            $email = self::email((string) ($student['email'] ?? ''));
            if ($email !== '') {
                $studentemails[$email] = 'cifra:' . $id;
            }
        }
        $adultemails = [];
        foreach ($groups as $group) {
            foreach ($group['adults'] as $adult) {
                $email = self::email((string) ($adult['email'] ?? ''));
                if ($email !== '' && !isset($studentemails[$email])) {
                    $adultemails[$email] = true;
                }
            }
        }
        $accounts = self::accounts(array_merge(array_keys($studentemails), array_keys($adultemails)));
        $childaccount = [];
        foreach ($studentemails as $email => $key) {
            if (isset($accounts[$email])) {
                $childaccount[$key] = $accounts[$email];
            }
        }
        $childuserids = array_flip(array_map(fn($account) => (int) $account->id, $childaccount));

        // What Family already has: the open families, their children and their adults.
        $known = self::known();
        foreach ($known['childusers'] as $userid => $unused) {
            $childuserids[$userid] = true;
        }

        // Where each household goes. Two households that land in one of this source's are one family in the plan, by its
        // key; a family made some other way takes one household only, or its new adults would come in twice.
        $keys = [];
        $joined = [];
        $taken = [];
        foreach (self::by_children($groups, $childaccount, $known) as [$index, $familyid]) {
            $family = $known['families'][$familyid];
            $ours = $family->source === self::SOURCE && (string) $family->sourcekey !== '';
            if (isset($keys[$index]) || (!$ours && isset($taken[$familyid]))) {
                continue;
            }
            $own = self::PREFIX . $groups[$index]['signature'];
            if ($ours) {
                $keys[$index] = (string) $family->sourcekey;
            } else {
                $keys[$index] = $own;
                $joined[$own] = $familyid;
                $taken[$familyid] = true;
            }
        }
        foreach ($groups as $index => $group) {
            if (isset($keys[$index])) {
                continue;
            }
            $own = self::PREFIX . $group['signature'];
            $byadults = isset($known['bykey'][$own]) ? 0 : self::by_adults($group, $known, $prefix);
            $keys[$index] = $byadults ? (string) $known['families'][$byadults]->sourcekey : $own;
        }

        $rows = [];
        $unmatched = [];
        $line = 0;
        foreach ($groups as $index => $group) {
            foreach ($group['adults'] as $adult) {
                $email = self::email((string) ($adult['email'] ?? ''));
                $account = $email !== '' && !isset($studentemails[$email]) ? ($accounts[$email] ?? null) : null;
                if ($account && isset($childuserids[(int) $account->id])) {
                    // An account that is a student here is never an adult of a family.
                    $account = null;
                }
                if ($account && !names::same_first((string) ($adult['firstname'] ?? ''), (string) $account->firstname)) {
                    // The address is somebody else's account (a teacher's, typed for a parent): a person looks at it.
                    $unmatched[(string) $adult['key']] = [
                        'key' => (string) $adult['key'],
                        'name' => trim(($adult['firstname'] ?? '') . ' ' . ($adult['lastname'] ?? '')),
                        'email' => $email,
                        'userid' => (int) $account->id,
                        'username' => (string) $account->username,
                        'accountname' => trim($account->firstname . ' ' . $account->lastname),
                    ];
                    $account = null;
                }
                $phone = phone::normalise((string) ($adult['phone'] ?? ''), $prefix);
                $rows[] = ['line' => ++$line, 'values' => array_filter([
                    'family' => $keys[$index],
                    'type' => 'adult',
                    'firstname' => self::fit((string) ($adult['firstname'] ?? ''), 100),
                    'lastname' => self::fit((string) ($adult['lastname'] ?? ''), 100),
                    'email' => strlen($email) <= 100 ? $email : '',
                    'phone' => $phone,
                    'username' => $account ? $account->username : '',
                    'key' => $adult['key'],
                ], fn($value) => $value !== '')];
            }
            foreach ($group['children'] as $child) {
                $account = $childaccount[$child] ?? null;
                $rows[] = ['line' => ++$line, 'values' => array_filter([
                    'family' => $keys[$index],
                    'type' => 'child',
                    'username' => $account ? $account->username : '',
                    'key' => $child,
                ], fn($value) => $value !== '')];
            }
        }

        return ['rows' => $rows, 'joined' => $joined, 'names' => $names, 'unmatched' => array_values($unmatched)];
    }

    /**
     * What a round would do, without doing it: Cifra is read, and nothing in Family changes.
     *
     * @param int $now
     * @param cifra|null $client The connection; the site's when null.
     * @param int $pause Microseconds between two records.
     * @return array plan (from importer::plan()), gone (as gone_adults() gives them), names and unmatched (as rows()
     *     gives them), students and households (how many).
     * @throws \moodle_exception When there is no connection or Cifra does not answer well.
     */
    public static function dry_run(int $now, ?cifra $client = null, int $pause = self::PAUSE): array {
        $client = $client ?? cifra::of_site();
        if (!$client) {
            throw new \moodle_exception('cifraerrorsettings', 'local_oksigeniafamily');
        }
        $read = self::read($client, $now, $pause);
        $groups = self::groups($read['records'], $now);
        $rows = self::rows($groups, $read['students']);
        $plan = importer::plan($rows['rows'], self::SOURCE, true, $rows['joined']);

        return [
            'plan' => $plan,
            'gone' => self::gone_adults($plan, $rows['rows']),
            'names' => $rows['names'],
            'unmatched' => $rows['unmatched'],
            'students' => count($read['students']),
            'households' => count($groups),
            'read' => $read,
        ];
    }

    /**
     * The adults this source brought to a household of the plan who are no longer in it in Cifra: a relative who is not
     * a legal guardian (who came in before 0.3.35, when every relative did), or one taken off the student's record.
     *
     * Those still quiet leave it: they never joined nor were welcomed, so all they have (the parent role through an
     * account with their e-mail, their number vouched for) came from the school's data alone, and goes with it. If they
     * should be there, the school marks them as guardians in Cifra and the next night brings them back. Those who have
     * joined, or whom the school welcomed, stay, for a person to look at: a parent without custody may still have the
     * right to know, or a reason not to, that Cifra does not say. A household with a row of Cifra that was not
     * understood is left as it is, since an adult of that row would look gone.
     *
     * @param array $plan From importer::plan(), with this source's rows.
     * @param array[] $rows The rows of the plan, as rows() gives them.
     * @return array leaving (adult ids, to take out) and review (each with familyid, adultid and name, to look at).
     */
    public static function gone_adults(array $plan, array $rows): array {
        $wrong = array_flip(array_map('intval', array_column($plan['errors'], 'line')));
        $unclear = [];
        foreach ($rows as $row) {
            if (isset($wrong[(int) $row['line']])) {
                $unclear[(string) ($row['values']['family'] ?? '')] = true;
            }
        }
        $gone = ['leaving' => [], 'review' => []];
        foreach ($plan['families'] as $family) {
            if (empty($family['id']) || isset($unclear[(string) $family['key']])) {
                continue;
            }
            foreach ($family['adults'] as $adult) {
                $data = $adult['data'];
                $ours = str_starts_with((string) ($data['sourcekey'] ?? ''), self::SOURCE . ':');
                if (empty($adult['id']) || !empty($adult['seen']) || !$ours) {
                    continue;
                }
                if (!empty($data['quiet'])) {
                    $gone['leaving'][] = (int) $adult['id'];
                } else {
                    $gone['review'][] = [
                        'familyid' => (int) $family['id'],
                        'adultid' => (int) $adult['id'],
                        'name' => trim(($data['firstname'] ?? '') . ' ' . ($data['lastname'] ?? '')),
                    ];
                }
            }
        }

        return $gone;
    }

    /**
     * The adults of Family linked to the account that rows() found with another first name: those a person has to unlink
     * (on the household's page, «Edit»). One that is not linked to it needs nothing.
     *
     * @param array[] $unmatched As rows() gives them.
     * @return int[] Adult ids.
     */
    public static function linked_unmatched(array $unmatched): array {
        global $DB;

        $ids = [];
        foreach ($unmatched as $one) {
            $found = $DB->get_fieldset_select(
                'local_oksigeniafamily_adult',
                'id',
                'sourcekey = :key AND userid = :userid AND status = :active',
                ['key' => $one['key'], 'userid' => $one['userid'], 'active' => api::ACTIVE]
            );
            $ids = array_merge($ids, array_map('intval', $found));
        }

        return array_values(array_unique($ids));
    }

    /**
     * Take out of their household the adults gone_adults() says leave, those still quiet now (one may have joined since
     * the plan was made), without a transaction of its own.
     *
     * @param int[] $adultids
     * @return int How many left.
     */
    private static function retire_quiet(array $adultids): int {
        global $DB;

        $left = 0;
        foreach ($adultids as $adultid) {
            $quiet = ['id' => $adultid, 'status' => api::ACTIVE, 'quiet' => 1];
            if ($DB->record_exists('local_oksigeniafamily_adult', $quiet)) {
                api::remove_adult($adultid, 'import');
                $left++;
            }
        }

        return $left;
    }

    /**
     * A round: Cifra is read, and what it says goes into Family by batches, unless the brake of a complete list stops
     * it (too many children would leave, or rows were not understood): then nothing changes until someone confirms how
     * many leave. The quiet adults no longer in their household in Cifra leave it (gone_adults()). Then the students
     * without an account get their name and group. Nothing is sent to anyone.
     *
     * @param int $now
     * @param cifra|null $client The connection; the site's when null.
     * @param int|null $confirm How many children leave, as someone confirmed it after the brake stopped a round.
     * @param int $pause Microseconds between two records.
     * @return array state (done, stopped, busy or error), counts, students, households, and error (its message).
     */
    public static function run(int $now, ?cifra $client = null, ?int $confirm = null, int $pause = self::PAUSE): array {
        global $DB;

        $result = ['state' => 'error', 'counts' => [], 'students' => 0, 'households' => 0, 'error' => ''];
        try {
            $dry = self::dry_run($now, $client, $pause);
        } catch (\moodle_exception $e) {
            $result['error'] = $e->getMessage();
            return self::keep($result, $now);
        }
        $plan = $dry['plan'];
        $result['students'] = $dry['students'];
        $result['households'] = $dry['households'];
        $result['counts'] = $plan['counts'];
        $leaving = count($plan['leaving']);
        if ($plan['guarded'] && $confirm !== $leaving) {
            $result['state'] = 'stopped';
            return self::keep($result, $now);
        }

        // Two imports never go in at the same time.
        $lock = job::lock();
        if (!$lock) {
            $result['state'] = 'busy';
            return self::keep($result, $now);
        }
        try {
            // The plan again, now that nobody else is importing.
            $groups = self::groups($dry['read']['records'], $now);
            $rows = self::rows($groups, $dry['read']['students']);
            $plan = importer::plan($rows['rows'], self::SOURCE, true, $rows['joined']);
            if ($plan['guarded'] && $confirm !== count($plan['leaving'])) {
                $result['state'] = 'stopped';
                $result['counts'] = $plan['counts'];
                return self::keep($result, $now);
            }
            \core_php_time_limit::raise();
            $gone = self::gone_adults($plan, $rows['rows']);
            $done = array_fill_keys(importer::DONE, 0) + ['adultsleaving' => 0, 'childrenleaving' => 0,
                'childrennamed' => 0];
            // Each batch goes in whole or not at all; a batch that went in stays, and the next round finds it as it is.
            foreach (array_chunk($plan['families'], self::BATCH) as $families) {
                $transaction = $DB->start_delegated_transaction();
                try {
                    foreach (importer::apply_families($families, self::SOURCE, true) as $name => $value) {
                        $done[$name] += $value;
                    }
                    $transaction->allow_commit();
                } catch (\Throwable $e) {
                    $transaction->rollback($e);
                }
            }
            foreach (array_chunk($gone['leaving'], self::BATCH) as $adultids) {
                $transaction = $DB->start_delegated_transaction();
                try {
                    $done['adultsleaving'] += self::retire_quiet($adultids);
                    $transaction->allow_commit();
                } catch (\Throwable $e) {
                    $transaction->rollback($e);
                }
            }
            foreach (array_chunk($plan['leaving'], self::BATCH) as $children) {
                $transaction = $DB->start_delegated_transaction();
                try {
                    $done['childrenleaving'] += importer::apply_leaving($children);
                    $transaction->allow_commit();
                } catch (\Throwable $e) {
                    $transaction->rollback($e);
                }
            }
            // What Cifra says of each adult, to help them sign in there (\local_oksigeniafamily\api::external_accounts()).
            $adults = [];
            foreach ($groups as $group) {
                $adults += $group['adults'];
            }
            $documents = self::documents($dry['read']['records']);
            foreach ($adults as $key => $adult) {
                $adults[$key]['document'] = $documents[$key] ?? '';
            }
            \local_oksigeniafamily\local\external::store(self::SOURCE, array_values($adults), $now);
            foreach ($dry['read']['students'] as $id => $student) {
                $done['childrennamed'] += api::set_child_details(
                    'cifra:' . $id,
                    trim((string) ($student['nombre'] ?? '')),
                    trim(($student['apellido1'] ?? '') . ' ' . ($student['apellido2'] ?? '')),
                    trim((string) ($student['grupo_academico_principal']['etiqueta'] ?? ''))
                );
            }
        } catch (\Throwable $e) {
            $result['error'] = \core_text::substr($e->getMessage(), 0, 500);
            self::keep($result, $now);
            throw $e;
        } finally {
            $lock->release();
        }
        // Those who joined and are no longer in their household in Cifra wait in «Families» for a person to look at, and
        // so do those linked to an account with another first name that has the e-mail Cifra has for them.
        families::set_review('gone', array_column($gone['review'], 'adultid'));
        families::set_review('account', self::linked_unmatched($rows['unmatched']));
        $result['state'] = 'done';
        $result['counts'] = $done + [
            'familiesjoined' => $plan['counts']['familiesjoined'],
            'errors' => count($plan['errors']),
        ];

        return self::keep($result, $now);
    }

    /**
     * The identity documents of the relatives in some records, by their key: Cifra gives a relative's (nif) with them.
     * Only those with their own id in Cifra, the key that stays.
     *
     * @param array[] $records The records of the students, as Cifra gives them.
     * @return string[] cifra:<id> => the document, in capitals and without separators.
     */
    public static function documents(array $records): array {
        $documents = [];
        foreach ($records as $record) {
            foreach ((array) ($record['familiares'] ?? []) as $relative) {
                $person = (array) ($relative['usuario'] ?? $relative);
                $id = (string) ($person['id'] ?? '');
                $document = substr(preg_replace('/[^A-Z0-9]/', '', strtoupper((string) ($person['nif'] ?? ''))), 0, 30);
                if ($id !== '' && $document !== '') {
                    $documents['cifra:' . $id] = $document;
                }
            }
        }

        return $documents;
    }

    /**
     * How the last round went, as the settings page says it.
     *
     * @return array|null time, state, counts, students, households and error; null before the first one.
     */
    public static function status(): ?array {
        $status = json_decode((string) get_config('local_oksigeniafamily', self::STATUS), true);

        return is_array($status) ? $status : null;
    }

    /**
     * How the last round went, in words, for the settings page.
     *
     * @return string
     */
    public static function status_text(): string {
        $component = 'local_oksigeniafamily';
        $status = self::status();
        if (!$status) {
            return get_string('cifrahouseholds:never', $component);
        }
        $counts = (array) ($status['counts'] ?? []);
        $a = ['time' => userdate((int) ($status['time'] ?? 0), get_string('strftimedatetimeshort', 'langconfig'))];
        $names = ['familiesnew', 'familiesjoined', 'adultsnew', 'adultsleaving', 'childrennew', 'childrenleaving', 'errors'];
        foreach ($names as $name) {
            $a[$name] = (int) ($counts[$name] ?? 0);
        }
        $a['students'] = (int) ($status['students'] ?? 0);
        $a['households'] = (int) ($status['households'] ?? 0);
        $a['error'] = s((string) ($status['error'] ?? ''));
        $state = in_array($status['state'] ?? '', ['done', 'stopped', 'busy'], true) ? $status['state'] : 'error';

        return get_string('cifrahouseholds:' . $state, $component, (object) $a);
    }

    /**
     * Keep how a round went.
     *
     * @param array $result As run() gives it.
     * @param int $now
     * @return array The same.
     */
    private static function keep(array $result, int $now): array {
        set_config(self::STATUS, json_encode(['time' => $now] + $result), 'local_oksigeniafamily');

        return $result;
    }

    /**
     * What Family already has: the open families, and their children and adults, to find a household again.
     *
     * @return array families (by id: id, source, sourcekey), bykey (this source's household key => family id), children
     *     (child key or user:<id> => family ids), childusers (accounts that are children, as keys) and adults (family id
     *     => adults: sourcekey, email, phone).
     */
    private static function known(): array {
        global $DB;

        $active = ['factive' => api::ACTIVE, 'active' => api::ACTIVE];
        $families = $DB->get_records('local_oksigeniafamily_family', ['status' => api::ACTIVE], 'id', 'id, source, sourcekey');
        $bykey = [];
        foreach ($families as $family) {
            if ($family->source === self::SOURCE && (string) $family->sourcekey !== '') {
                $bykey[(string) $family->sourcekey] = $bykey[(string) $family->sourcekey] ?? (int) $family->id;
            }
        }
        $children = [];
        $childusers = [];
        $rows = $DB->get_recordset_sql(
            "SELECT c.id, c.familyid, c.userid, c.sourcekey
               FROM {local_oksigeniafamily_child} c
               JOIN {local_oksigeniafamily_family} f ON f.id = c.familyid
              WHERE c.status = :active AND f.status = :factive",
            $active
        );
        foreach ($rows as $child) {
            if (!empty($child->userid)) {
                $children['user:' . $child->userid][] = (int) $child->familyid;
                $childusers[(int) $child->userid] = true;
            }
            if ((string) $child->sourcekey !== '') {
                $children[(string) $child->sourcekey][] = (int) $child->familyid;
            }
        }
        $rows->close();
        $adults = [];
        $rows = $DB->get_recordset_sql(
            "SELECT a.id, a.familyid, a.sourcekey, a.email, a.phone
               FROM {local_oksigeniafamily_adult} a
               JOIN {local_oksigeniafamily_family} f ON f.id = a.familyid
              WHERE a.status = :active AND f.status = :factive",
            $active
        );
        foreach ($rows as $adult) {
            $adults[(int) $adult->familyid][] = $adult;
        }
        $rows->close();

        return ['families' => $families, 'bykey' => $bykey, 'children' => $children, 'childusers' => $childusers,
            'adults' => $adults];
    }

    /**
     * The open families that already have children of each household, the most children first: [group index, family id].
     *
     * @param array[] $groups From groups().
     * @param \stdClass[] $childaccount Child key => their account.
     * @param array $known From known().
     * @return array[]
     */
    private static function by_children(array $groups, array $childaccount, array $known): array {
        $pairs = [];
        foreach ($groups as $index => $group) {
            $overlap = [];
            foreach ($group['children'] as $child) {
                $families = $known['children'][$child] ?? [];
                if (isset($childaccount[$child])) {
                    $families = array_merge($families, $known['children']['user:' . $childaccount[$child]->id] ?? []);
                }
                foreach (array_unique($families) as $familyid) {
                    $overlap[$familyid] = ($overlap[$familyid] ?? 0) + 1;
                }
            }
            foreach ($overlap as $familyid => $count) {
                $family = $known['families'][$familyid] ?? null;
                if (!$family) {
                    continue;
                }
                // The household this source made for the same guardians goes first among equals.
                $own = $family->source === self::SOURCE && $family->sourcekey === self::PREFIX . $group['signature'];
                $pairs[] = [$index, (int) $familyid, $count, $own ? 1 : 0];
            }
        }
        usort($pairs, function (array $one, array $other): int {
            return [$other[2], $other[3], $one[1], $one[0]] <=> [$one[2], $one[3], $other[1], $other[0]];
        });

        return array_map(fn($pair) => [$pair[0], $pair[1]], $pairs);
    }

    /**
     * A household this source made that a new child's family is: all its adults are among the child's, and it has all
     * the child's guardians, known by their id in Cifra, then their e-mail, then their mobile.
     *
     * @param array $group From groups().
     * @param array $known From known().
     * @param string $prefix The site's country code for phones.
     * @return int The family, or 0.
     */
    private static function by_adults(array $group, array $known, string $prefix): int {
        $best = 0;
        $most = 0;
        foreach ($known['bykey'] as $familyid) {
            $adults = $known['adults'][$familyid] ?? [];
            if (!$adults) {
                continue;
            }
            $matched = [];
            $allin = true;
            foreach ($adults as $adult) {
                $key = self::match($adult, $group['adults'], $prefix);
                if ($key === '') {
                    $allin = false;
                    break;
                }
                $matched[$key] = true;
            }
            $guardians = !array_diff($group['guardians'], array_keys($matched));
            if ($allin && $guardians && count($adults) > $most) {
                $best = (int) $familyid;
                $most = count($adults);
            }
        }

        return $best;
    }

    /**
     * Which adult of a household an adult of Family is: by their key, then their e-mail, then their mobile.
     *
     * @param \stdClass $adult The adult of Family.
     * @param array[] $adults The household's, by key.
     * @param string $prefix The site's country code for phones.
     * @return string Their key in the household, or ''.
     */
    private static function match(\stdClass $adult, array $adults, string $prefix): string {
        if ((string) $adult->sourcekey !== '' && isset($adults[(string) $adult->sourcekey])) {
            return (string) $adult->sourcekey;
        }
        $email = self::email((string) $adult->email);
        foreach ($adults as $key => $candidate) {
            if ($email !== '' && self::email((string) ($candidate['email'] ?? '')) === $email) {
                return (string) $key;
            }
        }
        foreach ($adults as $key => $candidate) {
            $phone = phone::normalise((string) ($candidate['phone'] ?? ''), $prefix);
            if ($phone !== '' && $phone === (string) $adult->phone) {
                return (string) $key;
            }
        }

        return '';
    }

    /**
     * The accounts of some e-mails, when only one active account has each.
     *
     * @param string[] $emails In lower case.
     * @return \stdClass[] E-mail => account (id, username).
     */
    private static function accounts(array $emails): array {
        global $CFG, $DB;

        $found = [];
        $many = [];
        foreach (array_chunk(array_values(array_unique($emails)), 500) as $chunk) {
            [$in, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'mail');
            $users = $DB->get_records_select(
                'user',
                "deleted = 0 AND LOWER(email) $in",
                $params,
                'id',
                'id, username, email, suspended, mnethostid, firstname, lastname'
            );
            foreach ($users as $user) {
                $email = self::email((string) $user->email);
                if (isset($found[$email])) {
                    $many[$email] = true;
                }
                $found[$email] = $user;
            }
        }
        $accounts = [];
        foreach ($found as $email => $user) {
            $local = (int) $user->mnethostid === (int) $CFG->mnet_localhost_id;
            if (!isset($many[$email]) && $local && empty($user->suspended)) {
                $accounts[$email] = $user;
            }
        }

        return $accounts;
    }

    /**
     * An e-mail as compared: in lower case, and empty when it is not one.
     *
     * @param string $email
     * @return string
     */
    private static function email(string $email): string {
        $email = \core_text::strtolower(trim($email));

        return $email !== '' && validate_email($email) ? $email : '';
    }

    /**
     * A text cut to fit its field.
     *
     * @param string $text
     * @param int $length
     * @return string
     */
    private static function fit(string $text, int $length): string {
        return \core_text::substr(trim($text), 0, $length);
    }

    /**
     * A student's name, as Cifra has it.
     *
     * @param array $student
     * @return string
     */
    private static function student_name(array $student): string {
        $name = trim(($student['nombre'] ?? '') . ' ' . ($student['apellido1'] ?? '') . ' ' . ($student['apellido2'] ?? ''));

        return preg_replace('/\s+/', ' ', $name);
    }
}
