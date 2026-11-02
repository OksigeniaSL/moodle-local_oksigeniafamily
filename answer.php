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
 * Where an adult with no account answers a request for consent, with the personal link that came by e-mail or as the
 * button of a message. The link is signed, so nobody can make one up or turn it into someone else's.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:ignore moodle.Files.RequireLogin.Missing -- families without an account answer here with their personal link.
require(__DIR__ . '/../../config.php');

use local_oksigeniafamily\api;
use local_oksigeniafamily\local\consent;
use local_oksigeniafamily\local\consent_link;
use local_oksigeniafamily\local\consent_view;
use local_oksigeniafamily\local\page;

$token = required_param('t', PARAM_ALPHANUMEXT);
$component = 'local_oksigeniafamily';
$url = new moodle_url('/local/oksigeniafamily/answer.php', ['t' => $token]);
$PAGE->set_context(context_system::instance());
$PAGE->set_url($url);
$PAGE->set_pagelayout('login');
$PAGE->set_title(get_string('consent:title', $component));
$PAGE->set_heading(format_string(get_site()->fullname));
$now = time();

$link = consent_link::read($token);
$request = $link ? consent::get($link['requestid']) : null;
$bykey = $link && $link['source'] === 'key';
$adult = null;
$children = [];
$keyadults = [];
if ($request && $bykey) {
    // Behind a key of the student: whoever answers says which of the student's adults they are.
    if (in_array($link['childuserid'], consent::students((int) $request->id), true)) {
        foreach (api::adults_of_child($link['childuserid']) as $one) {
            $keyadults[(int) $one->id] = $one;
        }
        $children = [$link['childuserid']];
    }
} else if ($request && $link) {
    $adult = $DB->get_record('local_oksigeniafamily_adult', ['id' => $link['adultid'], 'status' => api::ACTIVE]) ?: null;
    $children = $adult ? consent::children_for_adult($request, (int) $adult->id) : [];
}

if ($request && $children && optional_param('answer', '', PARAM_ALPHA) !== '') {
    require_sesskey();
    $childuserid = required_param('childuserid', PARAM_INT);
    $answer = required_param('answer', PARAM_ALPHA);
    $message = get_string('consent:notsaved', $component);
    $type = \core\output\notification::NOTIFY_ERROR;
    $who = $bykey ? ($keyadults[optional_param('adultid', 0, PARAM_INT)] ?? null) : $adult;
    $keyok = !$bykey || ($childuserid === $link['childuserid'] && empty($request->needall));
    if ($who && $keyok && in_array($answer, [consent::YES, consent::NO], true)) {
        try {
            consent::answer((int) $request->id, $childuserid, (int) $who->id, $answer, $link['source'], [
                'address' => $bykey ? 'key:' . $link['keyid'] : ($link['source'] === 'email' ? $who->email : null),
                'comment' => optional_param('comment', '', PARAM_TEXT),
            ]);
            $child = core_user::get_user($childuserid);
            $message = get_string('consent:saved:' . $answer, $component, (object) [
                'child' => $child ? format_string($child->firstname) : '',
                'deadline' => page::date((int) $request->deadline),
            ]);
            $type = \core\output\notification::NOTIFY_SUCCESS;
        } catch (moodle_exception $e) {
            $message = $e->getMessage();
        }
    }
    redirect($url, $message, null, $type);
}

echo $OUTPUT->header();
if (!$request || !$children) {
    echo $OUTPUT->heading(get_string('consent:title', $component));
    echo $OUTPUT->notification(get_string('consent:linkinvalid', $component), \core\output\notification::NOTIFY_WARNING);
} else if ($bykey) {
    echo $OUTPUT->render_from_template('local_oksigeniafamily/consent_answer', consent_view::answer_by_key(
        $request,
        $link['childuserid'],
        $keyadults,
        $now,
        $url,
        ['t' => $token]
    ));
    page::once_answers();
} else {
    echo $OUTPUT->render_from_template('local_oksigeniafamily/consent_answer', consent_view::answer(
        $request,
        $adult,
        $children,
        $now,
        $url,
        ['t' => $token]
    ));
    page::once_answers();
}
echo $OUTPUT->footer();
