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

namespace local_oksigeniafamily;

/**
 * Event observers.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * A deleted account leaves its families at once: as a child it leaves; as an adult, the adult stays without it.
     *
     * @param \core\event\user_deleted $event The event.
     * @return void
     */
    public static function user_deleted(\core\event\user_deleted $event): void {
        global $DB;

        $userid = (int) $event->objectid;
        $children = $DB->get_records('local_oksigeniafamily_child', ['userid' => $userid, 'status' => api::ACTIVE], '', 'id');
        foreach ($children as $child) {
            api::unlink_child((int) $child->id, 'deleted');
        }
        foreach ($DB->get_records('local_oksigeniafamily_adult', ['userid' => $userid], '', 'id') as $adult) {
            api::set_adult_user((int) $adult->id, null);
        }
    }
}
