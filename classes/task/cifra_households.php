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

namespace local_oksigeniafamily\task;

use local_oksigeniafamily\local\import\cifra_households as households;

/**
 * The nightly round of the households of Cifra, when the school turns it on (see local\import\cifra_households): the new
 * students join their families, brothers and sisters together. It sends nothing to anyone.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cifra_households extends \core\task\scheduled_task {
    /**
     * The task's name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task:cifrahouseholds', 'local_oksigeniafamily');
    }

    /**
     * Run it: only the counts go to the log, never anyone's data.
     */
    public function execute() {
        if (!households::on()) {
            mtrace('Cifra households: off.');
            return;
        }
        $result = households::run(time());
        $counts = array_filter($result['counts'], fn($value) => is_int($value) && $value > 0);
        $said = implode(', ', array_map(fn($name, $value) => "$name $value", array_keys($counts), $counts));
        mtrace("Cifra households: {$result['state']}; {$result['students']} students, {$result['households']} households"
            . ($said !== '' ? "; $said" : '') . ($result['error'] !== '' ? "; {$result['error']}" : '') . '.');
    }
}
