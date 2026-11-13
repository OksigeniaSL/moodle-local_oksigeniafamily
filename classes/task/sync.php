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

/**
 * The daily round of the family records: children who left, families with no children left, closed families past
 * their retention, and Moodle's parent role; and the files of old imports, with the previews nobody applied.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync extends \core\task\scheduled_task {
    /**
     * The task's name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task:sync', 'local_oksigeniafamily');
    }

    /**
     * Run it.
     */
    public function execute() {
        \local_oksigeniafamily\local\lifecycle::run();
        \local_oksigeniafamily\local\import\job::purge(time());
    }
}
