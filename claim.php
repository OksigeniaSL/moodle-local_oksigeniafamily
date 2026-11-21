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
 * Where a family claims a student's code: they join the student's family, without an account, and see what each
 * plugin gives them for that child (the canteen's link and PIN, the library's page...).
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:ignore moodle.Files.RequireLogin.Missing -- families without an account claim their code here.
require(__DIR__ . '/../../config.php');

use local_oksigeniafamily\local\claimmail;
use local_oksigeniafamily\local\links;
use local_oksigeniafamily\local\page;
use local_oksigeniafamily\local\phone;
use local_oksigeniafamily\local\throttle;

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/oksigeniafamily/claim.php'));
$PAGE->set_pagelayout('login');
$PAGE->set_title(get_string('claim:title', 'local_oksigeniafamily'));
$PAGE->set_heading(format_string(get_site()->fullname));
// What this page shows after a claim (a canteen's link and PIN) is never kept by the browser nor by a proxy.
$PAGE->set_cacheable(false);

$signedin = isloggedin() && !isguestuser();
// It goes once: a double tap would otherwise claim twice.
$form = new \local_oksigeniafamily\form\claim_form(null, null, 'post', '', ['data-okf-once' => 1]);
page::once_forms();
if ($signedin) {
    $form->set_data(['firstname' => $USER->firstname, 'lastname' => $USER->lastname, 'email' => $USER->email]);
}

$done = null;
$error = '';
// Whoever claimed has shown the adult's account is theirs: signed in to it, or through the link sent to its address.
$proven = $signedin;
// The address a link went to, for a code claimed with the address of an account of the site.
$mailed = '';
$address = getremoteaddr();
// The link sent to an account's address, while its owner has not confirmed it yet: who it is for and which child.
$asking = null;
$confirm = optional_param('confirm', '', PARAM_ALPHANUMEXT);
if ($confirm !== '') {
    // The link sent to an account's address: the child goes into that account. Opening it changes nothing (a mail
    // scanner or a preview opens links too): its owner confirms with a button, and then it is used up.
    $pending = claimmail::read($confirm);
    $account = $pending ? core_user::get_user($pending['userid']) : null;
    $student = $pending ? \local_oksigeniafamily\api::code_student($pending['code']) : null;
    $child = $student ? core_user::get_user($student) : null;
    if (!$account || $account->deleted || $account->suspended || !$child || $child->deleted) {
        $error = get_string('claimmail:gone', 'local_oksigeniafamily');
    } else if (!data_submitted() || !confirm_sesskey()) {
        $asking = ['account' => $account, 'child' => $child];
    } else if (!claimmail::spend($confirm)) {
        $error = get_string('claimmail:gone', 'local_oksigeniafamily');
    } else {
        try {
            $done = \local_oksigeniafamily\api::claim($pending['code'], [
                'userid' => (int) $account->id,
                'firstname' => $account->firstname,
                'lastname' => $account->lastname,
                'email' => $account->email,
                'phone' => $pending['phone'],
            ]);
            $proven = true;
        } catch (moodle_exception $e) {
            if ($e->errorcode !== 'claimself' && $e->errorcode !== 'claiminvalid') {
                // Nothing went in (someone was claiming the same at that moment): the link can be tried again.
                claimmail::unspend($confirm);
            }
            if ($e->errorcode === 'claimbusy' || $e->errorcode === 'claimself') {
                $error = get_string($e->errorcode, 'local_oksigeniafamily');
            } else if ($e->errorcode === 'claiminvalid') {
                $error = get_string('claimmail:gone', 'local_oksigeniafamily');
            } else {
                throw $e;
            }
        }
    }
} else if ($data = $form->get_data()) {
    if (throttle::blocked($address)) {
        $error = get_string('claim:toomany', 'local_oksigeniafamily');
    } else {
        $prefix = (string) get_config('local_oksigeniafamily', 'phoneprefix');
        $adult = [
            'firstname' => $data->firstname,
            'lastname' => $data->lastname,
            'email' => $data->email,
            'phone' => phone::normalise((string) $data->phone, $prefix),
        ];
        if ($signedin) {
            $adult['userid'] = (int) $USER->id;
        }
        $account = $signedin ? null : claimmail::account((string) $data->email);
        $student = $account ? \local_oksigeniafamily\api::code_student((string) $data->code) : null;
        if ($account && !$student) {
            throttle::failed($address);
            $error = get_string('claiminvalid', 'local_oksigeniafamily');
        } else if ($account && (int) $account->id === $student) {
            $error = get_string('claimself', 'local_oksigeniafamily');
        } else if ($account) {
            // The address of an account of the site (a teacher who is also a parent): the child goes into that account,
            // once its owner opens the link sent to it. One account, and never two with one address.
            claimmail::send((string) $data->code, $account, $student, $adult['phone']);
            $mailed = (string) $data->email;
        }
        if ($error === '' && $mailed === '') {
            try {
                // A phone typed here proves nothing, so it is not passed as verified: it never joins an adult already there.
                $done = \local_oksigeniafamily\api::claim((string) $data->code, $adult);
            } catch (moodle_exception $e) {
                if ($e->errorcode === 'claimbusy' || $e->errorcode === 'claimself') {
                    $error = get_string($e->errorcode, 'local_oksigeniafamily');
                } else if ($e->errorcode !== 'claiminvalid') {
                    throw $e;
                } else {
                    throttle::failed($address);
                    $error = get_string('claiminvalid', 'local_oksigeniafamily');
                }
            }
        }
    }
}

