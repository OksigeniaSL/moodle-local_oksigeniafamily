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

/**
 * What a school management program says of an adult it brings, to help them sign in there.
 *
 * A family that cannot get into the school's management program asks for help: the school can tell them, partly
 * hidden, their username there and the e-mail where the program sends a new password. The program gives the adult's
 * identity document and that e-mail, not the username nor whether it is active; the username follows from a rule the
 * centre sets ({nif}47 at a Cifra school), so it is said as derived. Kept while the program keeps bringing the adult,
 * and for 30 days after.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class external {
    /** The table. */
    public const TABLE = 'local_oksigeniafamily_extern';

    /** How long a record stays after the program stops bringing the adult. */
    public const KEEP = 30 * DAYSECS;

    /** The programs this knows, with the name each shows and the setting of its username rule. */
    private const SYSTEMS = ['cifra' => ['label' => 'Cifra', 'rule' => 'cifrausername']];

    /**
     * Keep what a program has just said of its adults, and forget those it stopped bringing a while ago.
     *
     * @param string $system The program (cifra).
     * @param array[] $adults Each with key (cifra:123), and document and email when it gives them.
     * @param int $now
     * @return int How many records changed.
     */
    public static function store(string $system, array $adults, int $now): int {
        global $DB;

        $known = $DB->get_records(self::TABLE, ['system' => $system], '', 'sourcekey, id, document, email');
        $changed = 0;
        foreach ($adults as $adult) {
            $key = (string) ($adult['key'] ?? '');
            if ($key === '' || strlen($key) > 255) {
                continue;
            }
            $document = substr(preg_replace('/[^A-Z0-9]/', '', strtoupper((string) ($adult['document'] ?? ''))), 0, 30);
            $email = \core_text::strtolower(trim((string) ($adult['email'] ?? '')));
            $email = strlen($email) <= 100 ? $email : '';
            $row = $known[$key] ?? null;
            if (!$row) {
                $record = ['system' => $system, 'sourcekey' => $key, 'document' => $document, 'email' => $email,
                    'timeseen' => $now, 'timemodified' => $now];
                $DB->insert_record(self::TABLE, $record);
                $known[$key] = (object) ['sourcekey' => $key, 'document' => $document, 'email' => $email];
                $changed++;
                continue;
            }
            $update = ['id' => $row->id, 'timeseen' => $now];
            if ((string) $row->document !== $document || (string) $row->email !== $email) {
                $update += ['document' => $document, 'email' => $email, 'timemodified' => $now];
                $changed++;
            }
            $DB->update_record(self::TABLE, (object) $update);
        }
        $old = ['system' => $system, 'old' => $now - self::KEEP];
        $DB->delete_records_select(self::TABLE, 'system = :system AND timeseen < :old', $old);

        return $changed;
    }

    /**
     * The accounts an adult of Family has in the programs that bring them.
     *
     * @param int $adultid The adult.
     * @return array[] Each with system, label, username (by the centre's rule, or ''), derived (whether the username
     *     follows from that rule rather than from the program), email (where the program sends a new password, or ''),
     *     active (null: the program does not say) and checked (when the program last brought them). Empty when the adult
     *     has left, or no program brings them.
     */
    public static function accounts(int $adultid): array {
        global $DB;

        $adult = $DB->get_record('local_oksigeniafamily_adult', ['id' => $adultid, 'status' => 'active'], 'id, sourcekey');
        if (!$adult || (string) $adult->sourcekey === '') {
            return [];
        }
        $accounts = [];
        foreach ($DB->get_records(self::TABLE, ['sourcekey' => $adult->sourcekey], 'system') as $row) {
            $system = self::SYSTEMS[$row->system] ?? null;
            if (!$system) {
                continue;
            }
            $username = self::username((string) get_config('local_oksigeniafamily', $system['rule']), (string) $row->document);
            $accounts[] = [
                'system' => (string) $row->system,
                'label' => $system['label'],
                'username' => $username,
                'derived' => $username !== '',
                'email' => (string) $row->email,
                'active' => null,
                'checked' => (int) $row->timeseen,
            ];
        }

        return $accounts;
    }

    /**
     * A username by the centre's rule: {nif} is the identity document.
     *
     * @param string $rule Such as {nif}47; empty when the centre has not said.
     * @param string $document The document, in capitals.
     * @return string Empty without a rule or a document.
     */
    public static function username(string $rule, string $document): string {
        $rule = trim($rule);
        if ($rule === '' || $document === '' || !str_contains($rule, '{nif}')) {
            return '';
        }

        return str_replace('{nif}', $document, $rule);
    }

    /**
     * Forget what the programs say of some adults (privacy).
     *
     * @param string[] $sourcekeys Their keys in the programs.
     * @return void
     */
    public static function forget(array $sourcekeys): void {
        global $DB;

        $sourcekeys = array_values(array_filter($sourcekeys, fn($key) => (string) $key !== ''));
        if (!$sourcekeys) {
            return;
        }
        [$in, $params] = $DB->get_in_or_equal($sourcekeys, SQL_PARAMS_NAMED);
        $DB->delete_records_select(self::TABLE, "sourcekey $in", $params);
    }
}
