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
 * A family: who is in it and how it stands, and what the school can do about it. Everything that changes something goes
 * by POST with the session key; what cannot be undone asks first.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_oksigeniafamily\api;
use local_oksigeniafamily\local\families;
use local_oksigeniafamily\local\page;
use local_oksigeniafamily\local\students;

$component = 'local_oksigeniafamily';
$id = required_param('id', PARAM_INT);
$ask = optional_param('ask', '', PARAM_ALPHA);
$do = optional_param('do', '', PARAM_ALPHA);
$adultid = optional_param('adultid', 0, PARAM_INT);
$childid = optional_param('childid', 0, PARAM_INT);
$url = new moodle_url('/local/oksigeniafamily/family.php', ['id' => $id]);

admin_externalpage_setup(
    'local_oksigeniafamily_families',
    '',
    ['id' => $id],
    '/local/oksigeniafamily/family.php',
    ['nosearch' => true]
);

$family = $DB->get_record('local_oksigeniafamily_family', ['id' => $id], '*', MUST_EXIST);
$closed = $family->status !== api::ACTIVE;
$people = families::people([$id]);
$adults = $people['adults'][$id] ?? [];
$children = $people['children'][$id] ?? [];
$adult = $adults[$adultid] ?? null;
$child = $children[$childid] ?? null;
$now = time();
$state = students::state(array_filter(array_map(fn($one) => (int) $one->userid, $children)), $now);

// What changes something. A family that is closed stays as it is.
if ($do !== '' && !$closed) {
    require_sesskey();
    if ($do === 'removeadult' && $adult) {
        api::remove_adult((int) $adult->id);
        if (optional_param('revoke', 0, PARAM_BOOL)) {
            foreach (array_keys($state) as $userid) {
                api::revoke_claim_codes($userid);
            }
        }
        \core\notification::success(get_string('family:removedadult', $component, families::adult_name($adult)));
    } else if ($do === 'removechild' && $child) {
        api::unlink_child((int) $child->id, 'manual');
        $name = families::child_name($child, $people['users']);
        \core\notification::success(get_string('family:removedchild', $component, $name));
    } else if ($do === 'revoke' && $child && !empty($child->userid)) {
        api::revoke_claim_codes((int) $child->userid);
        \core\notification::success(get_string('family:revoked', $component, families::child_name($child, $people['users'])));
    }
    redirect($url);
}

$present = array_filter($adults, fn($one) => $one->status === api::ACTIVE || $closed);
$names = array_map(fn($one) => families::adult_name($one), $present);
$title = $names ? implode(' · ', $names) : get_string('family:noadults', $component);
page::dress($title);
$PAGE->navbar->add($title);

$action = fn(array $extra) => (new moodle_url($url, $extra))->out(false);

// Each question that comes before something that cannot be undone.
$question = null;
if (!$closed && $ask === 'removeadult' && $adult && $adult->status === api::ACTIVE) {
    $name = families::adult_name($adult);
    $withcode = array_filter($state, fn($one) => $one['expires'] > 0);
    $hidden = [['name' => 'adultid', 'value' => $adult->id], ['name' => 'do', 'value' => 'removeadult']];
    $question = [
        'title' => get_string('family:ask:removeadult', $component, $name),
        'text' => get_string('family:ask:removeadult_text', $component),
        'buttons' => [],
    ];
    if ($withcode) {
        $question['text2'] = get_string('family:ask:withcode', $component);
        $question['buttons'][] = [
            'label' => get_string('family:removeandrevoke', $component),
            'danger' => true,
            'hidden' => array_merge($hidden, [['name' => 'revoke', 'value' => 1]]),
        ];
    }
    $question['buttons'][] = [
        'label' => get_string('family:removeonly', $component),
        'danger' => !$withcode,
        'hidden' => $hidden,
    ];
} else if (!$closed && $ask === 'removechild' && $child && $child->status === api::ACTIVE) {
    $question = [
        'title' => get_string('family:ask:removechild', $component, families::child_name($child, $people['users'])),
        'text' => get_string('family:ask:removechild_text', $component),
        'buttons' => [[
            'label' => get_string('family:removeonly', $component),
            'danger' => true,
            'hidden' => [['name' => 'childid', 'value' => $child->id], ['name' => 'do', 'value' => 'removechild']],
        ]],
    ];
} else if (!$closed && $ask === 'revoke' && $child && !empty($child->userid)) {
    $question = [
        'title' => get_string('family:ask:revoke', $component, families::child_name($child, $people['users'])),
        'text' => get_string('family:ask:revoke_text', $component),
        'buttons' => [[
            'label' => get_string('family:revoke', $component),
            'danger' => true,
            'hidden' => [['name' => 'childid', 'value' => $child->id], ['name' => 'do', 'value' => 'revoke']],
        ]],
    ];
}

