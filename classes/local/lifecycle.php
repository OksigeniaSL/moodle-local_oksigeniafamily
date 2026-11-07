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

use local_oksigeniafamily\api;

/**
 * How the family records keep up to date on their own: children who left the school leave their families, families
 * with no children left close after a grace period, and closed families and people who left are erased after their
 * retention period. The school does nothing for it: it only looks after its students, as it already does.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lifecycle {
    /**
     * The whole daily round.
     *
     * @param int|null $now The time to work from, for tests.
     * @return void
     */
    public static function run(?int $now = null): void {
        $now = $now ?? time();
        self::unlink_departed();
        self::close_childless($now);
        self::purge_closed($now);
        self::purge_left($now);
        // A student who is no longer here takes their photo and what their family said about it with them.
        photos::purge($now, self::days('gracedays', 30));
        standing::purge($now, self::days('gracedays', 30));
        roles::sync_all();
    }

    /**
     * Children whose account is gone or suspended leave their families.
     *
     * @return void
     */
    public static function unlink_departed(): void {
        global $DB;

        $sql = "SELECT c.id, u.id AS accountid, u.deleted, u.suspended
                  FROM {local_oksigeniafamily_child} c
             LEFT JOIN {user} u ON u.id = c.userid
                 WHERE c.status = :active AND c.userid IS NOT NULL
                   AND (u.id IS NULL OR u.deleted = 1 OR u.suspended = 1)";
        foreach ($DB->get_records_sql($sql, ['active' => api::ACTIVE]) as $child) {
            $reason = empty($child->accountid) || !empty($child->deleted) ? 'deleted' : 'suspended';
            api::unlink_child((int) $child->id, $reason);
        }
    }

    /**
     * Families with no children left close once the last child has been gone for the grace period (a student who
     * comes back within it finds the family as it was).
     *
     * @param int $now The time to work from.
     * @return void
     */
    public static function close_childless(int $now): void {
        global $DB;

        $grace = self::days('gracedays', 30) * DAYSECS;
        $sql = "SELECT f.id, f.timecreated, MAX(c.timeleft) AS lastleft
                  FROM {local_oksigeniafamily_family} f
             LEFT JOIN {local_oksigeniafamily_child} c ON c.familyid = f.id
                 WHERE f.status = :active
                   AND NOT EXISTS (
                       SELECT 1
                         FROM {local_oksigeniafamily_child} ac
                        WHERE ac.familyid = f.id AND ac.status = :childactive
                   )
              GROUP BY f.id, f.timecreated";
        foreach ($DB->get_records_sql($sql, ['active' => api::ACTIVE, 'childactive' => api::ACTIVE]) as $family) {
            $since = (int) ($family->lastleft ?: $family->timecreated);
            if ($since <= $now - $grace) {
                api::close_family((int) $family->id);
            }
        }
    }

    /**
     * Closed families are erased with their adults and children once their retention period is over.
     *
     * @param int $now The time to work from.
     * @return void
     */
    public static function purge_closed(int $now): void {
        global $DB;

        $limit = $now - self::days('retentiondays', 90) * DAYSECS;
        $select = 'status = :closed AND timeclosed <= :limit';
        $params = ['closed' => api::CLOSED, 'limit' => $limit];
        $families = $DB->get_fieldset_select('local_oksigeniafamily_family', 'id', $select, $params);
        foreach ($families as $familyid) {
            $DB->delete_records('local_oksigeniafamily_adult', ['familyid' => $familyid]);
            $DB->delete_records('local_oksigeniafamily_child', ['familyid' => $familyid]);
            $DB->delete_records('local_oksigeniafamily_family', ['id' => $familyid]);
        }
    }

    /**
     * The people who left a family that goes on are erased after the retention period too, and so are the codes that no
     * longer work: nothing stays for as long as a sibling is at the school.
     *
     * @param int $now The time to work from.
     * @return void
     */
    public static function purge_left(int $now): void {
        global $DB;

        $limit = $now - self::days('retentiondays', 90) * DAYSECS;
        $params = ['left' => api::LEFT, 'limit' => $limit];
        $DB->delete_records_select('local_oksigeniafamily_adult', 'status = :left AND timemodified <= :limit', $params);
        $DB->delete_records_select('local_oksigeniafamily_child', 'status = :left AND timeleft <= :limit', $params);
        $DB->delete_records_select(
            'local_oksigeniafamily_claim',
            '(status = :revoked AND timecreated <= :limit) OR expires <= :expired',
            ['revoked' => api::REVOKED, 'limit' => $limit, 'expired' => $limit]
        );
    }

    /**
     * A number of days from the settings, with its default when it was never saved.
     *
     * @param string $name The setting.
     * @param int $default Its default.
     * @return int
     */
    private static function days(string $name, int $default): int {
        $value = get_config('local_oksigeniafamily', $name);

        return $value === false || $value === '' ? $default : max(0, (int) $value);
    }
}