// The letter's QR brings the code after the # of the address, which browsers never send: it stays out of the logs of the
// server and of the address other sites see. It only fills the box, and it leaves the address at once.
$PAGE->requires->js_amd_inline("
    require([], function() {
        var found = /[#&]code=([0-9A-Za-z-]+)/.exec(window.location.hash);
        var box = document.getElementById('id_code');
        if (found && box) {
            box.value = found[1].toUpperCase();
        }
        if (found) {
            window.history.replaceState(null, '', window.location.pathname + window.location.search);
        }
    });
");

$header = $OUTPUT->header();
// Sent after Moodle's own headers, so that it is this one that counts: the link's seal, in the address of the page,
// never goes to another site in the Referer, nor do the links this page shows after a claim.
@header('Referrer-Policy: no-referrer');
echo $header;
if ($done) {
    // Every child of the family they joined, the one of the code first: in a household the school's data brought, the
    // brothers and sisters came too, and each one's canteen link and PIN are shown only now.
    $accounts = [(int) $done->childuserid];
    $names = [];
    foreach (\local_oksigeniafamily\api::children_of_adult((int) $done->id) as $kid) {
        if (empty($kid->userid)) {
            $names[] = (string) $kid->firstname;
        } else if ((int) $kid->userid !== (int) $done->childuserid) {
            $accounts[] = (int) $kid->userid;
        }
    }
    $users = $DB->get_records_list('user', 'id', $accounts, '', 'id, firstname');
    $accounts = array_values(array_filter($accounts, fn($id) => isset($users[$id])));
    $names = array_values(array_filter(array_merge(array_map(fn($id) => $users[$id]->firstname, $accounts), $names), 'strlen'));
    $many = count($names) > 1;
    $last = $many ? array_pop($names) : null;
    $together = $last === null ? (string) reset($names) : get_string('consent:and', 'local_oksigeniafamily', (object) [
        'list' => implode(', ', $names),
        'last' => $last,
    ]);
    [$shared, $own] = links::for_children($done, $accounts, $proven);
    $groups = $shared ? [['name' => '', 'links' => $shared]] : [];
    foreach ($own as $userid => $links) {
        if ($links) {
            $groups[] = ['name' => $many ? $users[$userid]->firstname : '', 'links' => $links];
        }
    }
    echo $OUTPUT->render_from_template('local_oksigeniafamily/claim_done', [
        'done' => get_string('claim:done', 'local_oksigeniafamily', (object) [
            'child' => $together,
            'site' => format_string(get_site()->fullname),
        ]),
        'linksheading' => get_string('claim:links', 'local_oksigeniafamily'),
        'pinlabel' => get_string('claim:pin', 'local_oksigeniafamily'),
        'groups' => $groups,
        'haslinks' => !empty($groups),
        'nolinks' => get_string('claim:nolinks', 'local_oksigeniafamily'),
    ]);
} else if ($asking) {
    echo $OUTPUT->heading(get_string('claimmail:confirmheading', 'local_oksigeniafamily'));
    echo html_writer::tag('p', get_string('claimmail:confirm', 'local_oksigeniafamily', (object) [
        'name' => format_string($asking['account']->firstname),
        'child' => format_string($asking['child']->firstname),
        'site' => format_string(get_site()->fullname),
    ]));
    // A form of its own, by POST and with the session's key: it goes once, and nothing else can send it for its owner.
    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => (new moodle_url('/local/oksigeniafamily/claim.php'))->out(false),
        'data-okf-once' => 1,
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'confirm', 'value' => $confirm]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::tag(
        'button',
        get_string('claimmail:confirmbutton', 'local_oksigeniafamily'),
        ['type' => 'submit', 'class' => 'btn btn-primary']
    );
    echo html_writer::end_tag('form');
    echo html_writer::tag(
        'p',
        get_string('claimmail:confirmnot', 'local_oksigeniafamily'),
        ['class' => 'text-muted mt-3']
    );
} else if ($mailed !== '') {
    echo $OUTPUT->heading(get_string('claimmail:checkheading', 'local_oksigeniafamily'));
    echo html_writer::tag('p', get_string('claimmail:sent', 'local_oksigeniafamily', (object) [
        'email' => s($mailed),
        'site' => format_string(get_site()->fullname),
    ]));
    echo html_writer::tag('p', get_string('claimmail:other', 'local_oksigeniafamily'), ['class' => 'text-muted']);
} else {
    echo $OUTPUT->heading(get_string('claim:title', 'local_oksigeniafamily'));
    echo html_writer::tag('p', get_string('claim:intro', 'local_oksigeniafamily'));
    if ($error !== '') {
        echo $OUTPUT->notification($error, \core\output\notification::NOTIFY_ERROR);
    }
    $form->display();
}
echo $OUTPUT->footer();
