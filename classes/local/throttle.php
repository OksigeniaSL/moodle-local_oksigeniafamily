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
 * A brake on guessing codes: a few failed tries from one address, then a pause.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class throttle {
    /** How many wrong codes an address may try in a window. */
    public const LIMIT = 10;

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
        return sha1($address);
    }
}
