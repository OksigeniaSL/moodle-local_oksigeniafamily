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
 * Where an adult with an account (a parent with their own, or the account of Aty) answers the requests for consent
 * about their children: one request with ?id=, or the list of those still open.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniafamily\api;
use local_oksigeniafamily\local\consent;
use local_oksigeniafamily\local\consent_view;
use local_oksigeniafamily\local\page;

$id = optional_param('id', 0, PARAM_INT);
$component = 'local_oksigeniafamily';

require_login(null, false);
if (isguestuser()) {
    throw new require_login_exception('guestsarenotallowed');
}
$url = new moodle_url('/local/oksigeniafamily/consent.php', $id ? ['id' => $id] : []);
$PAGE->set_context(context_system::instance());
$PAGE->set_url($url);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('consent:title', $component));
$PAGE->set_heading(format_string(get_site()->fullname));
$now = time();

// The adult records that are this person, in every family they are in.
$adults = $DB->get_records('local_oksigeniafamily_adult', ['userid' => $USER->id, 'status' => api::ACTIVE]);

if ($id && optional_param('answer', '', PARAM_ALPHA) !== '') {
    require_sesskey();
    $adultid = required_param('adultid', PARAM_INT);
    $childuserid = required_param('childuserid', PARAM_INT);
    $answer = required_param('answer', PARAM_ALPHA);
    $request = consent::get($id);
    $message = get_string('consent:notsaved', $component);
    $type = \core\output\notification::NOTIFY_ERROR;
    if ($request && isset($adults[$adultid]) && in_array($answer, [consent::YES, consent::NO], true)) {
        try {
            consent::answer($id, $childuserid, $adultid, $answer, \core_useragent::is_moodle_app() ? 'app' : 'web', [
                'userid' => (int) $USER->id,
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
if ($id) {
    $request = consent::get($id);
    $shown = false;
    foreach ($request ? $adults : [] as $adult) {
        $children = consent::children_for_adult($request, (int) $adult->id);
        if (!$children) {
            continue;
        }
        echo $OUTPUT->render_from_template('local_oksigeniafamily/consent_answer', consent_view::answer(
            $request,
            $adult,
            $children,
            $now,
            $url,
            ['adultid' => (int) $adult->id]
        ));
        $shown = true;
    }
    if (!$shown) {
        echo $OUTPUT->notification(get_string('consent:nochildren', $component), \core\output\notification::NOTIFY_INFO);
    }
} else {
    echo $OUTPUT->heading(get_string('consent:mine', $component));
    $items = consent::for_user((int) $USER->id, $now);
    if (!$items) {
        echo html_writer::div(
            html_writer::tag('p', get_string('consent:mine:none', $component)),
            'local-oksigeniafamily okf-empty'
        );
    }
    $list = '';
    foreach ($items as $item) {
        $request = $item['request'];
        $names = [];
        foreach ($item['children'] as $childuserid) {
            $child = core_user::get_user($childuserid, 'id, firstname');
            if ($child) {
                $names[] = $child;
            }
        }
        $who = \local_oksigeniafamily\local\consent_notices::names($names);
        $until = get_string('consent:until', $component, page::date((int) $request->deadline));
        $go = new moodle_url('/local/oksigeniafamily/consent.php', ['id' => $request->id]);
        $inside = html_writer::tag('h3', format_string($request->title))
            . html_writer::tag('p', $who . ' · ' . $until)
            . html_writer::link($go, get_string('consent:mail:button', $component), ['class' => 'btn btn-primary']);
        $list .= html_writer::tag('section', $inside, ['class' => 'okf-card mb-3']);
    }
    if ($list !== '') {
        echo html_writer::div($list, 'local-oksigeniafamily');
    }
}
page::once_answers();
echo $OUTPUT->footer();
