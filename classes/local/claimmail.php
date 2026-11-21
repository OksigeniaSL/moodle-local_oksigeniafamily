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
 * A code claimed with the address of an account of the site, such as a teacher who is also a parent: the child goes into
 * that account once the person shows it is theirs, through a link sent to that address. They see their children where
 * they already sign in, with one account, and the site never gets two accounts with one address.
 *
 * The link carries the code, the account and the phone typed, sealed with the site's key: nothing waits in the
 * database. It works for two days, and once: opening it only asks its owner to confirm (a mail scanner opens links
 * too), and once confirmed, the hash of what it says is kept until it would have expired anyway (spend()).
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class claimmail {
    /** How many days the link works. */
    public const DAYS = 2;

    /** Where the links already used are kept. */
    private const TABLE = 'local_oksigeniafamily_spent';

    /**
     * The account of the site with this address, when there is exactly one that signs in as a person does: not one that
     * cannot sign in, and not a family's own account in the app, which signs in by web services only.
     *
     * @param string $email The address typed.
     * @return \stdClass|null
     */
    public static function account(string $email): ?\stdClass {
        global $CFG, $DB;

        if (!validate_email($email)) {
            return null;
        }
        $users = $DB->get_records_select(
            'user',
            'LOWER(email) = LOWER(:email) AND deleted = 0 AND suspended = 0 AND confirmed = 1 AND mnethostid = :host',
            ['email' => trim($email), 'host' => $CFG->mnet_localhost_id]
        );
        $users = array_filter(
            $users,
            fn(\stdClass $user): bool => !in_array($user->auth, ['nologin', 'webservice'], true) && !isguestuser($user)
        );

        return count($users) === 1 ? reset($users) : null;
    }

    /**
     * Send the link that puts the child into the account.
     *
     * @param string $code The code, as typed.
     * @param \stdClass $user The account.
     * @param int $childuserid The student the code is for.
     * @param string $phone The phone typed, in E.164 form ('' if none).
     * @return bool Whether the e-mail went.
     */
    public static function send(string $code, \stdClass $user, int $childuserid, string $phone = ''): bool {
        $sealed = self::seal([
            'c' => strtoupper(preg_replace('/[\s-]+/', '', $code)),
            'u' => (int) $user->id,
            'p' => $phone,
            'x' => time() + self::DAYS * DAYSECS,
        ]);
        $url = (new \moodle_url('/local/oksigeniafamily/claim.php', ['confirm' => $sealed]))->out(false);
        $child = \core_user::get_user($childuserid);
        $a = (object) [
            'name' => format_string($user->firstname),
            'child' => $child ? format_string($child->firstname) : '',
            'site' => format_string(get_site()->fullname),
            'url' => $url,
            'days' => self::DAYS,
        ];
        $component = 'local_oksigeniafamily';
        $lang = force_current_language($user->lang ?? '');
        try {
            $subject = get_string('claimmail:subject', $component, $a);
            $text = get_string('claimmail:text', $component, $a);
            $html = get_string('claimmail:html', $component, (object) ((array) $a + ['url' => s($url)]));
        } finally {
            force_current_language($lang);
        }

        return (bool) email_to_user($user, \core_user::get_noreply_user(), $subject, $text, $html);
    }

    /**
     * What a link from send() says, while it is good.
     *
     * @param string $sealed The link's «confirm».
     * @return array|null [code, userid, phone]; null if it is not one of this site's, has expired or has been used.
     */
    public static function read(string $sealed): ?array {
        global $DB;

        $plain = self::open($sealed);
        $data = $plain === null ? null : json_decode($plain, true);
        if (!is_array($data) || empty($data['c']) || empty($data['u']) || (int) ($data['x'] ?? 0) < time()) {
            return null;
        }
        if ($DB->record_exists(self::TABLE, ['stamp' => self::stamp($plain)])) {
            return null;
        }

        return ['code' => (string) $data['c'], 'userid' => (int) $data['u'], 'phone' => (string) ($data['p'] ?? '')];
    }

    /**
     * Use a link, once: from then on read() gives nothing for it. Call it just before what the link does.
     *
     * @param string $sealed The link's «confirm».
     * @return bool Whether it was good and unused; false if it is not one of this site's, has expired or was used.
     */
    public static function spend(string $sealed): bool {
        global $DB;

        if (self::read($sealed) === null) {
            return false;
        }
        $now = time();
        // A link used longer ago than it could last would no longer be good anyway.
        $DB->delete_records_select(self::TABLE, 'timecreated < :old', ['old' => $now - self::DAYS * DAYSECS]);
        $stamp = self::stamp((string) self::open($sealed));
        try {
            // The index on the stamp is unique: of two confirmations at the same time, only one goes in.
            $DB->insert_record(self::TABLE, (object) ['stamp' => $stamp, 'timecreated' => $now]);
        } catch (\dml_write_exception $e) {
            return false;
        }

        return true;
    }

    /**
     * Give a link back when what it was used for could not be done (someone else was claiming the same code at that
     * moment): it can be tried again while it is good.
     *
     * @param string $sealed The link's «confirm».
     * @return void
     */
    public static function unspend(string $sealed): void {
        global $DB;

        $plain = self::open($sealed);
        if ($plain !== null) {
            $DB->delete_records(self::TABLE, ['stamp' => self::stamp($plain)]);
        }
    }

    /**
     * What a link says, in the clear, when it is one of this site's.
     *
     * @param string $sealed The link's «confirm».
     * @return string|null The sealed data, or null.
     */
    private static function open(string $sealed): ?string {
        try {
            $plain = \core\encryption::decrypt((string) base64_decode(strtr($sealed, '-_', '+/')));
        } catch (\Throwable $e) {
            return null;
        }

        return is_string($plain) && $plain !== '' ? $plain : null;
    }

    /**
     * What is kept of a used link: the hash of what it says, not of the link. The same data can be written in more than
     * one way in base64, and each would otherwise be a link of its own.
     *
     * @param string $plain What the link says, in the clear.
     * @return string
     */
    private static function stamp(string $plain): string {
        return hash('sha256', $plain);
    }

    /**
     * Data sealed with the site's key, for a link.
     *
     * @param array $data The data.
     * @return string Base64url, without padding.
     */
    private static function seal(array $data): string {
        return rtrim(strtr(base64_encode(\core\encryption::encrypt(json_encode($data))), '+/', '-_'), '=');
    }
}
