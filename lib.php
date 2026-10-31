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

/**
 * Library functions of local_oksigeniafamily: what other plugins look for here.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * For Oksigenia Marandu's list of numbers: who an adult of the family records is, such as "Lucía Sanz (family of Ana)".
 *
 * @param int $adultid The adult (Marandu's item id).
 * @return string|null The label, or null when the adult is not there.
 */
function local_oksigeniafamily_oksigeniamarandu_label(int $adultid): ?string {
    global $DB;

    $adult = $DB->get_record('local_oksigeniafamily_adult', ['id' => $adultid]);
    if (!$adult) {
        return null;
    }
    $name = trim(($adult->firstname ?? '') . ' ' . ($adult->lastname ?? ''));
    if ($name === '') {
        $name = $adult->phone ?? get_string('notice:someone', 'local_oksigeniafamily');
    }
    $children = [];
    foreach (\local_oksigeniafamily\api::children_of_adult($adultid) as $child) {
        $user = $child->userid ? \core_user::get_user((int) $child->userid, 'id, firstname') : null;
        if ($user) {
            $children[] = format_string($user->firstname);
        }
    }
    if (!$children) {
        return format_string($name);
    }

    return get_string('marandu:label', 'local_oksigeniafamily', (object) [
        'adult' => format_string($name),
        'children' => implode(', ', $children),
    ]);
}
