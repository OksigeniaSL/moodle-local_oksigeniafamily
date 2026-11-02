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
 * A new request for consent: what it is, to whom and until when. It goes out at once, by the tasks of Moodle.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniafamily\form\consent_form;
use local_oksigeniafamily\local\consent;
use local_oksigeniafamily\local\page;
use local_oksigeniafamily\local\students;
use local_oksigeniafamily\task\consent_send;

$contextid = optional_param('contextid', 0, PARAM_INT);
$context = $contextid ? context::instance_by_id($contextid) : context_system::instance();
$component = 'local_oksigeniafamily';
$title = get_string('consent:new', $component);
$params = $context->contextlevel == CONTEXT_SYSTEM ? [] : ['contextid' => $context->id];
page::consent_setup($context, '/local/oksigeniafamily/consent_edit.php', $params, $title);
$listurl = new moodle_url('/local/oksigeniafamily/consents.php', $params);

$groups = [];
if ($context->contextlevel == CONTEXT_COURSE) {
    foreach (groups_get_all_groups($context->instanceid) as $group) {
        $groups[(int) $group->id] = format_string($group->name, true, ['context' => $context]);
    }
}
$form = new consent_form($PAGE->url, ['context' => $context, 'groups' => $groups]);

$error = '';
if ($form->is_cancelled()) {
    redirect($listurl);
} else if ($data = $form->get_data()) {
    [$scope, $scopeid] = consent_form::scope_of($data);
    // In a course, only the course itself or one of its groups.
    if ($context->contextlevel == CONTEXT_COURSE) {
        $mine = ($scope === 'course' && $scopeid === (int) $context->instanceid)
            || ($scope === 'group' && isset($groups[$scopeid]));
        if (!$mine) {
            throw new moodle_exception('invalidrecord');
        }
    }
    $found = students::of_scope($scope, $scopeid);
    $students = $found ? array_keys($found['users']) : [];
    if (!$students) {
        $error = get_string('consent:error:nostudents', $component);
    } else {
        $requestid = consent::create($context, [
            'title' => $data->title,
            'message' => $data->message['text'] ?? '',
            'messageformat' => $data->message['format'] ?? FORMAT_HTML,
            'deadline' => consent::end_of_day((int) $data->deadline),
            'scope' => $scope,
            'scopeid' => $scopeid,
            'needall' => $data->needall,
            'keepdays' => $data->keepdays,
        ], $students, (int) $USER->id);
        file_save_draft_area_files(
            $data->attachment,
            context_system::instance()->id,
            $component,
            'consent',
            $requestid,
            ['subdirs' => 0, 'maxfiles' => 1]
        );
        consent_send::queue($requestid);
        redirect(
            new moodle_url('/local/oksigeniafamily/consent_report.php', ['id' => $requestid]),
            get_string('consent:sent', $component, count($students)),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading($title);
echo html_writer::tag('p', get_string('consent:new:intro', $component), ['class' => 'text-muted']);
if ($error !== '') {
    echo $OUTPUT->notification($error, \core\output\notification::NOTIFY_ERROR);
}
$form->display();
echo $OUTPUT->footer();
