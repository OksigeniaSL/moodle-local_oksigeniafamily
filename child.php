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
 * A child by hand: a student found by name, or someone without an account, by their key in the school's data.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_oksigeniafamily\api;
use local_oksigeniafamily\local\page;
use local_oksigeniafamily\local\students;

$component = 'local_oksigeniafamily';
$familyid = required_param('familyid', PARAM_INT);
$q = optional_param('q', '', PARAM_RAW_TRIMMED);
$url = new moodle_url('/local/oksigeniafamily/child.php', ['familyid' => $familyid]);
$back = new moodle_url('/local/oksigeniafamily/family.php', ['id' => $familyid]);

admin_externalpage_setup(
    'local_oksigeniafamily_families',
    '',
    array_filter(['familyid' => $familyid, 'q' => $q]),
    '/local/oksigeniafamily/child.php',
    ['nosearch' => true]
);

$family = $DB->get_record('local_oksigeniafamily_family', ['id' => $familyid], '*', MUST_EXIST);
if ($family->status !== api::ACTIVE) {
    redirect($back);
}

$adduser = optional_param('adduser', 0, PARAM_INT);
$addkey = trim(optional_param('addkey', '', PARAM_NOTAGS));
if ($adduser || $addkey !== '') {
    require_sesskey();
    if ($adduser) {
        $DB->get_record('user', ['id' => $adduser, 'deleted' => 0], 'id', MUST_EXIST);
        api::add_child($familyid, $adduser);
    } else {
        api::add_child($familyid, null, strpos($addkey, ':') === false ? 'manual:' . $addkey : $addkey);
    }
    redirect($back);
}

$title = get_string('child:add', $component);
page::dress($title);
$PAGE->navbar->add($title);

$already = array_map('intval', $DB->get_fieldset_select(
    'local_oksigeniafamily_child',
    'userid',
    'familyid = :familyid AND status = :active AND userid IS NOT NULL',
    ['familyid' => $familyid, 'active' => api::ACTIVE]
));
$results = [];
if ($q !== '') {
    foreach (students::search($q) as $user) {
        $results[] = [
            'id' => $user->id,
            'name' => fullname($user),
            'who' => $user->idnumber !== '' ? $user->idnumber : $user->username,
            'already' => in_array((int) $user->id, $already, true),
        ];
    }
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_oksigeniafamily/child', [
    'nav' => page::nav('families'),
    'backurl' => $back->out(false),
    'action' => $url->out(false),
    'familyid' => $familyid,
    'sesskey' => sesskey(),
    'q' => $q,
    'searched' => $q !== '',
    'results' => $results,
    'hasresults' => !empty($results),
]);
echo $OUTPUT->footer();
