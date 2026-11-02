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
use local_oksigeniafamily\local\phone;

/**
 * Turns rows of people into family records, in two steps: plan() says what would change without touching anything, and
 * apply() does it. Repeating the same rows changes nothing: a family is known by its key in its source, an adult within
 * their family by their key, their account or their name, and a child by their account or their key.
 *
 * The rows do not need to come from a file. A CSV gives them through csv_file; the school's management program (OneRoster,
 * Cifra) gives them from the readers in local\readers, with its own source and keys, and the rest stays the same.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class importer {
    /** How long each field of an adult can be, as the table has it. */
    private const LENGTHS = ['firstname' => 100, 'lastname' => 100, 'email' => 100, 'family' => 255, 'key' => 255];

    /**
     * What some rows would change.
     *
     * @param array $rows Each with line and values (family, type, firstname, lastname, email, phone, username, idnumber
     *     and key), and optionally toomany; see csv_file.
     * @param string $source Where they come from: csv, cifra, oneroster.
     * @param bool $complete Whether they are the whole list of that source, so children without an account who are not in
     *     them leave. Those with an account leave on their own with their account.
     * @return array errors (each with line and message), families (those with something to say, see below), leaving (the
     *     children who would leave) and counts (familiesnew, adultsnew, adultschanged, childrennew, childrenleaving,
     *     errors and same, the people already there as they are).
     */
    public static function plan(array $rows, string $source = 'csv', bool $complete = false): array {
        $errors = [];
        $families = [];
        $keys = [];
        foreach ($rows as $row) {
            $values = array_map(fn($value) => clean_param((string) $value, PARAM_NOTAGS), $row['values']);
            $values['key'] = self::key($values['key'] ?? '', $source);
            if ($values['key'] !== '') {
                $keys[$values['key']] = true;
            }
            $error = self::row_error($row, $values);
            if ($error === null) {
                $family = $values['family'];
                $families[$family] = $families[$family] ?? self::open_family($family, $source, $row['line']);
                $error = $values['type'] === 'adult'
                    ? self::plan_adult($families[$family], $values, $row['line'])
                    : self::plan_child($families[$family], $values, $row['line']);
            }
            if ($error !== null) {
                $errors[] = ['line' => $row['line'], 'message' => $error];
            }
        }

        foreach ($families as $key => $family) {
            $missing = !$family['adults'] ? 'noadults' : (!$family['children'] ? 'nochildren' : '');
            if ($missing !== '') {
                $errors[] = [
                    'line' => $family['line'],
                    'message' => get_string('import:error:' . $missing, 'local_oksigeniafamily', $key),
                ];
                unset($families[$key]);
            }
        }
        usort($errors, fn($one, $other) => $one['line'] <=> $other['line']);

        $leaving = $complete ? self::leaving($source, $keys) : [];

        return [
            'errors' => $errors,
            'families' => array_values($families),
            'leaving' => $leaving,
            'counts' => self::counts($families, $leaving, count($errors)),
        ];
    }

    /**
     * Do what a plan says, all of it or nothing.
     *
     * @param array $plan From plan().
     * @param string $source The same source as the plan.
     * @return void
     */
    public static function apply(array $plan, string $source = 'csv'): void {
        global $DB;

        \core_php_time_limit::raise();
        $transaction = $DB->start_delegated_transaction();
        foreach ($plan['families'] as $family) {
            $familyid = $family['id'] ?: api::create_family($source, $family['key']);
            foreach ($family['adults'] as $adult) {
                if (empty($adult['id'])) {
                    api::add_adult($familyid, array_filter($adult['data']));
                    continue;
                }
                $changes = array_intersect_key($adult['data'], array_flip($adult['changes']));
                if (isset($changes['userid'])) {
                    api::set_adult_user((int) $adult['id'], (int) $changes['userid']);
                    unset($changes['userid']);
                }
                if ($changes) {
                    api::update_adult((int) $adult['id'], $changes);
                }
            }
            foreach ($family['children'] as $child) {
                if (empty($child['id']) || !empty($child['changes'])) {
                    api::add_child($familyid, $child['userid'] ?: null, $child['sourcekey'] ?: null);
                }
            }
        }
        foreach ($plan['leaving'] as $child) {
            api::unlink_child((int) $child->id, 'import');
        }
        $transaction->allow_commit();
    }

    /**
     * The key of a person in their source: as given when it says which source (`cifra:123`), or with the source in front.
     *
     * @param string $key The key, as given.
     * @param string $source The source.
     * @return string
     */
    private static function key(string $key, string $source): string {
        $key = trim($key);
        if ($key === '' || strpos($key, ':') !== false) {
            return $key;
        }

        return $source . ':' . $key;
    }

    /**
     * What is wrong with a row whatever is in the records.
     *
     * @param array $row The row.
     * @param array $values Its values, clean.
     * @return string|null The message, or null.
     */
    private static function row_error(array $row, array $values): ?string {
        if (!empty($row['toomany'])) {
            return get_string('import:error:toomany', 'local_oksigeniafamily');
        }
        if (($values['family'] ?? '') === '') {
            return get_string('import:error:family', 'local_oksigeniafamily');
        }
        if (!in_array($values['type'] ?? '', ['adult', 'child'], true)) {
            return get_string('import:error:type', 'local_oksigeniafamily', $values['type'] ?? '');
        }
        foreach (self::LENGTHS as $field => $length) {
            if (\core_text::strlen($values[$field] ?? '') > $length) {
                return get_string('import:error:toolong', 'local_oksigeniafamily', (object) [
                    'value' => \core_text::substr($values[$field], 0, 30), 'max' => $length,
                ]);
            }
        }

        return null;
    }

    /**
     * A family as the plan keeps it: found by its key in its source, or to be made.
     *
     * @param string $key The family's key.
     * @param string $source The source.
     * @param int $line The first row of the family.
     * @return array
     */
    private static function open_family(string $key, string $source, int $line): array {
        global $DB;

        $active = ['status' => api::ACTIVE];
        $found = $DB->get_record(
            'local_oksigeniafamily_family',
            ['source' => $source, 'sourcekey' => $key] + $active,
            'id',
            IGNORE_MULTIPLE
        );
        $family = ['key' => $key, 'line' => $line, 'id' => $found ? (int) $found->id : 0, 'adults' => [], 'children' => []];
        if ($found) {
            $conditions = ['familyid' => $found->id] + $active;
            foreach ($DB->get_records('local_oksigeniafamily_adult', $conditions) as $adult) {
                $family['adults'][] = ['id' => (int) $adult->id, 'data' => (array) $adult, 'changes' => [], 'seen' => false];
            }
            foreach ($DB->get_records('local_oksigeniafamily_child', $conditions) as $child) {
                $family['children'][] = [
                    'id' => (int) $child->id, 'userid' => (int) $child->userid, 'sourcekey' => (string) $child->sourcekey,
                    'changes' => [], 'seen' => false,
                ];
            }
        }

        return $family;
    }

    /**
     * Add an adult's row to the plan of their family.
     *
     * @param array $family The family in the plan.
     * @param array $values The row's values.
     * @param int $line The row's line.
     * @return string|null What is wrong with the row, or null.
     */
    private static function plan_adult(array &$family, array $values, int $line): ?string {
        $data = [];
        foreach (['firstname', 'lastname', 'email'] as $field) {
            $data[$field] = $values[$field] ?? '';
        }
        $data['sourcekey'] = $values['key'];
        $data['email'] = \core_text::strtolower($data['email']);
        if ($data['email'] !== '' && !validate_email($data['email'])) {
            return get_string('import:error:email', 'local_oksigeniafamily', $data['email']);
        }
        $data['phone'] = '';
        if (($values['phone'] ?? '') !== '') {
            $data['phone'] = phone::normalise($values['phone'], self::phone_prefix());
            if ($data['phone'] === '') {
                return get_string('import:error:phone', 'local_oksigeniafamily', $values['phone']);
            }
        }
        $data['userid'] = 0;
        if (($values['username'] ?? '') !== '' || ($values['idnumber'] ?? '') !== '') {
            $account = self::account($values);
            if (is_string($account)) {
                return $account;
            }
            $data['userid'] = (int) $account->id;
            $data['firstname'] = $data['firstname'] !== '' ? $data['firstname'] : $account->firstname;
            $data['lastname'] = $data['lastname'] !== '' ? $data['lastname'] : $account->lastname;
        }
        $any = $data['firstname'] . $data['lastname'] . $data['email'] . $data['phone'] . $data['sourcekey'];
        if ($any === '' && !$data['userid']) {
            return get_string('import:error:adultempty', 'local_oksigeniafamily');
        }

        foreach ($family['adults'] as $index => $known) {
            if (self::same_adult($known['data'], $data)) {
                foreach ($data as $field => $value) {
                    // An empty cell says nothing: it does not wipe what an adult gave when claiming their code.
                    if (!empty($value) && !self::same_value($field, $value, $known['data'][$field] ?? '')) {
                        $family['adults'][$index]['data'][$field] = $value;
                        $family['adults'][$index]['changes'][] = $field;
                    }
                }
                $family['adults'][$index]['seen'] = true;
                return null;
            }
        }
        $family['adults'][] = ['id' => 0, 'data' => $data, 'changes' => [], 'seen' => true, 'line' => $line];

        return null;
    }

    /**
     * Add a child's row to the plan of their family.
     *
     * @param array $family The family in the plan.
     * @param array $values The row's values.
     * @param int $line The row's line.
     * @return string|null What is wrong with the row, or null.
     */
    private static function plan_child(array &$family, array $values, int $line): ?string {
        $userid = 0;
        if (($values['username'] ?? '') !== '' || ($values['idnumber'] ?? '') !== '') {
            $account = self::account($values);
            if (is_string($account)) {
                return $account;
            }
            $userid = (int) $account->id;
        }
        $key = $values['key'];
        if (!$userid && $key === '') {
            return get_string('import:error:childempty', 'local_oksigeniafamily');
        }

        foreach ($family['children'] as $index => $known) {
            $sameaccount = $userid && $known['userid'] === $userid;
            $samekey = $key !== '' && $known['sourcekey'] === $key;
            if ($samekey && $userid && $known['userid'] && !$sameaccount) {
                return get_string('import:error:keytaken', 'local_oksigeniafamily', $key);
            }
            if ($sameaccount || $samekey) {
                if ($userid && !$known['userid']) {
                    $family['children'][$index]['userid'] = $userid;
                    $family['children'][$index]['changes'][] = 'userid';
                }
                if ($key !== '' && $known['sourcekey'] === '') {
                    $family['children'][$index]['sourcekey'] = $key;
                    $family['children'][$index]['changes'][] = 'sourcekey';
                }
                $family['children'][$index]['seen'] = true;
                return null;
            }
        }
        $family['children'][] = [
            'id' => 0, 'userid' => $userid, 'sourcekey' => $key, 'changes' => [], 'seen' => true, 'line' => $line,
        ];

        return null;
    }

    /**
     * Whether two adults of a family are the same person. A key or an account decides when both have one. Otherwise, the
     * name, because a couple can share a mobile or a mailbox; and if one has no name, the phone or the e-mail.
     *
     * @param array $known The adult in the family.
     * @param array $new The adult in the row.
     * @return bool
     */
    private static function same_adult(array $known, array $new): bool {
        if (!empty($known['sourcekey']) && !empty($new['sourcekey'])) {
            return $known['sourcekey'] === $new['sourcekey'];
        }
        if (!empty($known['userid']) && !empty($new['userid'])) {
            return (int) $known['userid'] === (int) $new['userid'];
        }
        $knownname = self::plain_name($known);
        $newname = self::plain_name($new);
        if ($knownname !== '' && $newname !== '') {
            return $knownname === $newname;
        }
        foreach (['phone', 'email'] as $field) {
            if (!empty($known[$field]) && (string) $known[$field] === (string) ($new[$field] ?? '')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether two values of a field say the same. Names in capitals or without accents are not a change, because the same
     * person comes spelled differently from different programs.
     *
     * @param string $field The field.
     * @param mixed $one One value.
     * @param mixed $other The other.
     * @return bool
     */
    private static function same_value(string $field, $one, $other): bool {
        if (in_array($field, ['firstname', 'lastname'], true)) {
            return self::plain_name(['firstname' => (string) $one]) === self::plain_name(['firstname' => (string) $other]);
        }

        return (string) $one === (string) $other;
    }

    /**
     * A name to compare: without accents, case or extra spaces.
     *
     * @param array $adult The adult.
     * @return string
     */
    private static function plain_name(array $adult): string {
        $name = trim(($adult['firstname'] ?? '') . ' ' . ($adult['lastname'] ?? ''));

        return preg_replace('/\s+/', ' ', \core_text::strtolower(\core_text::specialtoascii($name)));
    }

    /**
     * The Moodle account a row names, by its username or its ID number.
     *
     * @param array $values The row's values.
     * @return \stdClass|string The account, or what is wrong.
     */
    private static function account(array $values) {
        global $CFG, $DB;

        $fields = 'id, username, firstname, lastname, suspended';
        if (($values['username'] ?? '') !== '') {
            $name = \core_text::strtolower($values['username']);
            $account = $DB->get_record(
                'user',
                ['username' => $name, 'mnethostid' => $CFG->mnet_localhost_id, 'deleted' => 0],
                $fields
            );
            if (!$account) {
                return get_string('import:error:username', 'local_oksigeniafamily', $values['username']);
            }
        } else {
            $accounts = $DB->get_records('user', ['idnumber' => $values['idnumber'], 'deleted' => 0], '', $fields, 0, 2);
            if (!$accounts) {
                return get_string('import:error:idnumber', 'local_oksigeniafamily', $values['idnumber']);
            }
            if (count($accounts) > 1) {
                return get_string('import:error:idnumbermany', 'local_oksigeniafamily', $values['idnumber']);
            }
            $account = reset($accounts);
        }
        if ($account->suspended) {
            return get_string('import:error:suspended', 'local_oksigeniafamily', $account->username);
        }

        return $account;
    }

    /**
     * The children without an account of a source who are not in the rows, for a complete list.
     *
     * @param string $source The source.
     * @param array $keys The keys in the rows (as array keys).
     * @return \stdClass[] Child records.
     */
    private static function leaving(string $source, array $keys): array {
        global $DB;

        $sql = "SELECT c.id, c.familyid, c.sourcekey, f.sourcekey AS familykey
                  FROM {local_oksigeniafamily_child} c
                  JOIN {local_oksigeniafamily_family} f ON f.id = c.familyid
                 WHERE c.status = :cactive AND c.userid IS NULL AND f.status = :factive AND f.source = :source";
        $children = $DB->get_records_sql($sql, ['cactive' => api::ACTIVE, 'factive' => api::ACTIVE, 'source' => $source]);

        return array_values(array_filter($children, fn($child) => !isset($keys[$child->sourcekey])));
    }

    /**
     * How many of each.
     *
     * @param array $families The families of the plan.
     * @param array $leaving The children who leave.
     * @param int $errors How many errors.
     * @return int[]
     */
    private static function counts(array $families, array $leaving, int $errors): array {
        $counts = ['familiesnew' => 0, 'adultsnew' => 0, 'adultschanged' => 0, 'childrennew' => 0, 'childrenchanged' => 0,
            'childrenleaving' => count($leaving), 'errors' => $errors, 'same' => 0];
        foreach ($families as $family) {
            $counts['familiesnew'] += $family['id'] ? 0 : 1;
            foreach ($family['adults'] as $adult) {
                if (empty($adult['id'])) {
                    $counts['adultsnew']++;
                } else if ($adult['changes']) {
                    $counts['adultschanged']++;
                } else if ($adult['seen']) {
                    $counts['same']++;
                }
            }
            foreach ($family['children'] as $child) {
                if (empty($child['id'])) {
                    $counts['childrennew']++;
                } else if ($child['changes']) {
                    $counts['childrenchanged']++;
                } else if ($child['seen']) {
                    $counts['same']++;
                }
            }
        }

        return $counts;
    }

    /**
     * The site's country code for phones typed without one.
     *
     * @return string
     */
    public static function phone_prefix(): string {
        return (string) (get_config('local_oksigeniafamily', 'phoneprefix') ?: '+34');
    }
}
