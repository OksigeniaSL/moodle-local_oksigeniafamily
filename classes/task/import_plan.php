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

use local_oksigeniafamily\local\import\job;

/**
 * Works out the preview of a big import in the background (see \local_oksigeniafamily\local\import\job::make_preview()):
 * reading what a file of tens of thousands of people would change is too long for a page. Nothing is applied here.
 *
 * Custom data: jobid.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class import_plan extends \core\task\adhoc_task {
    /**
     * Queue the preview of an import, to run as whoever loaded it.
     *
     * @param int $jobid The import.
     * @param int $userid Who loaded it; 0 for the user of cron.
     * @return void
     */
    public static function queue(int $jobid, int $userid = 0): void {
        $task = new self();
        $task->set_custom_data((object) ['jobid' => $jobid]);
        if ($userid > 0) {
            $task->set_userid($userid);
        }
        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * Work out the preview.
     *
     * @return void
     */
    public function execute() {
        job::make_preview((int) ($this->get_custom_data()->jobid ?? 0));
    }
}
