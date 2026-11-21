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
 * The brakes of the claim page: on guessing codes, a few failed tries from one address and then a pause; and on using
 * one good code over and over, or sending its links to an address, so that a code in someone else's hands neither fills
 * a mailbox nor tries address after address.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class throttle {
    /** How many wrong codes an address may try in a window. */
    public const LIMIT = 10;

    /**
     * The other brakes: what is counted => [how many, in how many seconds]. Each window starts with the first one counted.
     *
     * - code: claims of one good code. Each adult of a family uses it once or twice; many more is someone else.
     * - mailto: links sent to the address of one account. A few, never a mailbox filled by someone else.
     * - mailfrom: links sent from one visitor's address. A school's network on an enrolment day sends some, not dozens.
     */
    public const LIMITS = [
        'code' => [10, DAYSECS],
        'mailto' => [3, DAYSECS],
        'mailfrom' => [10, HOURSECS],
    ];

    /**
     * Whether an address has tried too many wrong codes lately.
     *
     * @param string $address The visitor's address.
     * @return bool
     */
    public static function blocked(string $address): bool {
        return (int) self::cache()->get(self::key($address)) >= self::LIMIT;
    }

    /**
     * Count a wrong code from an address.
     *
     * @param string $address The visitor's address.
     * @return void
     */
    public static function failed(string $address): void {
        $cache = self::cache();
        $key = self::key($address);
        $cache->set($key, (int) $cache->get($key) + 1);
    }

    /**
     * Whether one of the other brakes is on: as many as LIMITS allows were counted in its window.
     *
     * @param string $kind A key of LIMITS.
     * @param string $what What is counted: the code as typed, the account's id or the visitor's address.
     * @return bool
     */
    public static function over(string $kind, string $what): bool {
        return self::used($kind, $what) >= self::LIMITS[$kind][0];
    }

    /**
     * Count one for one of the other brakes.
     *
     * @param string $kind A key of LIMITS.
     * @param string $what As for over().
     * @return void
     */
    public static function count(string $kind, string $what): void {
        $cache = \cache::make('local_oksigeniafamily', 'claimlimits');
        $key = self::limit_key($kind, $what);
        $used = self::used($kind, $what);
        $kept = $cache->get($key);
        $start = $used > 0 && is_array($kept) ? (int) $kept['t'] : time();
        $cache->set($key, ['n' => $used + 1, 't' => $start]);
    }

    /**
     * How many were counted in the window that is running.
     *
     * @param string $kind A key of LIMITS.
     * @param string $what As for over().
     * @return int
     */
    private static function used(string $kind, string $what): int {
        if (!isset(self::LIMITS[$kind])) {
            throw new \coding_exception('Unknown brake: ' . $kind);
        }
        $kept = \cache::make('local_oksigeniafamily', 'claimlimits')->get(self::limit_key($kind, $what));
        if (!is_array($kept) || time() - (int) ($kept['t'] ?? 0) >= self::LIMITS[$kind][1]) {
            return 0;
        }

        return (int) ($kept['n'] ?? 0);
    }

    /**
     * The cache key of one of the other brakes. A code is counted however it was typed (spaces, dashes, case), and a
     * visitor's address as key() counts it; nothing of either is kept in the clear.
     *
     * @param string $kind A key of LIMITS.
     * @param string $what As for over().
     * @return string
     */
    private static function limit_key(string $kind, string $what): string {
        if ($kind === 'code') {
            $what = strtoupper((string) preg_replace('/[\s-]+/', '', $what));
        } else if ($kind === 'mailfrom') {
            return $kind . '_' . self::key($what);
        }

        return $kind . '_' . sha1($what);
    }

    /**
     * The cache of failed tries (its entries last 15 minutes, see db/caches.php).
     *
     * @return \cache
     */
    private static function cache(): \cache {
        return \cache::make('local_oksigeniafamily', 'claimattempts');
    }

    /**
     * The cache key for an address.
     *
     * @param string $address The visitor's address.
     * @return string
     */
    private static function key(string $address): string {
        // An IPv6 home or phone line gets a whole /64: every address in it would count apart. Count the /64.
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($address);
            if ($packed !== false) {
                $address = bin2hex(substr($packed, 0, 8)) . '::/64';
            }
        }
        return sha1($address);
    }
}
