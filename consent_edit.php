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

/**
 * A new request for consent, written as a letter: to whom, what it is, the circular and its terms. It goes out at once,
 * by the tasks of Moodle. On the site, the page first asks whose families: a course or a cohort.
 *
 * The letter is the page's own form, not a moodleform: who it goes to is a chip, so changing it does not reload the page
 * and lose what was typed or the file. Everything is checked again on the server (consent_school::read()).
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

use local_oksigeniafamily\local\consent_school;
use local_oksigeniafamily\local\page;

$contextid = optional_param('contextid', 0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$cohortid = $courseid ? 0 : optional_param('cohortid', 0, PARAM_INT);
$q = trim(optional_param('q', '', PARAM_TEXT));
$context = $contextid ? context::instance_by_id($contextid) : context_system::instance();
$component = 'local_oksigeniafamily';
$title = get_string('consent:new', $component);
$incourse = $context->contextlevel == CONTEXT_COURSE;
$params = $incourse ? ['contextid' => $context->id] : array_filter(['courseid' => $courseid, 'cohortid' => $cohortid]);
page::consent_setup($context, '/local/oksigeniafamily/consent_edit.php', $params, $title);
$listurl = new moodle_url('/local/oksigeniafamily/consents.php', $incourse ? ['contextid' => $context->id] : []);
$now = time();

// Inside a course the page header is the course's: the title of the page goes under it. On the site it is the header.
$heading = $incourse ? $OUTPUT->heading($title) : '';
$choices = consent_school::choices($context, $courseid, $cohortid);
$count = fn($n) => get_string($n == 1 ? 'consent:studentsone' : 'consent:students', $component, $n);

if (!$choices) {
    // On the site, with nothing chosen yet: whose families. Only the courses and cohorts this person can ask.
    $chips = function (array $found, string $param) use ($count) {
        return array_map(fn($item) => [
            'name' => $item['name'],
            'countlabel' => $count($item['count']),
            'url' => (new moodle_url('/local/oksigeniafamily/consent_edit.php', [$param => $item['id']]))->out(false),
        ], $found['items']);
    };
    $courses = consent_school::courses($q, $now);
    $cohorts = consent_school::cohorts($q);
    echo $OUTPUT->header();
    echo $OUTPUT->render_from_template('local_oksigeniafamily/consent_ask_pick', [
        'action' => (new moodle_url('/local/oksigeniafamily/consent_edit.php'))->out(false),
        'q' => $q,
        'coursestitle' => get_string($q === '' ? 'consent:pick:running' : 'consent:pick:courses', $component),
        'courses' => $chips($courses, 'courseid'),
        'hascourses' => (bool) $courses['items'],
        'cohorts' => $chips($cohorts, 'cohortid'),
        'hascohorts' => (bool) $cohorts['items'],
        'more' => $courses['more'] || $cohorts['more'],
        'none' => !$courses['items'] && !$cohorts['items'],
        'nonetext' => get_string($q === '' ? 'consent:pick:nothing' : 'consent:pick:none', $component),
    ]);
    echo $OUTPUT->footer();
    die;
}

$input = [
    'who' => $choices['options'][0]['value'],
    'title' => '',
    'message' => '',
    'until' => (string) consent_school::UNTILS[0],
    'untilday' => '',
    'needall' => 0,
    'keepdays' => consent_school::keep_default(),
    'draftitemid' => 0,
];
$errors = [];
if (data_submitted()) {
    require_sesskey();
    $input = [
        'who' => optional_param('who', '', PARAM_NOTAGS),
        'title' => optional_param('title', '', PARAM_TEXT),
        'message' => optional_param('message', '', PARAM_RAW),
        'until' => optional_param('until', '', PARAM_ALPHANUM),
        'untilday' => optional_param('untilday', '', PARAM_NOTAGS),
        'needall' => optional_param('needall', 0, PARAM_BOOL),
        'keepdays' => optional_param('keepdays', 0, PARAM_INT),
        'draftitemid' => optional_param('draftitemid', 0, PARAM_INT),
    ];
    // The circular comes as a plain file field: it is checked and kept in the user's draft area before anything else,
    // under a clean name, so that it is not lost if the letter has to be fixed.
    $upload = $_FILES['attachment'] ?? null;
    $uploaderror = is_array($upload) && is_int($upload['error'] ?? null) ? $upload['error'] : UPLOAD_ERR_NO_FILE;
    if ($uploaderror === UPLOAD_ERR_INI_SIZE || $uploaderror === UPLOAD_ERR_FORM_SIZE) {
        $max = get_user_max_upload_file_size($context, $CFG->maxbytes);
        $errors['attachment'] = get_string('consent:attach:size', $component, display_size($max));
    } else if ($uploaderror !== UPLOAD_ERR_NO_FILE && $uploaderror !== UPLOAD_ERR_OK) {
        $errors['attachment'] = file_get_upload_error($uploaderror);
    } else if ($uploaderror === UPLOAD_ERR_OK) {
        try {
            if (!is_uploaded_file((string) $upload['tmp_name'])) {
                throw new moodle_exception('consent:attach:type', $component);
            }
            $taken = consent_school::take_file(
                (string) $upload['tmp_name'],
                (string) ($upload['name'] ?? ''),
                $context,
                (int) $input['draftitemid']
            );
            $input['draftitemid'] = $taken['draftitemid'];
        } catch (moodle_exception $e) {
            $errors['attachment'] = $e->getMessage();
        }
    }
    [$data, $more] = consent_school::read($context, $choices, $input, $now);
    $errors += $more;
    if (!$errors) {
        $requestid = consent_school::send($context, $data, (int) $USER->id);
        redirect(
            new moodle_url('/local/oksigeniafamily/consent_report.php', ['id' => $requestid]),
            get_string('consent:sent', $component, count($data['students'])),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

// The letter: who, what and the circular; under it, its terms; at the foot, the send, always in sight.
$sendlabel = fn($n) => $n == 1 ? get_string('consent:sendtoone', $component) : get_string('consent:sendto', $component, $n);
$values = array_column($choices['options'], 'value');
$who = in_array($input['who'], $values, true) ? $input['who'] : $values[0];
$fixed = $choices['kind'] === 'cohort';
$options = [];
$send = '';
foreach ($choices['options'] as $i => $option) {
    $checked = $option['value'] === $who;
    $options[] = [
        'id' => 'okf-ask-who-' . $i,
        'value' => $option['value'],
        'label' => $option['label'],
        'countlabel' => $count($option['count']),
        'sendlabel' => $sendlabel($option['count']),
        'checked' => $checked,
    ];
    if ($checked) {
        $send = $sendlabel($option['count']);
    }
}

$untils = [];
$until = in_array($input['until'], array_merge(array_map('strval', consent_school::UNTILS), ['day']), true)
    ? $input['until'] : (string) consent_school::UNTILS[0];
$dayformat = get_string('strftimedateshort', 'langconfig');
foreach (consent_school::deadline_options($now) as $option) {
    $untils[] = [
        'value' => $option['days'],
        'label' => get_string('consent:until:' . ($option['days'] == 7 ? 'week' : 'twoweeks'), $component),
        'small' => get_string('consent:until:on', $component, userdate($option['deadline'], $dayformat)),
        'checked' => $until === (string) $option['days'],
    ];
}
$timezone = core_date::get_user_timezone_object();
$today = (new DateTime('@' . $now))->setTimezone($timezone);
$untilday = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $input['untilday'])
    ? $input['untilday'] : (new DateTime('@' . ($now + 7 * DAYSECS)))->setTimezone($timezone)->format('Y-m-d');

$keeps = [];
$keepdays = in_array((int) $input['keepdays'], consent_school::keep_options(), true)
    ? (int) $input['keepdays'] : consent_school::keep_default();
$manager = get_string_manager();
$keepinline = '';
foreach (consent_school::keep_options() as $days) {
    $known = $manager->string_exists('consent:keep:' . $days, $component);
    $inline = $known ? get_string('consent:keep:' . $days . ':in', $component) : get_string('consent:keep:days', $component, $days);
    $keeps[] = [
        'days' => $days,
        'label' => $known ? get_string('consent:keep:' . $days, $component) : get_string('consent:keep:days', $component, $days),
        'inline' => $inline,
        'checked' => $days === $keepdays,
    ];
    if ($days === $keepdays) {
        $keepinline = $inline;
    }
}

$draft = consent_school::draft_file((int) $input['draftitemid']);
$attachhint = $draft
    ? get_string('consent:attach:kept', $component, $draft->get_filename())
    : get_string('consent:attach:hint', $component);

echo $OUTPUT->header();
echo $heading;
if ($errors) {
    echo $OUTPUT->notification(implode('<br>', array_map('s', array_unique($errors))), \core\output\notification::NOTIFY_ERROR);
}
echo $OUTPUT->render_from_template('local_oksigeniafamily/consent_ask', [
    'action' => $PAGE->url->out(false),
    'sesskey' => sesskey(),
    'draftitemid' => $draft ? (int) $input['draftitemid'] : 0,
    'fixed' => $fixed ? $options[0] : null,
    'options' => $fixed ? [] : $options,
    'changeurl' => $incourse ? '' : (new moodle_url('/local/oksigeniafamily/consent_edit.php'))->out(false),
    'title' => trim((string) $input['title']),
    'invalidtitle' => isset($errors['title']),
    'message' => (string) $input['message'],
    'accept' => implode(',', file_get_typegroup('extension', consent_school::FILETYPES)),
    'attachhint' => $attachhint,
    'untils' => $untils,
    'daychecked' => $until === 'day',
    'today' => $today->format('Y-m-d'),
    'untilday' => $untilday,
    'needone' => empty($input['needall']),
    'needall' => !empty($input['needall']),
    'keeps' => $keeps,
    'keepopen' => $keepdays !== consent_school::keep_default() || isset($errors['keepdays']),
    'keepline' => get_string('consent:keep:line', $component, s($keepinline)),
    'sendlabel' => $send,
    'cancelurl' => $listurl->out(false),
]);
// The number on the button follows the chip chosen, and the line of how long answers are kept follows its choice.
$PAGE->requires->js_amd_inline("
    require([], function() {
        var form = document.querySelector('form[data-okf-ask]');
        if (!form) {
            return;
        }
        form.addEventListener('change', function(e) {
            var field = e.target;
            var send = form.querySelector('[data-okf-sendlabel]');
            var keep = form.querySelector('.okf-ask-keep summary b');
            var day = form.querySelector('input[name=untilday]');
            if (field.name === 'who' && send && field.getAttribute('data-okf-send')) {
                send.textContent = field.getAttribute('data-okf-send');
            }
            if (field.name === 'keepdays' && keep && field.getAttribute('data-okf-keep')) {
                keep.textContent = field.getAttribute('data-okf-keep');
            }
            if (field.name === 'until' && day) {
                day.required = field.value === 'day';
            }
        });
    });
");
page::once_forms();
echo $OUTPUT->footer();
