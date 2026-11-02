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

use local_oksigeniafamily\local\links;
use local_oksigeniafamily\local\phone;
use local_oksigeniafamily\local\throttle;

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/oksigeniafamily/claim.php'));
$PAGE->set_pagelayout('login');
$PAGE->set_title(get_string('claim:title', 'local_oksigeniafamily'));
$PAGE->set_heading(format_string(get_site()->fullname));

$signedin = isloggedin() && !isguestuser();
$form = new \local_oksigeniafamily\form\claim_form();
if ($signedin) {
    $form->set_data(['firstname' => $USER->firstname, 'lastname' => $USER->lastname, 'email' => $USER->email]);
}

$done = null;
$error = '';
$address = getremoteaddr();
if ($data = $form->get_data()) {
    if (throttle::blocked($address)) {
        $error = get_string('claim:toomany', 'local_oksigeniafamily');
    } else {
        $prefix = (string) (get_config('local_oksigeniafamily', 'phoneprefix') ?: '+34');
        $adult = [
            'firstname' => $data->firstname,
            'lastname' => $data->lastname,
            'email' => $data->email,
            'phone' => phone::normalise((string) $data->phone, $prefix),
        ];
        if ($signedin) {
            $adult['userid'] = (int) $USER->id;
        }
        try {
            // A phone typed here proves nothing, so it is not passed as verified: it never joins an adult already there.
            $done = \local_oksigeniafamily\api::claim((string) $data->code, $adult);
        } catch (moodle_exception $e) {
            if ($e->errorcode !== 'claiminvalid') {
                throw $e;
            }
            throttle::failed($address);
            $error = get_string('claiminvalid', 'local_oksigeniafamily');
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
    $links = links::for_child($done, (int) $done->childuserid);
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
} else {
    echo $OUTPUT->heading(get_string('claim:title', 'local_oksigeniafamily'));
    echo html_writer::tag('p', get_string('claim:intro', 'local_oksigeniafamily'));
    if ($error !== '') {
        echo $OUTPUT->notification($error, \core\output\notification::NOTIFY_ERROR);
    }
    $form->display();
}
echo $OUTPUT->footer();
