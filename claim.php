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
$confirm = optional_param('confirm', '', PARAM_ALPHANUMEXT);
if ($confirm !== '') {
    // The link sent to an account's address: the child goes into that account.
    $pending = claimmail::read($confirm);
    $account = $pending ? core_user::get_user($pending['userid']) : null;
    if (!$account || $account->deleted || $account->suspended) {
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

echo $OUTPUT->header();
if ($done) {
    $child = core_user::get_user($done->childuserid);
    $links = links::for_child($done, (int) $done->childuserid, $proven);
    echo $OUTPUT->render_from_template('local_oksigeniafamily/claim_done', [
        'done' => get_string('claim:done', 'local_oksigeniafamily', (object) [
            'child' => $child ? $child->firstname : '',
            'site' => format_string(get_site()->fullname),
        ]),
        'linksheading' => get_string('claim:links', 'local_oksigeniafamily'),
        'pinlabel' => get_string('claim:pin', 'local_oksigeniafamily'),
        'links' => $links,
        'haslinks' => !empty($links),
        'nolinks' => get_string('claim:nolinks', 'local_oksigeniafamily'),
    ]);
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
