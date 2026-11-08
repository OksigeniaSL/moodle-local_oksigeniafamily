<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_oksigeniafamily\local;

/**
 * The doors of Family in «Your work», the dashboard block of the Oksigenia plugins: the family records, for whoever runs
 * them, and the requests for consent, for whoever asks for them on the whole site or has some open in a course.
 *
 * It runs on every load of the dashboard, so what it finds is kept for a minute for each person.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class work {
    /** The plugin. */
    private const COMPONENT = 'local_oksigeniafamily';

    /**
     * The doors of a person.
     *
     * @param int $userid The person.
     * @param int|null $now The time, for the tests; with it, nothing is kept.
     * @return array As the block takes them: key, title, url, status, alerts (text, url, level, due), actions (label, url).
     */
    public static function for_user(int $userid, ?int $now = null): array {
        if ($userid <= 0 || isguestuser($userid)) {
            return [];
        }
        if ($now !== null) {
            return self::doors($userid, $now);
        }
        $cache = \cache::make(self::COMPONENT, 'work');
        $key = $userid . '_' . current_language();
        $kept = $cache->get($key);
        if (is_array($kept)) {
            return $kept['doors'];
        }
        $doors = self::doors($userid, time());
        $cache->set($key, ['doors' => $doors]);

        return $doors;
    }

    /**
     * The doors, worked out now.
     *
     * @param int $userid The person.
     * @param int $now The time.
     * @return array
     */
    private static function doors(int $userid, int $now): array {
        $system = \context_system::instance();
        $doors = [];
        if (has_capability('local/oksigeniafamily:manage', $system, $userid)) {
            $doors[] = self::families();
        }
        $consents = self::consents($userid, has_capability('local/oksigeniafamily:requestconsent', $system, $userid), $now);
        if ($consents) {
            $doors[] = $consents;
        }

        return $doors;
    }

    /**
     * The door of the family records: how many there are, and the codes and the import at hand.
     *
     * @return array
     */
    private static function families(): array {
        global $DB;

        $families = $DB->count_records('local_oksigeniafamily_family', ['status' => 'active']);
        $children = $DB->count_records('local_oksigeniafamily_child', ['status' => 'active']);
        $accounts = $DB->count_records_select('local_oksigeniafamily_adult', "status = 'active' AND userid IS NOT NULL");

        return [
            'key' => self::COMPONENT,
            'title' => get_string('nav:families', self::COMPONENT),
            'url' => (new \moodle_url('/local/oksigeniafamily/index.php'))->out(false),
            'status' => implode(' · ', [
                self::figure('work:status:families', $families),
                self::figure('work:status:children', $children),
                self::figure('work:status:accounts', $accounts),
            ]),
            'alerts' => [],
            'actions' => [
                ['label' => get_string('nav:codes', self::COMPONENT),
                    'url' => (new \moodle_url('/local/oksigeniafamily/codes.php'))->out(false)],
                ['label' => get_string('nav:import', self::COMPONENT),
                    'url' => (new \moodle_url('/local/oksigeniafamily/import.php'))->out(false)],
            ],
        ];
    }

    /**
     * The door of the requests for consent. Whoever asks for them on the whole site sees all the open ones; a teacher,
     * only theirs, and only while one is open. The alerts are always the person's own: what closes today with students
     * still without an answer.
     *
     * @param int $userid The person.
     * @param bool $site Whether they may ask for consent on the whole site.
     * @param int $now The time.
     * @return array|null Null when there is no door.
     */
    private static function consents(int $userid, bool $site, int $now): ?array {
        global $DB;

        $where = 'status = :status AND deadline >= :now';
        $params = ['status' => consent::OPEN, 'now' => $now];
        if (!$site) {
            $where .= ' AND usercreated = :userid';
            $params['userid'] = $userid;
        }
        $open = $DB->get_records_select(
            'local_oksigeniafamily_request',
            $where,
            $params,
            'deadline ASC, id ASC',
            'id, contextid, title, deadline, usercreated'
        );
        if (!$site) {
            // Only where they may still ask for it: the page of the requests asks for the same.
            $open = array_filter($open, function (\stdClass $request) use ($userid): bool {
                $context = \context::instance_by_id((int) $request->contextid, IGNORE_MISSING);
                return $context && has_capability('local/oksigeniafamily:requestconsent', $context, $userid);
            });
            if (!$open) {
                return null;
            }
        }
        $waiting = self::without_answer(array_keys($open));

        $pageparams = $site ? [] : ['contextid' => (int) reset($open)->contextid];
        $alerts = [];
        $user = \core_user::get_user($userid);
        $endofday = consent::end_of_day($now, $user ?: null);
        foreach ($open as $request) {
            $count = $waiting[$request->id] ?? 0;
            if ((int) $request->usercreated !== $userid || (int) $request->deadline > $endofday || !$count) {
                continue;
            }
            $a = (object) ['title' => shorten_text(format_string($request->title, true, ['escape' => false]), 60),
                'count' => $count];
            $alerts[] = [
                'text' => get_string('work:alert:closes' . ($count === 1 ? ':one' : ''), self::COMPONENT, $a),
                'url' => (new \moodle_url('/local/oksigeniafamily/consent_report.php', ['id' => $request->id]))->out(false),
                'level' => 'today',
                'due' => (int) $request->deadline,
            ];
        }

        $figures = [];
        if ($open) {
            $figures[] = self::figure('work:status:open', count($open));
            $figures[] = self::figure('work:status:waiting', array_sum($waiting));
        } else {
            $figures[] = get_string('work:status:noneopen', self::COMPONENT);
        }

        return [
            'key' => self::COMPONENT . '-consents',
            'title' => get_string('consent:title', self::COMPONENT),
            'url' => (new \moodle_url('/local/oksigeniafamily/consents.php', $pageparams))->out(false),
            'status' => implode(' · ', $figures),
            'alerts' => $alerts,
            'actions' => [
                ['label' => get_string('consent:new', self::COMPONENT),
                    'url' => (new \moodle_url('/local/oksigeniafamily/consent_edit.php', $pageparams))->out(false)],
            ],
        ];
    }

    /**
     * How many students of each request have no answer at all yet, in one query.
     *
     * @param int[] $requestids The requests.
     * @return int[] By request; those with none are left out.
     */
    private static function without_answer(array $requestids): array {
        global $DB;

        if (!$requestids) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal($requestids, SQL_PARAMS_NAMED, 'request');
        $counts = $DB->get_records_sql_menu(
            "SELECT t.requestid, COUNT(1)
               FROM {local_oksigeniafamily_target} t
              WHERE t.requestid $in
                AND NOT EXISTS (
                    SELECT 1
                      FROM {local_oksigeniafamily_answer} a
                     WHERE a.requestid = t.requestid AND a.childuserid = t.userid
                )
           GROUP BY t.requestid",
            $params
        );

        return array_map('intval', $counts);
    }

    /**
     * A figure with its words, in the singular when it is one.
     *
     * @param string $key The string, whose «:one» form is for one.
     * @param int $count The figure.
     * @return string
     */
    private static function figure(string $key, int $count): string {
        return get_string($key . ($count === 1 ? ':one' : ''), self::COMPONENT, $count);
    }
}
