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
use local_oksigeniafamily\task\consent_send;

/**
 * What the school does with requests for consent from its pages: choose whose families it asks, write the letter with
 * its circular and its terms, send it, and write down an answer that came on paper.
 *
 * The letter is a plain form of the page, not a moodleform: everything it brings is checked here again, on the server.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class consent_school {
    /** @var int[] How long the answers can be kept after the deadline, in days; all of them above consent::MINKEEPDAYS. */
    public const KEEPOPTIONS = [90, 180, 365, 730];

    /** @var int[] The deadlines offered as a chip, in days from today. */
    public const UNTILS = [7, 14];

    /** @var string[] The kinds of file a circular can be: the same as the file manager took before. */
    public const FILETYPES = ['document', 'image'];

    /** @var int How many courses or cohorts the choice of whose families shows at once. */
    public const LIMIT = 30;

    /** @var string The capability to ask, and to see and write down the answers. */
    private const CAPABILITY = 'local/oksigeniafamily:requestconsent';

    /**
     * Whether the current user can ask the families of a course: they can see it and they have the capability there.
     *
     * @param int $courseid The course.
     * @return bool
     */
    public static function can_ask_course(int $courseid): bool {
        global $DB;

        if ($courseid <= 0 || $courseid === (int) SITEID) {
            return false;
        }
        $course = $DB->get_record('course', ['id' => $courseid], 'id, visible');
        $context = $course ? \context_course::instance($courseid, IGNORE_MISSING) : null;
        if (!$context) {
            return false;
        }
        if (!$course->visible && !has_capability('moodle/course:viewhiddencourses', $context)) {
            return false;
        }

        return has_capability(self::CAPABILITY, $context);
    }

    /**
     * Whether the current user can ask the families of a cohort: they can see it and they have the capability where it
     * lives (the site or a category).
     *
     * @param int $cohortid The cohort.
     * @return bool
     */
    public static function can_ask_cohort(int $cohortid): bool {
        global $DB;

        $cohort = $cohortid > 0 ? $DB->get_record('cohort', ['id' => $cohortid], 'id, contextid') : null;
        $context = $cohort ? \context::instance_by_id((int) $cohort->contextid, IGNORE_MISSING) : null;
        if (!$context) {
            return false;
        }

        return has_capability(self::CAPABILITY, $context)
            && has_any_capability(['moodle/cohort:view', 'moodle/cohort:manage'], $context);
    }

    /**
     * The courses whose families the current user can ask: those under way (begun, and not ended), or those whose name
     * matches what was typed.
     *
     * @param string $query What was typed, or ''.
     * @param int $now The time.
     * @return array items (each with id, name and count of students) and more (there are more than self::LIMIT).
     */
    public static function courses(string $query, int $now): array {
        global $DB;

        $params = ['site' => SITEID, 'level' => CONTEXT_COURSE];
        if ($query !== '') {
            $like = '%' . $DB->sql_like_escape($query) . '%';
            $where = '(' . $DB->sql_like('c.fullname', ':full', false, false)
                . ' OR ' . $DB->sql_like('c.shortname', ':short', false, false) . ')';
            $params += ['full' => $like, 'short' => $like];
        } else {
            $where = 'c.startdate <= :now AND (c.enddate = 0 OR c.enddate >= :now2)';
            $params += ['now' => $now, 'now2' => $now];
        }
        $ctxfields = \context_helper::get_preload_record_columns_sql('ctx');
        $sql = "SELECT c.id, c.fullname, c.visible, $ctxfields
                  FROM {course} c
                  JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = :level
                 WHERE c.id <> :site AND $where
              ORDER BY c.fullname, c.id";
        $items = [];
        $more = false;
        $courses = $DB->get_recordset_sql($sql, $params);
        foreach ($courses as $course) {
            \context_helper::preload_from_record($course);
            $context = \context_course::instance((int) $course->id);
            if (!$course->visible && !has_capability('moodle/course:viewhiddencourses', $context)) {
                continue;
            }
            if (!has_capability(self::CAPABILITY, $context)) {
                continue;
            }
            if (count($items) === self::LIMIT) {
                $more = true;
                break;
            }
            $items[] = [
                'id' => (int) $course->id,
                'name' => format_string($course->fullname, true, ['context' => $context]),
                'count' => self::count_students('course', (int) $course->id),
            ];
        }
        $courses->close();

        return ['items' => $items, 'more' => $more];
    }

    /**
     * The cohorts whose families the current user can ask, those whose name matches what was typed if anything was.
     *
     * @param string $query What was typed, or ''.
     * @return array items (each with id, name and count of members) and more (there are more than self::LIMIT).
     */
    public static function cohorts(string $query): array {
        global $DB;

        $where = '';
        $params = [];
        if ($query !== '') {
            $where = 'WHERE ' . $DB->sql_like('c.name', ':name', false, false);
            $params['name'] = '%' . $DB->sql_like_escape($query) . '%';
        }
        $sql = "SELECT c.id, c.name, c.contextid, COUNT(u.id) AS members
                  FROM {cohort} c
             LEFT JOIN {cohort_members} cm ON cm.cohortid = c.id
             LEFT JOIN {user} u ON u.id = cm.userid AND u.deleted = 0 AND u.suspended = 0
                       $where
              GROUP BY c.id, c.name, c.contextid
              ORDER BY c.name, c.id";
        $items = [];
        $more = false;
        $cohorts = $DB->get_recordset_sql($sql, $params);
        foreach ($cohorts as $cohort) {
            $context = \context::instance_by_id((int) $cohort->contextid, IGNORE_MISSING);
            if (!$context || !has_capability(self::CAPABILITY, $context)) {
                continue;
            }
            if (!has_any_capability(['moodle/cohort:view', 'moodle/cohort:manage'], $context)) {
                continue;
            }
            if (count($items) === self::LIMIT) {
                $more = true;
                break;
            }
            $items[] = [
                'id' => (int) $cohort->id,
                'name' => format_string($cohort->name, true, ['context' => $context]),
                'count' => (int) $cohort->members,
            ];
        }
        $cohorts->close();

        return ['items' => $items, 'more' => $more];
    }

    /**
     * How many students a choice stands for: the same live accounts students::of_scope() gives, only counted.
     *
     * @param string $scope course, group or cohort.
     * @param int $id Its id.
     * @return int
     */
    public static function count_students(string $scope, int $id): int {
        global $DB;

        if ($scope === 'cohort') {
            return $DB->count_records_sql(
                'SELECT COUNT(1)
                   FROM {cohort_members} cm
                   JOIN {user} u ON u.id = cm.userid
                  WHERE cm.cohortid = :cohortid AND u.deleted = 0 AND u.suspended = 0',
                ['cohortid' => $id]
            );
        }
        $courseid = $id;
        $groupid = 0;
        if ($scope === 'group') {
            $groupid = $id;
            $courseid = (int) $DB->get_field('groups', 'courseid', ['id' => $id]);
        }
        $context = $courseid && $courseid !== (int) SITEID ? \context_course::instance($courseid, IGNORE_MISSING) : null;
        if (!$context || !in_array($scope, ['course', 'group'], true)) {
            return 0;
        }
        [$esql, $params] = get_enrolled_sql($context, 'moodle/course:isincompletionreports', $groupid, true);

        return $DB->count_records_sql(
            "SELECT COUNT(1) FROM {user} u JOIN ($esql) je ON je.id = u.id WHERE u.deleted = 0 AND u.suspended = 0",
            $params
        );
    }

    /**
     * Whose families a letter can go to, from where it is written: inside a course, the course and each of its groups;
     * on the site, the course or the cohort chosen first. Without a choice on the site, none: the page asks first.
     *
     * @param \context $context Where it is written: a course or the site.
     * @param int $courseid The course chosen on the site, or 0.
     * @param int $cohortid The cohort chosen on the site, or 0.
     * @return array kind (course or cohort), name and options (each with value, label and count); [] without a choice.
     * @throws \moodle_exception nopermissions when the course or the cohort chosen is not one the user can ask.
     */
    public static function choices(\context $context, int $courseid, int $cohortid): array {
        global $DB;

        $component = 'local_oksigeniafamily';
        $incourse = $context->contextlevel == CONTEXT_COURSE;
        if ($incourse) {
            $courseid = (int) $context->instanceid;
            $cohortid = 0;
        }
        if ($courseid > 0) {
            if (!$incourse && !self::can_ask_course($courseid)) {
                throw new \moodle_exception('nopermissions', 'error', '', get_string('consent:new', $component));
            }
            $course = get_course($courseid);
            $coursecontext = \context_course::instance($courseid);
            $name = format_string($course->fullname, true, ['context' => $coursecontext]);
            $options = [[
                'value' => 'course:' . $courseid,
                'label' => $incourse ? get_string('consent:field:wholecourse', $component) : $name,
                'count' => self::count_students('course', $courseid),
            ]];
            foreach (groups_get_all_groups($courseid) as $group) {
                $options[] = [
                    'value' => 'group:' . $group->id,
                    'label' => format_string($group->name, true, ['context' => $coursecontext]),
                    'count' => self::count_students('group', (int) $group->id),
                ];
            }

            return ['kind' => 'course', 'name' => $name, 'options' => $options];
        }
        if ($cohortid > 0) {
            if (!self::can_ask_cohort($cohortid)) {
                throw new \moodle_exception('nopermissions', 'error', '', get_string('consent:new', $component));
            }
            $cohort = $DB->get_record('cohort', ['id' => $cohortid], 'id, name, contextid', MUST_EXIST);
            $name = format_string($cohort->name, true, ['context' => \context::instance_by_id((int) $cohort->contextid)]);

            return ['kind' => 'cohort', 'name' => $name, 'options' => [[
                'value' => 'cohort:' . $cohortid,
                'label' => $name,
                'count' => self::count_students('cohort', $cohortid),
            ]]];
        }

        return [];
    }

    /**
     * The deadlines offered as chips: in a week and in two, each with its day.
     *
     * @param int $now The time.
     * @return array Each with days and deadline (the end of that day for the user).
     */
    public static function deadline_options(int $now): array {
        $options = [];
        foreach (self::UNTILS as $days) {
            $options[] = ['days' => $days, 'deadline' => consent::end_of_day($now + $days * DAYSECS)];
        }

        return $options;
    }

    /**
     * The deadline chosen: a chip of days from today, or another day typed as YYYY-MM-DD, always at the end of that day
     * for whoever asks.
     *
     * @param string $until 7 or 14 (days), or day.
     * @param string $day The other day, as YYYY-MM-DD.
     * @param int $now The time.
     * @return int The deadline, or 0 when it is not a day or it has passed.
     */
    public static function deadline(string $until, string $day, int $now): int {
        if ($until === 'day') {
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $day, $parts)) {
                return 0;
            }
            [, $year, $month, $date] = array_map('intval', $parts);
            if (!checkdate($month, $date, $year)) {
                return 0;
            }
            // Midday of that day where the user is; the end of the day is worked out from it.
            $time = make_timestamp($year, $month, $date, 12);
        } else if (in_array((int) $until, self::UNTILS, true) && (string) (int) $until === $until) {
            $time = $now + (int) $until * DAYSECS;
        } else {
            return 0;
        }
        $deadline = consent::end_of_day($time);

        return $deadline >= $now ? $deadline : 0;
    }

    /**
     * How many days the answers are kept unless the school says otherwise: the setting of the site, never below the
     * minimum.
     *
     * @return int
     */
    public static function keep_default(): int {
        $days = (int) (get_config('local_oksigeniafamily', 'consentkeepdays') ?: consent::KEEPDAYS);

        return max(consent::MINKEEPDAYS, $days);
    }

    /**
     * The choices of how long the answers are kept, with the site's own if it is another.
     *
     * @return int[] Days, from fewest to most.
     */
    public static function keep_options(): array {
        $options = array_unique(array_merge(self::KEEPOPTIONS, [self::keep_default()]));
        $options = array_values(array_filter($options, fn($days) => $days >= consent::MINKEEPDAYS));
        sort($options);

        return $options;
    }

    /**
     * Read the letter that came from the page and check all of it.
     *
     * @param \context $context Where it is written.
     * @param array $choices As self::choices() gives them.
     * @param array $input who, title, message, until, untilday, needall, keepdays and draftitemid (the circular, in the
     *                     user's draft area).
     * @param int $now The time.
     * @return array The data for self::send() and the errors by field (none when it can go).
     */
    public static function read(\context $context, array $choices, array $input, int $now): array {
        global $CFG, $USER;

        $component = 'local_oksigeniafamily';
        $errors = [];

        $who = (string) ($input['who'] ?? '');
        $values = array_column($choices['options'] ?? [], 'value');
        $scope = '';
        $scopeid = 0;
        $students = [];
        if (!in_array($who, $values, true) || !preg_match('/^(course|group|cohort):(\d+)$/', $who, $parts)) {
            $errors['who'] = get_string('consent:error:who', $component);
        } else {
            [$scope, $scopeid] = [$parts[1], (int) $parts[2]];
            $found = students::of_scope($scope, $scopeid);
            $students = $found ? array_map('intval', array_keys($found['users'])) : [];
            if (!$students) {
                $errors['who'] = get_string('consent:error:nostudents', $component);
            }
        }

        $title = trim(clean_param((string) ($input['title'] ?? ''), PARAM_TEXT));
        if ($title === '') {
            $errors['title'] = get_string('consent:error:title', $component);
        }
        // Plain text: the paragraphs stay as they were typed, and format_text() makes it safe wherever it is shown.
        $message = trim(str_replace(["\r\n", "\r"], "\n", (string) ($input['message'] ?? '')));

        $deadline = self::deadline((string) ($input['until'] ?? ''), (string) ($input['untilday'] ?? ''), $now);
        if (!$deadline) {
            $errors['until'] = get_string('consent:error:deadline', $component);
        }

        $keepdays = (int) ($input['keepdays'] ?? 0);
        if ($keepdays < consent::MINKEEPDAYS || !in_array($keepdays, self::keep_options(), true)) {
            $errors['keepdays'] = get_string('consent:error:keepdays', $component, consent::MINKEEPDAYS);
        }

        // The circular went to the user's draft area when it came; it is checked again here, as it may be any draft.
        $draftitemid = (int) ($input['draftitemid'] ?? 0);
        if ($draftitemid > 0) {
            $max = get_user_max_upload_file_size($context, $CFG->maxbytes);
            $files = get_file_storage()->get_area_files(
                \context_user::instance($USER->id)->id,
                'user',
                'draft',
                $draftitemid,
                'id',
                false
            );
            foreach ($files as $file) {
                $toobig = $max != USER_CAN_IGNORE_FILE_SIZE_LIMITS && $file->get_filesize() > $max;
                if (!file_extension_in_typegroup($file->get_filename(), self::FILETYPES) || $toobig) {
                    $errors['attachment'] = get_string('consent:attach:type', $component);
                }
            }
            if (count($files) > 1) {
                $errors['attachment'] = get_string('consent:attach:type', $component);
            }
        }

        return [[
            'title' => \core_text::substr($title, 0, 255),
            'message' => $message,
            'messageformat' => FORMAT_PLAIN,
            'deadline' => $deadline,
            'scope' => $scope,
            'scopeid' => $scopeid,
            'needall' => empty($input['needall']) ? 0 : 1,
            'keepdays' => $keepdays,
            'students' => $students,
            'draftitemid' => $draftitemid,
        ], $errors];
    }

    /**
     * A file name that is safe to keep: no paths, no control characters, and not too long, with its extension.
     *
     * @param string $name The name the browser sent.
     * @return string
     */
    public static function clean_name(string $name): string {
        $name = trim(clean_param(trim($name), PARAM_FILE), " .\t\n\r\0\x0B");
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        if (\core_text::strlen($name) > 120) {
            $base = \core_text::substr(pathinfo($name, PATHINFO_FILENAME), 0, 110);
            $name = $extension !== '' ? $base . '.' . $extension : $base;
        }

        return $name;
    }

    /**
     * Take the circular that came with the letter: it has to be a document or an image within the size of uploads of
     * the site, and pass Moodle's antivirus, if the site has one. It goes to the user's draft area until the letter
     * goes, so that it is not lost if something else has to be fixed first.
     *
     * @param string $path Where it is, already on the server.
     * @param string $name The name the browser sent.
     * @param \context $context Where the letter is written.
     * @param int $draftitemid The draft area of a circular taken before, to replace it, or 0.
     * @return array draftitemid and name.
     * @throws \moodle_exception consent:attach:type, consent:attach:size or consent:attach:virus.
     */
    public static function take_file(string $path, string $name, \context $context, int $draftitemid): array {
        global $CFG, $USER;

        $component = 'local_oksigeniafamily';
        $name = self::clean_name($name);
        if (pathinfo($name, PATHINFO_FILENAME) === '' || !file_extension_in_typegroup($name, self::FILETYPES)) {
            throw new \moodle_exception('consent:attach:type', $component);
        }
        $size = is_file($path) ? (int) filesize($path) : 0;
        $max = get_user_max_upload_file_size($context, $CFG->maxbytes);
        if ($size <= 0 || ($max != USER_CAN_IGNORE_FILE_SIZE_LIMITS && $size > $max)) {
            throw new \moodle_exception('consent:attach:size', $component, '', display_size($max));
        }
        try {
            \core\antivirus\manager::scan_file($path, $name, true);
        } catch (\core\antivirus\scanner_exception $e) {
            throw new \moodle_exception('consent:attach:virus', $component);
        }

        $fs = get_file_storage();
        $usercontext = \context_user::instance($USER->id);
        if ($draftitemid > 0) {
            $fs->delete_area_files($usercontext->id, 'user', 'draft', $draftitemid);
        } else {
            $draftitemid = file_get_unused_draft_itemid();
        }
        $fs->create_file_from_pathname([
            'contextid' => $usercontext->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => $name,
            'userid' => $USER->id,
        ], $path);

        return ['draftitemid' => $draftitemid, 'name' => $name];
    }

    /**
     * The circular waiting in the user's draft area, if there is one.
     *
     * @param int $draftitemid The draft area.
     * @return \stored_file|null
     */
    public static function draft_file(int $draftitemid): ?\stored_file {
        global $USER;

        if ($draftitemid <= 0) {
            return null;
        }
        $files = get_file_storage()->get_area_files(
            \context_user::instance($USER->id)->id,
            'user',
            'draft',
            $draftitemid,
            'id',
            false
        );

        return $files ? reset($files) : null;
    }

    /**
     * Make the request with its circular, and queue the sending.
     *
     * @param \context $context Where it is made: a course or the site.
     * @param array $data As self::read() gives it, without errors.
     * @param int $userid Who makes it.
     * @return int The request.
     */
    public static function send(\context $context, array $data, int $userid): int {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        $requestid = consent::create($context, [
            'title' => $data['title'],
            'message' => $data['message'],
            'messageformat' => $data['messageformat'],
            'deadline' => $data['deadline'],
            'scope' => $data['scope'],
            'scopeid' => $data['scopeid'],
            'needall' => $data['needall'],
            'keepdays' => $data['keepdays'],
        ], $data['students'], $userid);
        if (!empty($data['draftitemid'])) {
            // Where the circulars of the requests have always been: the system, by request.
            file_save_draft_area_files(
                (int) $data['draftitemid'],
                \context_system::instance()->id,
                'local_oksigeniafamily',
                'consent',
                $requestid,
                ['subdirs' => 0, 'maxfiles' => 1]
            );
        }
        $transaction->allow_commit();
        consent_send::queue($requestid);

        return $requestid;
    }

    /**
     * Write down an answer that came on paper, signed by one of the student's adults in the records. The page checks
     * the capability and the session key; this checks the rest.
     *
     * @param \stdClass $request The request.
     * @param int $childuserid The student.
     * @param int $adultid The adult who signs it.
     * @param string $answer yes or no.
     * @param int $userid Who writes it down.
     * @return \stdClass The answer, with its channel school.
     * @throws \moodle_exception invalidrecord when the student is not in the request, consentnotfamily when the adult is
     *                           not theirs, consentclosed when it can no longer be answered.
     */
    public static function paper(\stdClass $request, int $childuserid, int $adultid, string $answer, int $userid): \stdClass {
        if (!in_array($childuserid, consent::students((int) $request->id), true)) {
            throw new \moodle_exception('invalidrecord');
        }
        if (!in_array($answer, [consent::YES, consent::NO], true)) {
            throw new \invalid_parameter_exception('answer');
        }
        $theirs = array_map(fn($adult) => (int) $adult->id, api::adults_of_child($childuserid));
        if ($adultid <= 0 || !in_array($adultid, $theirs, true)) {
            throw new \moodle_exception('consentnotfamily', 'local_oksigeniafamily');
        }

        return consent::answer((int) $request->id, $childuserid, $adultid, $answer, 'school', ['userid' => $userid]);
    }
}
