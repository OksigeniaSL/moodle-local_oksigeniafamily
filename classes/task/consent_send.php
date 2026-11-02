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
 * Sends a request for consent, or its reminder, out of the way of the page: a whole school can be many families.
 *
 * Custom data: requestid, reminder (bool) and, optionally, children (the students just added).
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class consent_send extends \core\task\adhoc_task {
    /**
     * Queue it.
     *
     * @param int $requestid The request.
     * @param bool $reminder Whether it is the reminder.
     * @param int[]|null $children Only about these students; all if null.
     * @return void
     */
    public static function queue(int $requestid, bool $reminder = false, ?array $children = null): void {
        $task = new self();
        $task->set_custom_data((object) [
            'requestid' => $requestid,
            'reminder' => $reminder,
            'children' => $children === null ? null : array_values(array_map('intval', $children)),
        ]);
        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * Run it.
     */
    public function execute() {
        $data = $this->get_custom_data();
        $children = isset($data->children) && is_array($data->children) ? array_map('intval', $data->children) : null;
        \local_oksigeniafamily\local\consent_notices::send((int) $data->requestid, !empty($data->reminder), $children);
    }
}
