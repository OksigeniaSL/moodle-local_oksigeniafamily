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
 * An adult by hand: a new family with its first adult, one more for a family, or a change in their details.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_oksigeniafamily\api;
use local_oksigeniafamily\form\adult_form;
use local_oksigeniafamily\local\import\importer;
use local_oksigeniafamily\local\page;
use local_oksigeniafamily\local\phone;

$component = 'local_oksigeniafamily';
$id = optional_param('id', 0, PARAM_INT);
$familyid = optional_param('familyid', 0, PARAM_INT);
$params = array_filter(['id' => $id, 'familyid' => $familyid]);

admin_externalpage_setup(
    'local_oksigeniafamily_families',
    '',
    $params,
    '/local/oksigeniafamily/adult.php',
    ['nosearch' => true]
);

$adult = $id ? $DB->get_record('local_oksigeniafamily_adult', ['id' => $id], '*', MUST_EXIST) : null;
$familyid = $adult ? (int) $adult->familyid : $familyid;
$family = $familyid ? $DB->get_record('local_oksigeniafamily_family', ['id' => $familyid], '*', MUST_EXIST) : null;
$back = $family
    ? new moodle_url('/local/oksigeniafamily/family.php', ['id' => $familyid])
    : new moodle_url('/local/oksigeniafamily/index.php');
if ($family && $family->status !== api::ACTIVE) {
    redirect($back);
}

$title = get_string($adult ? 'adult:edit' : ($family ? 'adult:add' : 'adult:new'), $component);
page::dress($title);
$PAGE->navbar->add($title);

$form = new adult_form($PAGE->url);
if ($adult) {
    $data = (array) $adult;
    $data['username'] = $adult->userid ? (string) $DB->get_field('user', 'username', ['id' => $adult->userid]) : '';
    $form->set_data($data);
}
if ($form->is_cancelled()) {
    redirect($back);
}
if ($data = $form->get_data()) {
    $details = [
        'firstname' => $data->firstname,
        'lastname' => $data->lastname,
        'email' => $data->email,
        'phone' => phone::normalise((string) $data->phone, importer::phone_prefix()),
    ];
    $username = core_text::strtolower(trim((string) $data->username));
    $userid = $username === '' ? null : (int) $DB->get_field('user', 'id', ['username' => $username, 'deleted' => 0]);
    if ($adult) {
        api::update_adult((int) $adult->id, $details + ['phoneverified' => true]);
        if ((int) $adult->userid !== (int) $userid) {
            api::set_adult_user((int) $adult->id, $userid);
        }
        redirect($back);
    }
    $isnew = !$family;
    $familyid = $familyid ?: api::create_family('manual');
    api::add_adult($familyid, $details + ['userid' => $userid, 'phoneverified' => true]);
    // A new family goes on with its first child.
    redirect($isnew
        ? new moodle_url('/local/oksigeniafamily/child.php', ['familyid' => $familyid])
        : new moodle_url('/local/oksigeniafamily/family.php', ['id' => $familyid]));
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_oksigeniafamily/adult', [
    'nav' => page::nav('families'),
    'backurl' => $back->out(false),
    'form' => $form->render(),
    'isnew' => !$family,
    'prefix' => importer::phone_prefix(),
]);
echo $OUTPUT->footer();
