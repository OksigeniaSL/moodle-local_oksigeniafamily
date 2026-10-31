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
 * Phone numbers in one form (E.164, as WhatsApp and Oksigenia Marandu use them), so the same adult is found again.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class phone {
    /**
     * A number as typed, in E.164: without spaces or signs, with its country code (the site's, when it has none).
     *
     * @param string $typed The number as typed.
     * @param string $prefix The site's country code, such as +34.
     * @return string The number, or '' when it is not one.
     */
    public static function normalise(string $typed, string $prefix = '+34'): string {
        $digits = preg_replace('/[^\d+]/', '', $typed);
        if (strpos($digits, '00') === 0) {
            $digits = '+' . substr($digits, 2);
        }
        if ($digits !== '' && $digits[0] !== '+') {
            $digits = '+' . ltrim($prefix, '+') . $digits;
        }

        return preg_match('/^\+[1-9]\d{7,14}$/', $digits) ? $digits : '';
    }
}
