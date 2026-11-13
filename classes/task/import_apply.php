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
 * Applies a big import in the background, by batches (see \local_oksigeniafamily\local\import\job::run()). When it fails,
 * Moodle runs it again later, and it goes on from the last batch that went in.
 *
 * Custom data: jobid.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class import_apply extends \core\task\adhoc_task {
    /**
     * Queue it.
     *
     * @param int $jobid The import.
     * @param int $delay Seconds to wait before it runs.
     * @return void
     */
    public static function queue(int $jobid, int $delay = 0): void {
        $task = new self();
        $task->set_custom_data((object) ['jobid' => $jobid]);
        if ($delay > 0) {
            $task->set_next_run_time(time() + $delay);
        }
        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * Run it.
     */
    public function execute() {
        $jobid = (int) ($this->get_custom_data()->jobid ?? 0);
        if (!job::run($jobid)) {
            // Another import is going in: this one waits its turn.
            self::queue($jobid, MINSECS);
        }
    }
}
