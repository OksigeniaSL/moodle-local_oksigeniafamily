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

use local_oksigeniafamily\local\consent;
use local_oksigeniafamily\local\consent_notices;

/**
 * Sends a request for consent, or its reminder, out of the way of the page: a whole school can be many families.
 *
 * It goes by pieces. The first task works out who it goes to; if they are more than a piece, it queues a task for each
 * piece of adults, so that a failure only runs its own piece again. Each adult it reaches gets a mark for that sending,
 * and running it again skips whoever has one: nobody gets the same request, or the same reminder, twice.
 *
 * Custom data: requestid, reminder (bool), round (the number of this sending), optionally children (the students just
 * added) and, in a piece, adults (the adults of the piece).
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class consent_send extends \core\task\adhoc_task {
    /** Adults per piece. */
    public const PIECE = 200;

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
            // Each sending has its own number: the marks of one never skip anybody in another, such as a second reminder.
            'round' => random_int(1, 999999999),
        ]);
        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * Run it: a piece, or the first step, which sends it at once to a piece or less, and splits the rest in pieces.
     */
    public function execute() {
        $data = $this->get_custom_data();
        $requestid = (int) $data->requestid;
        $reminder = !empty($data->reminder);
        $children = isset($data->children) && is_array($data->children) ? array_map('intval', $data->children) : null;
        // A task queued before the sending went by pieces has no number of its own: its id serves.
        $round = (int) ($data->round ?? 0) ?: (int) $this->get_id();
        if (isset($data->adults) && is_array($data->adults)) {
            consent_notices::send($requestid, $reminder, $children, array_map('intval', $data->adults), $round);
            return;
        }

        $request = consent::get($requestid);
        if (!$request || !consent::is_open($request, time())) {
            return;
        }
        $adultids = array_keys(consent::recipients($request, $reminder, $children));
        if (count($adultids) <= self::PIECE) {
            consent_notices::send($requestid, $reminder, $children, $adultids, $round);
            return;
        }
        sort($adultids);
        foreach (array_chunk($adultids, self::PIECE) as $piece) {
            $task = new self();
            $task->set_custom_data((object) [
                'requestid' => $requestid,
                'reminder' => $reminder,
                'children' => $children,
                'round' => $round,
                'adults' => $piece,
            ]);
            // The first step run again queues each piece once, as long as it has not run yet; a piece that already ran
            // would skip everyone anyway.
            \core\task\manager::queue_adhoc_task($task, true);
        }
    }
}
