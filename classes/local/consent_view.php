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

/**
 * What the pages of the requests for consent show: the list, the answers for the school and the page where a family
 * answers.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class consent_view {
    /**
     * A date and time people read.
     *
     * @param int $time The time.
     * @return string
     */
    public static function datetime(int $time): string {
        return userdate($time, get_string('strftimedatetimeshort', 'langconfig'));
    }

    /**
     * What a channel is called.
     *
     * @param string $channel The channel.
     * @return string
     */
    public static function channel(string $channel): string {
        $key = 'consent:channel:' . $channel;

        return get_string_manager()->string_exists($key, 'local_oksigeniafamily')
            ? get_string($key, 'local_oksigeniafamily') : $channel;
    }

    /**
     * How a request stands, as a label and the class of its chip.
     *
     * @param \stdClass $request The request.
     * @param int $now The time.
     * @return array label and chip.
     */
    public static function request_status(\stdClass $request, int $now): array {
        if ($request->status === consent::CANCELLED) {
            return ['label' => get_string('consent:status:cancelled', 'local_oksigeniafamily'), 'chip' => 'okf-chip-muted'];
        }
        if (!consent::is_open($request, $now)) {
            return ['label' => get_string('consent:status:closed', 'local_oksigeniafamily'), 'chip' => 'okf-chip-muted'];
        }

        return ['label' => get_string('consent:status:open', 'local_oksigeniafamily'), 'chip' => ''];
    }

    /**
     * How a student stands, as a label and the class of its chip.
     *
     * @param \stdClass $state As consent::student_state() gives it.
     * @return array label and chip.
     */
    public static function student_status(\stdClass $state): array {
        $component = 'local_oksigeniafamily';
        if ($state->status === consent::YES) {
            return ['label' => get_string('consent:student:yes', $component), 'chip' => 'okf-chip-ok'];
        }
        if ($state->status === consent::NO) {
            return ['label' => get_string('consent:student:no', $component), 'chip' => 'okf-chip-bad'];
        }
        if (!$state->adults) {
            return ['label' => get_string('consent:student:nofamily', $component), 'chip' => 'okf-chip-warn'];
        }

        return ['label' => get_string('consent:student:pending', $component), 'chip' => 'okf-chip-muted'];
    }

    /**
     * The circular of a request, if it has one.
     *
     * @param \stdClass $request The request.
     * @param string $token The personal link it is seen with, for whoever has no account.
     * @return array|null name and url.
     */
    public static function attachment(\stdClass $request, string $token = ''): ?array {
        $files = get_file_storage()->get_area_files(
            \context_system::instance()->id,
            'local_oksigeniafamily',
            'consent',
            (int) $request->id,
            'id',
            false
        );
        $file = reset($files);
        if (!$file) {
            return null;
        }
        $url = \moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            'local_oksigeniafamily',
            'consent',
            (int) $request->id,
            $file->get_filepath(),
            $file->get_filename()
        );
        if ($token !== '') {
            $url->param('t', $token);
        }

        return ['name' => $file->get_filename(), 'url' => $url->out(false)];
    }

    /**
     * The request itself: its title, its text, its circular and until when.
     *
     * @param \stdClass $request The request.
     * @param int $now The time.
     * @param string $token The personal link, for whoever has no account.
     * @return array
     */
    public static function request(\stdClass $request, int $now, string $token = ''): array {
        $context = \context_system::instance();
        $open = consent::is_open($request, $now);
        $attachment = self::attachment($request, $token);

        return [
            'title' => format_string($request->title, true, ['context' => $context]),
            'message' => format_text($request->message, (int) $request->messageformat, ['context' => $context]),
            'hasattachment' => (bool) $attachment,
            'attachment' => $attachment,
            'open' => $open,
            'until' => get_string(
                $open ? 'consent:until' : ($request->status === consent::CANCELLED ? 'consent:cancelled' : 'consent:closedon'),
                'local_oksigeniafamily',
                page::date((int) $request->deadline)
            ),
        ];
    }

    /**
     * The page where an adult answers for each of their children.
     *
     * @param \stdClass $request The request.
     * @param \stdClass $adult The adult.
     * @param int[] $children The students of the request they are family of.
     * @param int $now The time.
     * @param \moodle_url $action Where the answers go.
     * @param array $hidden What else goes with them (the personal link, or the request).
     * @return array For local_oksigeniafamily/consent_answer.
     */
    public static function answer(
        \stdClass $request,
        \stdClass $adult,
        array $children,
        int $now,
        \moodle_url $action,
        array $hidden
    ): array {
        $component = 'local_oksigeniafamily';
        $last = consent::last_answers((int) $request->id, $children);
        $open = consent::is_open($request, $now);
        $rows = [];
        foreach ($children as $childuserid) {
            $child = \core_user::get_user($childuserid);
            if (!$child || !empty($child->deleted)) {
                continue;
            }
            $mine = $last[$childuserid][(int) $adult->id] ?? null;
            $fields = [];
            foreach ($hidden + ['childuserid' => $childuserid, 'sesskey' => sesskey()] as $name => $value) {
                $fields[] = ['name' => $name, 'value' => $value];
            }
            $rows[] = [
                'name' => format_string($child->firstname),
                'fullname' => fullname($child),
                'answered' => (bool) $mine,
                'saidyes' => $mine && $mine->answer === consent::YES,
                'saidno' => $mine && $mine->answer === consent::NO,
                'answertext' => $mine ? get_string(
                    $mine->answer === consent::YES ? 'consent:yousaidyes' : 'consent:yousaidno',
                    $component,
                    (object) ['child' => format_string($child->firstname), 'when' => self::datetime((int) $mine->timecreated)]
                ) : '',
                'yeslabel' => get_string('consent:yes', $component),
                'nolabel' => get_string('consent:no', $component),
                'yesaria' => get_string('consent:yesfor', $component, format_string($child->firstname)),
                'noaria' => get_string('consent:nofor', $component, format_string($child->firstname)),
                'hidden' => $fields,
                'commentid' => 'okf-comment-' . $childuserid,
            ];
        }

        return self::request($request, $now, (string) ($hidden['t'] ?? '')) + [
            'action' => $action->out(false),
            'as' => get_string('consent:as', $component, consent::adult_name($adult)),
            'children' => $rows,
            'haschildren' => (bool) $rows,
            'open' => $open,
        ];
    }

    /**
     * The page behind another plugin's key of a student: the key is the student's, so whoever answers says which of the
     * student's adults they are, and sees what the others answered.
     *
     * @param \stdClass $request The request.
     * @param int $childuserid The student.
     * @param \stdClass[] $adults The student's adults in the records, by id.
     * @param int $now The time.
     * @param \moodle_url $action Where the answers go.
     * @param array $hidden What else goes with them (the link).
     * @return array For local_oksigeniafamily/consent_answer.
     */
    public static function answer_by_key(
        \stdClass $request,
        int $childuserid,
        array $adults,
        int $now,
        \moodle_url $action,
        array $hidden
    ): array {
        $component = 'local_oksigeniafamily';
        $child = \core_user::get_user($childuserid);
        $view = self::request($request, $now, (string) ($hidden['t'] ?? '')) + [
            'action' => $action->out(false),
            'as' => '',
            'children' => [],
            'haschildren' => false,
            'note' => '',
        ];
        if (!$child || !empty($child->deleted)) {
            return $view;
        }
        if (!$adults) {
            $view['note'] = get_string('consent:key:noadults', $component);
            $view['open'] = false;
        } else if (!empty($request->needall)) {
            $view['note'] = get_string('consent:key:needall', $component);
            $view['open'] = false;
        }

        $last = consent::last_answers((int) $request->id, [$childuserid])[$childuserid] ?? [];
        $options = [];
        $answers = [];
        foreach ($adults as $adultid => $adult) {
            $name = consent::adult_name($adult);
            $options[] = ['id' => (int) $adultid, 'name' => $name];
            if (isset($last[$adultid])) {
                $answers[] = get_string('consent:key:said', $component, (object) [
                    'adult' => $name,
                    'answer' => get_string($last[$adultid]->answer === consent::YES ? 'consent:yes' : 'consent:no', $component),
                    'when' => self::datetime((int) $last[$adultid]->timecreated),
                ]);
            }
        }
        $fields = [];
        foreach ($hidden + ['childuserid' => $childuserid, 'sesskey' => sesskey()] as $name => $value) {
            $fields[] = ['name' => $name, 'value' => $value];
        }
        $first = format_string($child->firstname);
        $view['haschildren'] = true;
        $view['children'][] = [
            'name' => $first,
            'fullname' => fullname($child),
            'answered' => false,
            'hasanswers' => (bool) $answers,
            'answers' => $answers,
            'chooser' => $adults ? [
                'selectid' => 'okf-who-' . $childuserid,
                'options' => $options,
                // With more than one adult, nobody is chosen for them: they say who they are.
                'placeholder' => count($options) > 1 ? get_string('consent:whoareyou', $component) : '',
            ] : null,
            'yeslabel' => get_string('consent:yes', $component),
            'nolabel' => get_string('consent:no', $component),
            'yesaria' => get_string('consent:yesfor', $component, $first),
            'noaria' => get_string('consent:nofor', $component, $first),
            'hidden' => $fields,
            'commentid' => 'okf-comment-' . $childuserid,
        ];

        return $view;
    }

    /**
     * The answers of a request, for the school.
     *
     * @param \stdClass $request The request.
     * @param int $now The time.
     * @return array For local_oksigeniafamily/consent_report (without the actions, which the page adds).
     */
    public static function report(\stdClass $request, int $now): array {
        global $DB;

        $component = 'local_oksigeniafamily';
        $state = consent::state($request);
        $counts = consent::counts($state);
        $open = consent::is_open($request, $now);
        $users = $state ? $DB->get_records_list('user', 'id', array_keys($state)) : [];
        $rows = [];
        foreach ($state as $userid => $one) {
            $user = $users[$userid] ?? null;
            if (!$user) {
                continue;
            }
            $people = [];
            foreach ($one->adults as $adultid => $adult) {
                $answer = $one->last[$adultid] ?? null;
                $people[] = self::person(consent::adult_name($adult), $answer);
            }
            foreach ($one->last as $key => $answer) {
                if (is_string($key)) {
                    $people[] = self::person($answer->adultname, $answer);
                }
            }
            $status = self::student_status($one);
            $rows[] = [
                'name' => fullname($user),
                'sort' => \core_text::strtolower($user->lastname . ' ' . $user->firstname),
                'status' => $status['label'],
                'chip' => $status['chip'],
                'conflict' => $one->conflict,
                'people' => $people,
                'haspeople' => (bool) $people,
                'recordurl' => $open ? (new \moodle_url('/local/oksigeniafamily/consent_record.php', [
                    'id' => $request->id, 'child' => $userid,
                ]))->out(false) : '',
            ];
        }
        \core_collator::asort_array_of_arrays_by_key($rows, 'sort');
        $total = count($state);

        return self::request($request, $now) + [
            'status' => self::request_status($request, $now),
            'tiles' => [
                ['label' => get_string('consent:count:yes', $component), 'value' => $counts['yes'], 'tone' => 'ok'],
                ['label' => get_string('consent:count:no', $component), 'value' => $counts['no'], 'tone' => 'bad'],
                ['label' => get_string('consent:count:pending', $component), 'value' => $counts['pending'], 'tone' => ''],
            ],
            'lede' => get_string('consent:report:lede', $component, (object) [
                'total' => $total,
                'nofamily' => $counts['nofamily'],
            ]),
            'hasnofamily' => $counts['nofamily'] > 0,
            'nofamily' => get_string('consent:report:nofamily', $component, $counts['nofamily']),
            'rows' => array_values($rows),
            'hasrows' => (bool) $rows,
            'remindable' => $counts['pending'] - $counts['nofamily'],
        ];
    }

    /**
     * One adult of a student and what they answered, for the school.
     *
     * @param string $name Who.
     * @param \stdClass|null $answer Their last answer.
     * @return array
     */
    private static function person(string $name, ?\stdClass $answer): array {
        $component = 'local_oksigeniafamily';
        if (!$answer) {
            return ['name' => $name, 'answer' => get_string('consent:noanswer', $component), 'chip' => 'okf-chip-muted',
                'detail' => '', 'comment' => ''];
        }

        return [
            'name' => $name,
            'answer' => get_string($answer->answer === consent::YES ? 'consent:yes' : 'consent:no', $component),
            'chip' => $answer->answer === consent::YES ? 'okf-chip-ok' : 'okf-chip-bad',
            'detail' => self::datetime((int) $answer->timecreated) . ' · ' . self::channel($answer->channel),
            'comment' => (string) ($answer->comment ?? ''),
        ];
    }

    /**
     * The list of requests.
     *
     * @param \stdClass[] $requests The requests.
     * @param int $now The time.
     * @param bool $wherecolumn Whether to say where each was made (on the list of the whole site).
     * @return array Rows for local_oksigeniafamily/consents.
     */
    public static function rows(array $requests, int $now, bool $wherecolumn): array {
        $rows = [];
        foreach ($requests as $request) {
            $counts = consent::counts(consent::state($request));
            $where = '';
            if ($wherecolumn) {
                $context = \context::instance_by_id((int) $request->contextid, IGNORE_MISSING);
                $where = $context ? $context->get_context_name(false, true) : '';
            }
            $rows[] = [
                'url' => (new \moodle_url('/local/oksigeniafamily/consent_report.php', ['id' => $request->id]))->out(false),
                'title' => format_string($request->title, true, ['context' => \context_system::instance()]),
                'deadline' => page::date((int) $request->deadline),
                'status' => self::request_status($request, $now),
                'counts' => get_string('consent:list:counts', 'local_oksigeniafamily', (object) $counts),
                'where' => $where,
            ];
        }

        return $rows;
    }

    /**
     * The card of a child's page in Oksigenia Aty: the open requests about the child, with what this adult answered.
     *
     * @param int $adultuserid The adult's account.
     * @param int $childuserid The child.
     * @param int $now The time.
     * @return array One card, or none.
     */
    public static function child_card(int $adultuserid, int $childuserid, int $now): array {
        $component = 'local_oksigeniafamily';
        $items = [];
        foreach (consent::for_user($adultuserid, $now) as $item) {
            if (!in_array($childuserid, $item['children'], true)) {
                continue;
            }
            $request = $item['request'];
            $deadline = (int) $request->deadline;
            $until = page::date($deadline);
            $mine = consent::last_answers((int) $request->id, [$childuserid])[$childuserid][(int) $item['adult']->id] ?? null;
            $detail = $mine
                ? get_string($mine->answer === consent::YES ? 'consent:aty:yes' : 'consent:aty:no', $component, $until)
                : get_string('consent:aty:missing', $component, $until);
            $items[] = [
                'title' => format_string($request->title, true, ['context' => \context_system::instance()]),
                'detail' => $detail,
                'date' => $deadline,
                'urgent' => !$mine && $deadline - $now <= 2 * DAYSECS,
                'url' => (new \moodle_url('/local/oksigeniafamily/consent.php', ['id' => $request->id]))->out(false),
            ];
        }
        if (!$items) {
            return [];
        }

        return [['heading' => get_string('consent:aty:heading', $component), 'items' => $items]];
    }
}
