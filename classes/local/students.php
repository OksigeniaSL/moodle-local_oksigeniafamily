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
 * Whom the codes are for: a student, the students of a group or a course, the members of a cohort; and how each of them
 * stands (adults in their family, a code in use). Only live accounts: a suspended student gets no letter.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class students {
    /** How many results a search gives. */
    public const LIMIT = 30;

    /**
     * The fields of a user that the pages need.
     *
     * @return string
     */
    private static function fields(): string {
        $fields = array_merge(['id', 'username', 'idnumber', 'suspended'], \core_user\fields::get_name_fields());

        return implode(', ', array_map(fn($field) => "u.$field", $fields));
    }

    /**
     * Live accounts whose name, username or ID number match.
     *
     * @param string $query What was typed.
     * @return \stdClass[] Users, keyed by id.
     */
    public static function search(string $query): array {
        global $CFG, $DB;

        $like = '%' . $DB->sql_like_escape($query) . '%';
        $fullname = $DB->sql_fullname('u.firstname', 'u.lastname');
        $sql = 'SELECT ' . self::fields() . '
                  FROM {user} u
                 WHERE u.deleted = 0 AND u.suspended = 0 AND u.id <> :guest AND u.mnethostid = :host
                   AND (' . $DB->sql_like($fullname, ':name', false, false) . '
                        OR ' . $DB->sql_like('u.username', ':username', false, false) . '
                        OR ' . $DB->sql_like('u.idnumber', ':idnumber', false, false) . ')
              ORDER BY u.lastname, u.firstname, u.id';

        return $DB->get_records_sql($sql, [
            'guest' => $CFG->siteguest, 'host' => $CFG->mnet_localhost_id,
            'name' => $like, 'username' => $like, 'idnumber' => $like,
        ], 0, self::LIMIT);
    }

    /**
     * Who a choice on the codes page stands for.
     *
     * @param string $scope user, group, course or cohort.
     * @param int $id Its id.
     * @return array|null name (of the choice, empty for a student) and users (keyed by id), or null when it does not exist.
     */
    public static function of_scope(string $scope, int $id): ?array {
        global $CFG, $DB;

        switch ($scope) {
            case 'user':
                $sql = 'SELECT ' . self::fields() . ' FROM {user} u
                         WHERE u.id = :id AND u.id <> :guest AND u.deleted = 0 AND u.suspended = 0';
                $users = $DB->get_records_sql($sql, ['id' => $id, 'guest' => $CFG->siteguest]);
                return $users ? ['name' => '', 'users' => $users] : null;
            case 'group':
                $group = $DB->get_record('groups', ['id' => $id]);
                return $group
                    ? ['name' => format_string($group->name), 'users' => self::enrolled((int) $group->courseid, $id)]
                    : null;
            case 'course':
                $course = $DB->get_record('course', ['id' => $id]);
                return $course && $id !== (int) SITEID
                    ? ['name' => format_string($course->fullname), 'users' => self::enrolled($id, 0)]
                    : null;
            case 'cohort':
                $cohort = $DB->get_record('cohort', ['id' => $id]);
                return $cohort ? ['name' => format_string($cohort->name), 'users' => self::cohort_members($id)] : null;
        }

        return null;
    }

    /**
     * The students of a course, or of one of its groups: who counts towards its completion reports, which is how Moodle
     * tells students from teachers whatever the roles of the site are called.
     *
     * @param int $courseid The course.
     * @param int $groupid The group, or 0.
     * @return \stdClass[] Users, keyed by id.
     */
    private static function enrolled(int $courseid, int $groupid): array {
        $context = \context_course::instance($courseid);
        $users = get_enrolled_users(
            $context,
            'moodle/course:isincompletionreports',
            $groupid,
            self::fields(),
            'u.lastname, u.firstname, u.id',
            0,
            0,
            true
        );

        return array_filter($users, fn($user) => empty($user->suspended));
    }

    /**
     * The members of a cohort.
     *
     * @param int $cohortid The cohort.
     * @return \stdClass[] Users, keyed by id.
     */
    private static function cohort_members(int $cohortid): array {
        global $DB;

        $sql = 'SELECT ' . self::fields() . '
                  FROM {cohort_members} cm
                  JOIN {user} u ON u.id = cm.userid
                 WHERE cm.cohortid = :cohortid AND u.deleted = 0 AND u.suspended = 0
              ORDER BY u.lastname, u.firstname, u.id';

        return $DB->get_records_sql($sql, ['cohortid' => $cohortid]);
    }

    /**
     * Courses and groups whose name matches.
     *
     * @param string $query What was typed.
     * @return array courses and groups: each with id, name and, for groups, coursename.
     */
    public static function search_courses_and_groups(string $query): array {
        global $DB;

        $like = '%' . $DB->sql_like_escape($query) . '%';
        $coursematch = $DB->sql_like('c.fullname', ':full', false, false)
            . ' OR ' . $DB->sql_like('c.shortname', ':short', false, false);
        $courses = $DB->get_records_sql(
            "SELECT c.id, c.fullname FROM {course} c WHERE c.id <> :site AND ($coursematch) ORDER BY c.fullname, c.id",
            ['site' => SITEID, 'full' => $like, 'short' => $like],
            0,
            self::LIMIT
        );
        $groups = $DB->get_records_sql(
            'SELECT g.id, g.name, c.fullname
               FROM {groups} g
               JOIN {course} c ON c.id = g.courseid
              WHERE ' . $DB->sql_like('g.name', ':name', false, false) . '
           ORDER BY g.name, g.id',
            ['name' => $like],
            0,
            self::LIMIT
        );

        return ['courses' => array_values($courses), 'groups' => array_values($groups)];
    }

    /**
     * The cohorts of the site with how many live accounts each has, those matching what was typed if anything was.
     *
     * @param string $query What was typed, or ''.
     * @return \stdClass[] Each with id, name and members.
     */
    public static function cohorts(string $query): array {
        global $DB;

        $where = '';
        $params = [];
        if ($query !== '') {
            $where = 'WHERE ' . $DB->sql_like('c.name', ':name', false, false);
            $params['name'] = '%' . $DB->sql_like_escape($query) . '%';
        }
        $sql = "SELECT c.id, c.name, COUNT(u.id) AS members
                  FROM {cohort} c
             LEFT JOIN {cohort_members} cm ON cm.cohortid = c.id
             LEFT JOIN {user} u ON u.id = cm.userid AND u.deleted = 0 AND u.suspended = 0
                       $where
              GROUP BY c.id, c.name
              ORDER BY c.name, c.id";

        return array_values($DB->get_records_sql($sql, $params, 0, self::LIMIT));
    }

    /**
     * How each student stands: how many adults they have and the code they have in use.
     *
     * @param int[] $userids The students.
     * @param int $now The time to work from.
     * @return array By user id: adults, expires (0 without a code in use) and claims (how many times it was used).
     */
    public static function state(array $userids, int $now): array {
        global $DB;

        $state = [];
        foreach ($userids as $userid) {
            $state[(int) $userid] = ['adults' => 0, 'expires' => 0, 'claims' => 0];
        }
        foreach (array_chunk(array_keys($state), 500) as $chunk) {
            [$in, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            $sql = "SELECT c.userid, COUNT(DISTINCT a.id) AS adults
                      FROM {local_oksigeniafamily_child} c
                      JOIN {local_oksigeniafamily_family} f ON f.id = c.familyid AND f.status = :factive
                      JOIN {local_oksigeniafamily_adult} a ON a.familyid = f.id AND a.status = :aactive
                     WHERE c.status = :cactive AND c.userid $in
                  GROUP BY c.userid";
            $active = ['factive' => api::ACTIVE, 'aactive' => api::ACTIVE, 'cactive' => api::ACTIVE];
            $adults = $DB->get_records_sql($sql, $params + $active);
            foreach ($adults as $row) {
                $state[(int) $row->userid]['adults'] = (int) $row->adults;
            }
            $sql = "SELECT userid, MAX(expires) AS expires, SUM(claims) AS claims
                      FROM {local_oksigeniafamily_claim}
                     WHERE status = :active AND expires >= :now AND userid $in
                  GROUP BY userid";
            foreach ($DB->get_records_sql($sql, $params + ['active' => api::ACTIVE, 'now' => $now]) as $row) {
                $state[(int) $row->userid]['expires'] = (int) $row->expires;
                $state[(int) $row->userid]['claims'] = (int) $row->claims;
            }
        }

        return $state;
    }
}
