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
 * People's names as they are compared: the same however they were typed (case, accents, spaces).
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class names {
    /**
     * A name as compared: in lower case, without accents, with one space between its words.
     *
     * @param string $name The name.
     * @return string
     */
    public static function fold(string $name): string {
        $name = \core_text::strtolower(\core_text::specialtoascii(trim($name)));

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $name));
    }
}
