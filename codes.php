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
 * The codes and letters: choose a student, a group, a course or a cohort, see how they stand, and issue the letters or
 * withdraw the codes. A code is only kept as its hash, so the page with the letters is the only place it ever exists in
 * the clear: it answers to a one-time POST, it is not cached, and asking again gives no letters.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_oksigeniafamily\api;
use local_oksigeniafamily\local\letters;
use local_oksigeniafamily\local\page;
use local_oksigeniafamily\local\students;

$component = 'local_oksigeniafamily';
$scope = optional_param('scope', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$q = optional_param('q', '', PARAM_RAW_TRIMMED);
$mode = optional_param('mode', '', PARAM_ALPHA);
$revoke = optional_param('revoke', 0, PARAM_INT);
$scripturl = new moodle_url('/local/oksigeniafamily/codes.php');
$params = $scope !== '' ? ['scope' => $scope, 'id' => $id] : array_filter(['q' => $q]);

// The letters have a page of their own, without the frame of the site, as they are for paper.
$options = ['nosearch' => true] + ($mode !== '' ? ['pagelayout' => 'embedded'] : []);
admin_externalpage_setup('local_oksigeniafamily_families', '', $params, $scripturl, $options);

$who = $scope !== '' ? students::of_scope($scope, $id) : null;
$now = time();

if ($who === null) {
    // The choice: one search for everything, and the results by kind.
    page::dress(get_string('codes', $component));
    $found = ['courses' => [], 'groups' => []];
    $people = [];
    if ($q !== '') {
        $found = students::search_courses_and_groups($q);
        foreach (students::search($q) as $user) {
            $people[] = [
                'name' => fullname($user),
                'who' => $user->idnumber !== '' ? $user->idnumber : $user->username,
                'url' => (new moodle_url($scripturl, ['scope' => 'user', 'id' => $user->id]))->out(false),
            ];
        }
    }
    $link = fn(string $kind, $one, string $name, string $who) => [
        'name' => $name,
        'who' => $who,
        'url' => (new moodle_url($scripturl, ['scope' => $kind, 'id' => $one->id]))->out(false),
    ];
    $places = [];
    foreach ($found['groups'] as $group) {
        $places[] = $link('group', $group, format_string($group->name), format_string($group->fullname));
    }
    foreach ($found['courses'] as $course) {
        $places[] = $link('course', $course, format_string($course->fullname), get_string('codes:wholecourse', $component));
    }
    $cohorts = [];
    foreach (students::cohorts($q) as $cohort) {
        $members = get_string('codes:members', $component, $cohort->members);
        $cohorts[] = $link('cohort', $cohort, format_string($cohort->name), $members);
    }
    echo $OUTPUT->header();
    echo $OUTPUT->render_from_template('local_oksigeniafamily/codes', [
        'nav' => page::nav('codes'),
        'action' => $scripturl->out(false),
        'q' => $q,
        'searched' => $q !== '',
        'people' => $people,
        'haspeople' => !empty($people),
        'places' => $places,
        'hasplaces' => !empty($places),
        'cohorts' => $cohorts,
        'hascohorts' => !empty($cohorts),
    ]);
    echo $OUTPUT->footer();
    exit;
}

$here = new moodle_url($scripturl, ['scope' => $scope, 'id' => $id]);
$single = $scope === 'user';
$first = $who['users'] ? reset($who['users']) : null;
$name = $single ? fullname($first) : $who['name'];

// The letters: the only moment a code exists in the clear.
if ($mode !== '') {
    require_sesskey();
    $ticket = required_param('ticket', PARAM_ALPHANUM);
    if (empty($SESSION->local_oksigeniafamily_tickets[$ticket])) {
        // A reload or a second click: new codes now would leave the letters of the first answer useless.
        \core\notification::warning(get_string('codes:alreadyissued', $component));
        redirect($here);
    }
    unset($SESSION->local_oksigeniafamily_tickets[$ticket]);
    $mode = in_array($mode, [letters::PENDING, letters::NOCODE, letters::REPLACE], true) ? $mode : letters::PENDING;
    $issued = letters::issue($who['users'], $mode, $now);
    if (!$issued) {
        \core\notification::info(get_string('codes:nobody', $component));
        redirect($here);
    }
    $PAGE->set_cacheable(false);
    $PAGE->set_title(get_string('letters', $component));
    $PAGE->requires->js_amd_inline("
        require([], function() {
            var printed = false;
            var button = document.querySelector('[data-okf-print]');
            if (button) {
                button.addEventListener('click', function() {
                    window.print();
                });
            }
            window.addEventListener('afterprint', function() {
                printed = true;
            });
            window.addEventListener('beforeunload', function(event) {
                if (!printed) {
                    event.preventDefault();
                    event.returnValue = '';
                }
            });
        });
    ");
    echo $OUTPUT->header();
    echo $OUTPUT->render_from_template('local_oksigeniafamily/letters', [
        'ready' => get_string('letters:ready', $component, count($issued)),
        'backurl' => $here->out(false),
        'letters' => letters::context($issued, $single ? '' : $name),
        'logo' => (string) $OUTPUT->get_logo_url(null, 100),
        'site' => format_string(get_site()->fullname),
    ]);
    echo $OUTPUT->footer();
    exit;
}

// Withdrawing the codes in use of all of them.
$state = students::state(array_keys($who['users']), $now);
$inuse = count(array_filter($state, fn($one) => $one['expires'] > 0));
if ($revoke === 2) {
    require_sesskey();
    foreach (array_keys($who['users']) as $userid) {
        api::revoke_claim_codes((int) $userid);
    }
    \core\notification::success(get_string('codes:revoked', $component, $inuse));
    redirect($here);
}

page::dress($name);
$PAGE->navbar->add($name);

// A ticket for this one issue, so a reload cannot silently replace letters already printed.
$ticket = random_string(24);
$tickets = array_slice($SESSION->local_oksigeniafamily_tickets ?? [], -20, null, true);
$tickets[$ticket] = true;
$SESSION->local_oksigeniafamily_tickets = $tickets;

page::once_forms();

$rows = [];
foreach ($who['users'] as $userid => $user) {
    if (count($rows) >= 300) {
        break;
    }
    $one = $state[(int) $userid];
    $rows[] = [
        'name' => fullname($user),
        'who' => $user->idnumber !== '' ? $user->idnumber : $user->username,
        'hasfamily' => $one['adults'] > 0,
        'adults' => get_string('codes:adults', $component, $one['adults']),
        'hascode' => $one['expires'] > 0,
        'code' => $one['expires'] > 0 ? get_string('codes:until', $component, (object) [
            'date' => page::date($one['expires']), 'claims' => $one['claims'],
        ]) : '',
    ];
}

$hidden = count($who['users']) - count($rows);
$counts = letters::counts($state);
$choices = [];
$checked = false;
foreach ([letters::PENDING, letters::NOCODE, letters::REPLACE] as $choice) {
    $choices[] = [
        'value' => $choice,
        'label' => get_string('codes:choice:' . $choice, $component),
        'help' => get_string('codes:choice:' . $choice . '_help', $component),
        'count' => $counts[$choice],
        'disabled' => $counts[$choice] === 0,
        'checked' => !$checked && $counts[$choice] > 0,
    ];
    $checked = $checked || $counts[$choice] > 0;
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_oksigeniafamily/codes_scope', [
    'nav' => page::nav('codes'),
    'backurl' => $scripturl->out(false),
    'single' => $single,
    'singlemode' => $single ? ($inuse ? letters::REPLACE : letters::NOCODE) : '',
    'singlewarning' => $single && $inuse ? get_string('codes:single:replace', $component) : '',
    'lede' => $single ? '' : get_string('codes:scope:count', $component, count($who['users'])),
    'rows' => $rows,
    'hasrows' => !empty($rows),
    'more' => $hidden > 0 ? get_string('codes:more', $component, $hidden) : '',
    'choices' => $choices,
    'canissue' => !empty($who['users']),
    'action' => $here->out(false),
    'sesskey' => sesskey(),
    'ticket' => $ticket,
    'inuse' => $inuse,
    'revokelabel' => get_string('codes:revoke', $component, $inuse),
    'askrevoke' => $revoke === 1 && $inuse > 0,
    'revoketext' => get_string('codes:revoke:ask', $component, $inuse),
    'revokeurl' => (new moodle_url($here, ['revoke' => 1]))->out(false),
    'cancelurl' => $here->out(false),
]);
echo $OUTPUT->footer();
