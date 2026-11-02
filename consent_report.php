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
 * A request for consent as the school sees it: who said yes, who said no and who is missing, with the reminder, the
 * new students of its course, the downloads and calling it off. What changes something goes by POST with the session
 * key; calling it off asks first.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniafamily\local\consent;
use local_oksigeniafamily\local\consent_export;
use local_oksigeniafamily\local\consent_view;
use local_oksigeniafamily\local\page;
use local_oksigeniafamily\local\students;
use local_oksigeniafamily\task\consent_send;

$id = required_param('id', PARAM_INT);
$do = optional_param('do', '', PARAM_ALPHA);
$ask = optional_param('ask', '', PARAM_ALPHA);
$download = optional_param('download', '', PARAM_ALPHA);
$component = 'local_oksigeniafamily';

$request = consent::get($id);
if (!$request) {
    throw new moodle_exception('invalidrecord');
}
$context = context::instance_by_id((int) $request->contextid);
$title = format_string($request->title, true, ['context' => context_system::instance()]);
page::consent_setup($context, '/local/oksigeniafamily/consent_report.php', ['id' => $id], $title);
$url = new moodle_url('/local/oksigeniafamily/consent_report.php', ['id' => $id]);
$params = $context->contextlevel == CONTEXT_SYSTEM ? [] : ['contextid' => $context->id];
$now = time();
$open = consent::is_open($request, $now);

if (in_array($download, ['csv', 'pdf'], true)) {
    consent_export::download($request, $download);
}

if ($do !== '') {
    require_sesskey();
    if (!$open) {
        redirect($url);
    }
    if ($do === 'remind') {
        consent_send::queue($id, true);
        redirect($url, get_string('consent:reminded', $component), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    if ($do === 'addnew' && $request->scope !== '') {
        $found = students::of_scope($request->scope, (int) $request->scopeid);
        $added = consent::add_students($id, $found ? array_keys($found['users']) : []);
        if ($added) {
            consent_send::queue($id, false, $added);
        }
        $text = $added ? get_string('consent:added', $component, count($added)) : get_string('consent:added:none', $component);
        redirect($url, $text, null, \core\output\notification::NOTIFY_SUCCESS);
    }
    if ($do === 'cancel') {
        consent::cancel($id);
        redirect($url, get_string('consent:cancelled:done', $component), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    redirect($url);
}

echo $OUTPUT->header();
echo $OUTPUT->heading($title);
if ($ask === 'cancel' && $open) {
    echo $OUTPUT->confirm(
        get_string('consent:cancel:question', $component, $title),
        new single_button(
            new moodle_url($url, ['do' => 'cancel', 'sesskey' => sesskey()]),
            get_string('consent:cancel', $component)
        ),
        new single_button($url, get_string('consent:cancel:keep', $component), 'get')
    );
} else {
    $report = consent_view::report($request, $now);
    echo $OUTPUT->render_from_template('local_oksigeniafamily/consent_report', $report + [
        'listurl' => (new moodle_url('/local/oksigeniafamily/consents.php', $params))->out(false),
        'actionurl' => $url->out(false),
        'sesskey' => sesskey(),
        'canremind' => $open && $report['remindable'] > 0,
        'remindlabel' => get_string('consent:remind', $component, $report['remindable']),
        'canaddnew' => $open && $request->scope !== '',
        'cancancel' => $open,
        'csvurl' => (new moodle_url($url, ['download' => 'csv']))->out(false),
        'pdfurl' => (new moodle_url($url, ['download' => 'pdf']))->out(false),
    ]);
    page::once_forms();
}
echo $OUTPUT->footer();
