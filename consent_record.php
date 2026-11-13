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
 * Write down an answer that came on paper, for one student of a request for consent. The report posts here straight
 * from its row (quick, with the adult who signs and the answer); this page's own form is for someone who is not in the
 * family records, or to add a comment.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniafamily\api;
use local_oksigeniafamily\form\consent_record_form;
use local_oksigeniafamily\local\consent;
use local_oksigeniafamily\local\consent_school;
use local_oksigeniafamily\local\page;

$id = required_param('id', PARAM_INT);
$childuserid = required_param('child', PARAM_INT);
$quick = optional_param('quick', 0, PARAM_BOOL);
$component = 'local_oksigeniafamily';

$request = consent::get($id);
if (!$request || !in_array($childuserid, consent::students($id), true)) {
    throw new moodle_exception('invalidrecord');
}
$context = context::instance_by_id((int) $request->contextid);
$title = get_string('consent:record:title', $component);
page::consent_setup($context, '/local/oksigeniafamily/consent_record.php', ['id' => $id, 'child' => $childuserid], $title);
$back = new moodle_url('/local/oksigeniafamily/consent_report.php', ['id' => $id]);
$child = core_user::get_user($childuserid, '*', MUST_EXIST);

// Straight from the row of the report: one of the adults of the record, and yes or no. Only by POST, with the session
// key; the page set-up above has already checked the capability, and consent_school::paper() checks the rest.
if ($quick && data_submitted()) {
    require_sesskey();
    $show = optional_param('show', '', PARAM_ALPHA);
    $return = new moodle_url($back, array_filter(['show' => $show]));
    $return->set_anchor('okf-row-' . $childuserid);
    try {
        consent_school::paper(
            $request,
            $childuserid,
            optional_param('adultid', 0, PARAM_INT),
            optional_param('answer', '', PARAM_ALPHA),
            (int) $USER->id
        );
        redirect($return, get_string('consent:record:done', $component), null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (moodle_exception $e) {
        redirect($return, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

$adults = [];
foreach (api::adults_of_child($childuserid) as $adult) {
    $adults[(int) $adult->id] = consent::adult_name($adult);
}
$form = new consent_record_form($PAGE->url, ['adults' => $adults, 'student' => fullname($child)]);

$error = '';
if ($form->is_cancelled()) {
    redirect($back);
} else if ($data = $form->get_data()) {
    $adultid = isset($adults[(int) $data->adultid]) ? (int) $data->adultid : 0;
    try {
        consent::answer($id, $childuserid, $adultid, $data->answer, 'school', [
            'userid' => (int) $USER->id,
            'name' => $adultid ? '' : $data->name,
            'comment' => $data->comment,
        ]);
        redirect($back, get_string('consent:record:done', $component), null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (moodle_exception $e) {
        $error = $e->getMessage();
    }
}

echo $OUTPUT->header();
if ($context->contextlevel == CONTEXT_COURSE) {
    echo $OUTPUT->heading($title);
}
echo html_writer::tag('p', format_string($request->title, true, ['context' => context_system::instance()]), ['class' => 'lead']);
if ($error !== '') {
    echo $OUTPUT->notification($error, \core\output\notification::NOTIFY_ERROR);
}
$form->display();
echo $OUTPUT->footer();
