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

// Copied from local_oksigeniaayvurenda (classes/core/readers/stage_guesser.php) by kit/lectores/lectores.py in hq.
// Do not edit it here: change it there and run the script again.

/**
 * A guess of the stage from a group's name.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_oksigeniafamily\local\readers;

/**
 * Proposes the stage of a group from its name («ESO 3º C» → «ESO», «3º Primaria A» → «Primaria», «Year 5 Blue» →
 * «Year»), for the librarian to confirm or change. Groups are named in every way in the world, so this only fills
 * the field: it never decides.
 *
 * Part of the core: plain PHP, with no call to Moodle.
 */
final class stage_guesser {
    /** @var string[] Small words that are never a stage. */
    private const SKIP = ['de', 'del', 'la', 'el', 'los', 'las', 'y', 'of', 'the', 'and', 'grupo', 'group', 'curso', 'clase'];

    /** @var string[] Units of an age: «2 años», «18 months». A group named by the children's age has no stage name. */
    private const AGES = ['año', 'años', 'mes', 'meses', 'month', 'months', 'an', 'ans', 'any', 'anys'];

    /**
     * The first word of the name that looks like a stage: letters, two or more, not an ordinal or a group letter.
     *
     * @param string $name
     * @return string Empty when nothing looks like one.
     */
    public static function guess(string $name): string {
        $words = preg_split('/[\s,;:\/\-]+/u', trim($name)) ?: [];
        $previous = '';
        foreach ($words as $word) {
            $clean = trim($word, " .º°ª()[]");
            if (ctype_digit($previous) && in_array(mb_strtolower($clean), self::AGES, true)) {
                return '';
            }
            $previous = $clean;
            if (!preg_match('/^\p{L}{2,}$/u', $clean)) {
                continue;
            }
            if (in_array(mb_strtolower($clean), self::SKIP, true)) {
                continue;
            }
            return $clean;
        }
        return '';
    }
}