$adultrows = [];
$gone = [];
foreach ($adults as $one) {
    $name = families::adult_name($one);
    if ($one->status !== api::ACTIVE && !$closed) {
        $gone[] = ['name' => $name, 'when' => page::date((int) $one->timemodified)];
        continue;
    }
    $account = !empty($one->userid) ? ($people['users'][$one->userid] ?? null) : null;
    $adultrows[] = [
        'name' => $name,
        'phone' => $one->phone,
        'email' => $one->email,
        'account' => $account ? $account->username : '',
        'accounturl' => $account ? (new moodle_url('/user/profile.php', ['id' => $account->id]))->out(false) : '',
        'editurl' => $closed ? '' : (new moodle_url('/local/oksigeniafamily/adult.php', ['id' => $one->id]))->out(false),
        'removeurl' => $closed ? '' : $action(['ask' => 'removeadult', 'adultid' => $one->id]),
    ];
}
$childrows = [];
foreach ($children as $one) {
    $name = families::child_name($one, $people['users']);
    if ($one->status !== api::ACTIVE && !$closed) {
        $gone[] = ['name' => $name, 'when' => page::date((int) $one->timeleft)];
        continue;
    }
    $userid = (int) $one->userid;
    $code = $state[$userid] ?? ['expires' => 0, 'claims' => 0];
    $childrows[] = [
        'name' => $name,
        'url' => $userid ? (new moodle_url('/user/profile.php', ['id' => $userid]))->out(false) : '',
        'key' => $userid ? $one->sourcekey : '',
        'noaccount' => !$userid,
        'codeline' => !$userid ? '' : ($code['expires'] > 0 ? get_string('code:until', $component, (object) [
            'date' => page::date($code['expires']), 'claims' => $code['claims'],
        ]) : get_string('codes:nocode', $component)),
        'letterurl' => !$closed && $userid
            ? (new moodle_url('/local/oksigeniafamily/codes.php', ['scope' => 'user', 'id' => $userid]))->out(false)
            : '',
        'revokeurl' => !$closed && $code['expires'] > 0 ? $action(['ask' => 'revoke', 'childid' => $one->id]) : '',
        'removeurl' => $closed ? '' : $action(['ask' => 'removechild', 'childid' => $one->id]),
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_oksigeniafamily/family', [
    'nav' => page::nav('families'),
    'backurl' => (new moodle_url('/local/oksigeniafamily/index.php'))->out(false),
    'closed' => $closed,
    'source' => page::source($family->source),
    'familykey' => $family->sourcekey,
    'since' => page::date((int) $family->timecreated),
    'closedsince' => $closed && $family->timeclosed ? page::date((int) $family->timeclosed) : '',
    'question' => $question,
    'action' => $url->out(false),
    'cancelurl' => $url->out(false),
    'sesskey' => sesskey(),
    'adults' => $adultrows,
    'hasadults' => !empty($adultrows),
    'children' => $childrows,
    'haschildren' => !empty($childrows),
    'addadulturl' => $closed ? '' : (new moodle_url('/local/oksigeniafamily/adult.php', ['familyid' => $id]))->out(false),
    'addchildurl' => $closed ? '' : (new moodle_url('/local/oksigeniafamily/child.php', ['familyid' => $id]))->out(false),
    'gone' => $gone,
    'hasgone' => !empty($gone),
]);
echo $OUTPUT->footer();
