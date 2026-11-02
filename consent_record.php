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
 * Write down an answer that came on paper, for one student of a request for consent.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniafamily\api;
use local_oksigeniafamily\form\consent_record_form;
use local_oksigeniafamily\local\consent;
use local_oksigeniafamily\local\page;

$id = required_param('id', PARAM_INT);
$childuserid = required_param('child', PARAM_INT);
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
echo $OUTPUT->heading($title);
echo html_writer::tag('p', format_string($request->title, true, ['context' => context_system::instance()]), ['class' => 'lead']);
if ($error !== '') {
    echo $OUTPUT->notification($error, \core\output\notification::NOTIFY_ERROR);
}
$form->display();
echo $OUTPUT->footer();
