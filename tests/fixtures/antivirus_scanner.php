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

namespace antivirus_okftestable;

/**
 * An antivirus for the tests of local_oksigeniafamily: a file is infected when its content carries the word EICAR.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scanner extends \core\antivirus\scanner {
    /**
     * Always ready.
     *
     * @return bool
     */
    public function is_configured() {
        return true;
    }

    /**
     * Infected when the content says EICAR.
     *
     * @param string $file Full path to the file.
     * @param string $filename Its name.
     * @return int
     */
    public function scan_file($file, $filename) {
        return strpos((string) file_get_contents($file), 'EICAR') !== false ? self::SCAN_RESULT_FOUND : self::SCAN_RESULT_OK;
    }
}
