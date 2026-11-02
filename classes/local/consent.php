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
 * Requests for consent: the school asks the families of some students for their permission (a trip, the photos of the
 * year, going home alone) and each adult answers yes or no by whatever way reaches them.
 *
 * Every answer is a new row and none is ever changed: the history is the record of who said what, when and how. An
 * adult can change their mind until the deadline; what counts is the last answer of each adult.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class consent {
    /** @var string The answer that gives consent. */
    public const YES = 'yes';
    /** @var string The answer that refuses it. */
    public const NO = 'no';
    /** @var string A student whose family has not answered yet. */
    public const PENDING = 'pending';

    /** @var string A request families can answer until its deadline. */
    public const OPEN = 'open';
    /** @var string A request the school called off. */
    public const CANCELLED = 'cancelled';

    /** @var string[] Where an answer came from. */
    public const CHANNELS = ['app', 'web', 'email', 'link', 'whatsapp', 'telegram', 'school'];

    /** @var int Days a request is kept after its deadline, unless the school says otherwise. */
    public const KEEPDAYS = 365;

    /**
     * Make a request for the given students. It does not send it: \local_oksigeniafamily\task\consent_send does.
     *
     * @param \context $context Where it is made: a course or the site.
     * @param array $data title, message, messageformat, deadline, scope and scopeid (who it goes to: a course, a group or a
     *                    cohort), needall and keepdays.
     * @param int[] $childuserids The students it is about.
     * @param int $userid Who makes it.
     * @return int The request.
     */
    public static function create(\context $context, array $data, array $childuserids, int $userid): int {
        global $DB;

        $title = trim((string) ($data['title'] ?? ''));
        $deadline = (int) ($data['deadline'] ?? 0);
        if ($title === '' || $deadline <= 0) {
            throw new \coding_exception('A request needs a title and a deadline');
        }
        $now = time();
        $requestid = (int) $DB->insert_record('local_oksigeniafamily_request', (object) [
            'contextid' => $context->id,
            'title' => \core_text::substr($title, 0, 255),
            'message' => (string) ($data['message'] ?? ''),
            'messageformat' => (int) ($data['messageformat'] ?? FORMAT_HTML),
            'deadline' => $deadline,
            'scope' => in_array($data['scope'] ?? '', ['course', 'group', 'cohort'], true) ? $data['scope'] : '',
            'scopeid' => (int) ($data['scopeid'] ?? 0),
            'needall' => empty($data['needall']) ? 0 : 1,
            'keepdays' => max(0, (int) ($data['keepdays'] ?? self::KEEPDAYS)),
            'status' => self::OPEN,
            'usercreated' => $userid,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        self::add_students($requestid, $childuserids);

        return $requestid;
    }

    /**
     * Add students to a request: those who joined the class later, for instance.
     *
     * @param int $requestid The request.
     * @param int[] $childuserids The students.
     * @return int[] The students added, without those it already had.
     */
    public static function add_students(int $requestid, array $childuserids): array {
        global $DB;

        $have = array_map('intval', $DB->get_fieldset_select(
            'local_oksigeniafamily_target',
            'userid',
            'requestid = ?',
            [$requestid]
        ));
        $added = [];
        $now = time();
        foreach (array_unique(array_map('intval', $childuserids)) as $userid) {
            if ($userid <= 0 || in_array($userid, $have, true)) {
                continue;
            }
            if (!$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
                continue;
            }
            $DB->insert_record('local_oksigeniafamily_target', (object) [
                'requestid' => $requestid,
                'userid' => $userid,
                'timecreated' => $now,
            ]);
            $added[] = $userid;
        }

        return $added;
    }

    /**
     * A request.
     *
     * @param int $requestid The request.
     * @return \stdClass|null
     */
    public static function get(int $requestid): ?\stdClass {
        global $DB;

        return $DB->get_record('local_oksigeniafamily_request', ['id' => $requestid]) ?: null;
    }

    /**
     * Whether families can still answer a request.
     *
     * @param \stdClass $request The request.
     * @param int $now The time.
     * @return bool
     */
    public static function is_open(\stdClass $request, int $now): bool {
        return $request->status === self::OPEN && $now <= (int) $request->deadline;
    }

    /**
     * The students of a request.
     *
     * @param int $requestid The request.
     * @return int[]
     */
    public static function students(int $requestid): array {
        global $DB;

        return array_map('intval', $DB->get_fieldset_select(
            'local_oksigeniafamily_target',
            'userid',
            'requestid = ?',
            [$requestid]
        ));
    }

    /**
     * Record an answer.
     *
     * @param int $requestid The request.
     * @param int $childuserid The student.
     * @param int $adultid The adult of the family records who answers; 0 only for a paper answer from someone who is not
     *                     in them, written down by the school.
     * @param string $answer yes or no.
     * @param string $channel Where it came from (one of self::CHANNELS).
     * @param array $by userid (the account that answered, or that wrote a paper answer down), address (the e-mail, the
     *                  number or the chat, without an account), name (who answered, for a paper answer with no adult)
     *                  and comment, all optional.
     * @param int|null $now The time; now if not given.
     * @return \stdClass The answer.
     * @throws \moodle_exception consentclosed when it can no longer be answered, consentnotfamily when the adult is not
     *                           family of that student for that request.
     */
    public static function answer(
        int $requestid,
        int $childuserid,
        int $adultid,
        string $answer,
        string $channel,
        array $by = [],
        ?int $now = null
    ): \stdClass {
        global $DB;

        $now = $now ?? time();
        if (!in_array($answer, [self::YES, self::NO], true) || !in_array($channel, self::CHANNELS, true)) {
            throw new \coding_exception('Unknown answer or channel');
        }
        $request = self::get($requestid);
        if (!$request || !self::is_open($request, $now)) {
            throw new \moodle_exception('consentclosed', 'local_oksigeniafamily');
        }
        if (!$DB->record_exists('local_oksigeniafamily_target', ['requestid' => $requestid, 'userid' => $childuserid])) {
            throw new \moodle_exception('consentnotfamily', 'local_oksigeniafamily');
        }

        $name = trim((string) ($by['name'] ?? ''));
        if ($adultid > 0) {
            $adult = $DB->get_record('local_oksigeniafamily_adult', ['id' => $adultid, 'status' => api::ACTIVE]);
            if (!$adult || !api::adult_has_child($adultid, $childuserid)) {
                throw new \moodle_exception('consentnotfamily', 'local_oksigeniafamily');
            }
            $name = self::adult_name($adult);
        } else if ($channel !== 'school' || $name === '') {
            throw new \moodle_exception('consentnotfamily', 'local_oksigeniafamily');
        }

        $before = self::student_state($request, $childuserid)->conflict;
        $comment = trim((string) ($by['comment'] ?? ''));
        $row = (object) [
            'requestid' => $requestid,
            'childuserid' => $childuserid,
            'adultid' => $adultid,
            'adultname' => \core_text::substr($name, 0, 255),
            'answer' => $answer,
            'comment' => $comment === '' ? null : $comment,
            'channel' => $channel,
            'userid' => empty($by['userid']) ? null : (int) $by['userid'],
            'address' => empty($by['address']) ? null : \core_text::substr((string) $by['address'], 0, 100),
            'timecreated' => $now,
        ];
        $row->id = (int) $DB->insert_record('local_oksigeniafamily_answer', $row);

        // Whoever made the request hears of it when two adults come to say different things, not at every answer after.
        if (!$before && self::student_state($request, $childuserid)->conflict) {
            consent_notices::conflict($request, $childuserid);
        }

        return $row;
    }

    /**
     * What an adult is called, for the record and the pages.
     *
     * @param \stdClass $adult The adult.
     * @return string
     */
    public static function adult_name(\stdClass $adult): string {
        $name = trim(($adult->firstname ?? '') . ' ' . ($adult->lastname ?? ''));
        if ($name === '') {
            $name = (string) ($adult->phone ?? $adult->email ?? '');
        }

        return $name !== '' ? $name : get_string('notice:someone', 'local_oksigeniafamily');
    }

    /**
     * The last answer of each adult, by student.
     *
     * @param int $requestid The request.
     * @param int[]|null $childuserids Only these students; all if null.
     * @return array Student => adult => answer. A paper answer from someone not in the records goes under "p" and its id.
     */
    public static function last_answers(int $requestid, ?array $childuserids = null): array {
        global $DB;

        $params = ['requestid' => $requestid];
        $where = 'requestid = :requestid';
        if ($childuserids !== null) {
            if (!$childuserids) {
                return [];
            }
            [$in, $inparams] = $DB->get_in_or_equal($childuserids, SQL_PARAMS_NAMED, 'child');
            $where .= ' AND childuserid ' . $in;
            $params += $inparams;
        }
        $last = [];
        foreach ($DB->get_records_select('local_oksigeniafamily_answer', $where, $params, 'id ASC') as $row) {
            $key = (int) $row->adultid > 0 ? (int) $row->adultid : 'p' . $row->id;
            $last[(int) $row->childuserid][$key] = $row;
        }

        return $last;
    }

    /**
     * How a student stands in a request.
     *
     * @param \stdClass $request The request.
     * @param int $childuserid The student.
     * @param array|null $last Their last answers, from self::last_answers(), if already at hand.
     * @param \stdClass[]|null $adults Their adults, from api::adults_of_child(), if already at hand.
     * @return \stdClass status (yes, no or pending), conflict (adults said different things), adults (the student's
     *                   adults now, by id), last (the last answers, by adult) and missing (adults with no answer yet).
     */
    public static function student_state(
        \stdClass $request,
        int $childuserid,
        ?array $last = null,
        ?array $adults = null
    ): \stdClass {
        $last = $last ?? (self::last_answers((int) $request->id, [$childuserid])[$childuserid] ?? []);
        $adults = $adults ?? self::adults_of(api::adults_of_child($childuserid));

        // Only the adults of the student now count, and the paper answers the school wrote down: an adult the school
        // removed (a code in the wrong hands) leaves their answers in the history, but they no longer weigh.
        $last = array_filter($last, fn($row, $key) => is_string($key) || isset($adults[$key]), ARRAY_FILTER_USE_BOTH);
        $answers = array_map(fn($row) => $row->answer, $last);
        $yes = in_array(self::YES, $answers, true);
        $no = in_array(self::NO, $answers, true);
        $missing = array_values(array_diff(array_keys($adults), array_keys($last)));

        // A "no" from anyone weighs more than a "yes". With needall, every adult of the student has to say yes, unless the
        // school wrote down a paper answer, which speaks for the family.
        $paper = (bool) array_filter($last, fn($row) => $row->channel === 'school' && $row->answer === self::YES);
        if ($no) {
            $status = self::NO;
        } else if ($yes && (empty($request->needall) || !$missing || $paper)) {
            $status = self::YES;
        } else {
            $status = self::PENDING;
        }

        return (object) [
            'status' => $status,
            'conflict' => $yes && $no,
            'adults' => $adults,
            'last' => $last,
            'missing' => $status === self::PENDING ? $missing : [],
        ];
    }

    /**
     * Key a list of adults by their id.
     *
     * @param \stdClass[] $adults Adults, as api::adults_of_child() gives them.
     * @return \stdClass[]
     */
    private static function adults_of(array $adults): array {
        $keyed = [];
        foreach ($adults as $adult) {
            $keyed[(int) $adult->id] = $adult;
        }

        return $keyed;
    }

    /**
     * How every student of a request stands.
     *
     * @param \stdClass $request The request.
     * @return \stdClass[] By student, as self::student_state() says.
     */
    public static function state(\stdClass $request): array {
        $students = self::students((int) $request->id);
        $last = self::last_answers((int) $request->id);
        $state = [];
        foreach ($students as $userid) {
            $state[$userid] = self::student_state($request, $userid, $last[$userid] ?? []);
        }

        return $state;
    }

    /**
     * How many students are in each status.
     *
     * @param \stdClass[] $state As self::state() gives it.
     * @return array yes, no, pending, nofamily (pending students with no adult in the records) and conflict.
     */
    public static function counts(array $state): array {
        $counts = ['yes' => 0, 'no' => 0, 'pending' => 0, 'nofamily' => 0, 'conflict' => 0];
        foreach ($state as $one) {
            $counts[$one->status]++;
            if ($one->status === self::PENDING && !$one->adults) {
                $counts['nofamily']++;
            }
            if ($one->conflict) {
                $counts['conflict']++;
            }
        }

        return $counts;
    }

    /**
     * Who to tell about a request: on sending it, every adult of its students; for a reminder, only the adults whose
     * answer is still missing.
     *
     * @param \stdClass $request The request.
     * @param bool $reminder Whether it is a reminder.
     * @param int[]|null $childuserids Only these students; all if null.
     * @return array Adult id => [adult, children (user ids)].
     */
    public static function recipients(\stdClass $request, bool $reminder, ?array $childuserids = null): array {
        $students = $childuserids ?? self::students((int) $request->id);
        $last = self::last_answers((int) $request->id, $students);
        $recipients = [];
        foreach ($students as $userid) {
            $state = self::student_state($request, $userid, $last[$userid] ?? []);
            $adultids = $reminder ? $state->missing : array_keys($state->adults);
            foreach ($adultids as $adultid) {
                $recipients[$adultid] = $recipients[$adultid] ?? ['adult' => $state->adults[$adultid], 'children' => []];
                $recipients[$adultid]['children'][] = $userid;
            }
        }

        return $recipients;
    }

    /**
     * The open requests about the children of a person with an account: what they have to answer.
     *
     * @param int $userid The person (a parent with an account, or the account of Aty).
     * @param int $now The time.
     * @return array Each with request, adult and children (user ids), the soonest deadline first.
     */
    public static function for_user(int $userid, int $now): array {
        global $DB;

        $sql = "SELECT t.id, t.requestid, t.userid AS childuserid, a.id AS adultid
                  FROM {local_oksigeniafamily_adult} a
                  JOIN {local_oksigeniafamily_child} c ON c.familyid = a.familyid AND c.status = :childactive
                  JOIN {local_oksigeniafamily_target} t ON t.userid = c.userid
                  JOIN {local_oksigeniafamily_request} r ON r.id = t.requestid
                 WHERE a.userid = :userid AND a.status = :adultactive AND r.status = :open AND r.deadline >= :now
              ORDER BY r.deadline, r.id, t.userid";
        $rows = $DB->get_records_sql($sql, [
            'userid' => $userid,
            'childactive' => api::ACTIVE,
            'adultactive' => api::ACTIVE,
            'open' => self::OPEN,
            'now' => $now,
        ]);
        $list = [];
        foreach ($rows as $row) {
            $key = $row->requestid . ':' . $row->adultid;
            if (!isset($list[$key])) {
                $list[$key] = [
                    'request' => self::get((int) $row->requestid),
                    'adult' => $DB->get_record('local_oksigeniafamily_adult', ['id' => $row->adultid]),
                    'children' => [],
                ];
            }
            if (!in_array((int) $row->childuserid, $list[$key]['children'], true)) {
                $list[$key]['children'][] = (int) $row->childuserid;
            }
        }

        return array_values($list);
    }

    /**
     * The students of a request an adult is family of.
     *
     * @param \stdClass $request The request.
     * @param int $adultid The adult.
     * @return int[]
     */
    public static function children_for_adult(\stdClass $request, int $adultid): array {
        $children = array_map(fn($child) => (int) $child->userid, api::children_of_adult($adultid));

        return array_values(array_intersect(self::students((int) $request->id), $children));
    }

    /**
     * Call a request off: families can no longer answer it, and what they answered stays.
     *
     * @param int $requestid The request.
     * @return void
     */
    public static function cancel(int $requestid): void {
        global $DB;

        $DB->update_record('local_oksigeniafamily_request', (object) [
            'id' => $requestid,
            'status' => self::CANCELLED,
            'timemodified' => time(),
        ]);
    }

    /**
     * Erase a request with its students, its answers and its file.
     *
     * @param int $requestid The request.
     * @return void
     */
    public static function delete(int $requestid): void {
        global $DB;

        $DB->delete_records('local_oksigeniafamily_answer', ['requestid' => $requestid]);
        $DB->delete_records('local_oksigeniafamily_target', ['requestid' => $requestid]);
        $DB->delete_records('local_oksigeniafamily_request', ['id' => $requestid]);
        get_file_storage()->delete_area_files(
            \context_system::instance()->id,
            'local_oksigeniafamily',
            'consent',
            $requestid
        );
    }

    /**
     * Erase the requests past their retention: so many days after their deadline, as each says.
     *
     * @param int $now The time.
     * @return int How many.
     */
    public static function purge(int $now): int {
        global $DB;

        $ids = $DB->get_fieldset_select(
            'local_oksigeniafamily_request',
            'id',
            'deadline + keepdays * :day < :now',
            ['day' => DAYSECS, 'now' => $now]
        );
        foreach ($ids as $id) {
            self::delete((int) $id);
        }

        return count($ids);
    }

    /**
     * The open requests whose deadline is within a day and that have not been reminded: the reminder goes now.
     *
     * @param int $now The time.
     * @return int[] The requests.
     */
    public static function due_reminders(int $now): array {
        global $DB;

        return array_map('intval', $DB->get_fieldset_select(
            'local_oksigeniafamily_request',
            'id',
            'status = :open AND timereminded IS NULL AND deadline > :now AND deadline <= :soon AND timecreated < :made',
            ['open' => self::OPEN, 'now' => $now, 'soon' => $now + DAYSECS, 'made' => $now - DAYSECS]
        ));
    }

    /**
     * The end of a day for a person, which is when a deadline falls.
     *
     * @param int $day Any time of that day.
     * @param \stdClass|null $user Whose day; the current user if not given.
     * @return int
     */
    public static function end_of_day(int $day, ?\stdClass $user = null): int {
        $timezone = $user ? \core_date::get_user_timezone($user) : 99;

        return usergetmidnight($day, $timezone) + DAYSECS - 1;
    }
}
