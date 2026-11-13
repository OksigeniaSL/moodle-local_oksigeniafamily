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
 * Library functions of local_oksigeniafamily: what other plugins look for here.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * For Oksigenia Marandu's list of numbers: who an adult of the family records is, such as "Lucía Sanz (family of Ana)".
 *
 * @param int $adultid The adult (Marandu's item id).
 * @return string|null The label, or null when the adult is not there.
 */
function local_oksigeniafamily_oksigeniamarandu_label(int $adultid): ?string {
    global $DB;

    $adult = $DB->get_record('local_oksigeniafamily_adult', ['id' => $adultid]);
    if (!$adult) {
        return null;
    }
    $name = trim(($adult->firstname ?? '') . ' ' . ($adult->lastname ?? ''));
    if ($name === '') {
        $name = $adult->phone ?? get_string('notice:someone', 'local_oksigeniafamily');
    }
    $children = [];
    foreach (\local_oksigeniafamily\api::children_of_adult($adultid) as $child) {
        $user = $child->userid ? \core_user::get_user((int) $child->userid, 'id, firstname') : null;
        if ($user) {
            $children[] = format_string($user->firstname);
        }
    }
    if (!$children) {
        return format_string($name);
    }

    return get_string('marandu:adultname', 'local_oksigeniafamily', (object) [
        'adult' => format_string($name),
        'children' => implode(', ', $children),
    ]);
}

/**
 * For Oksigenia Marandu: the notices of the family records that go by WhatsApp and Telegram. A request for consent goes
 * as a question with two buttons, «Yes» and «No», and the answer comes back by Marandu's hook (db/hooks.php).
 *
 * @return array
 */
function local_oksigeniafamily_oksigeniamarandu_notices(): array {
    return [
        \local_oksigeniafamily\local\consent_notices::MARANDU_NOTICE => [
            'examples' => ['Academia Ejemplo', 'Lucas', 'Salida al Teide', '14 de noviembre de 2026'],
            'perweek' => 0.3,
            'options' => [\local_oksigeniafamily\local\consent::YES, \local_oksigeniafamily\local\consent::NO],
        ],
    ];
}

/**
 * The requests for consent in the menu of a course, for its teachers.
 *
 * @param navigation_node $navigation The course's node.
 * @param stdClass $course The course.
 * @param context $context Its context.
 * @return void
 */
function local_oksigeniafamily_extend_navigation_course(navigation_node $navigation, stdClass $course, context $context): void {
    if (
        (int) $course->id === (int) SITEID || !\local_oksigeniafamily\local\consent::enabled()
        || !has_capability('local/oksigeniafamily:requestconsent', $context)
    ) {
        return;
    }
    $navigation->add(
        get_string('consent:title', 'local_oksigeniafamily'),
        new moodle_url('/local/oksigeniafamily/consents.php', ['contextid' => $context->id]),
        navigation_node::TYPE_SETTING,
        null,
        'local_oksigeniafamily_consents',
        new pix_icon('i/checked', '')
    );
}

/**
 * The circular of a request for consent: for the school, where it can see the request; for an adult with an account,
 * if they are family of one of its students; for an adult with no account, with their personal link (?t=).
 *
 * @param stdClass $course The course (the site).
 * @param stdClass|null $cm Not used.
 * @param context $context The context of the file (the system).
 * @param string $filearea The area: consent.
 * @param array $args The request, then the path and the name.
 * @param bool $forcedownload Whether to force the download.
 * @param array $options More options.
 * @return bool False if it cannot be sent.
 */
function local_oksigeniafamily_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    global $DB, $USER;

    if ($context->contextlevel != CONTEXT_SYSTEM || $filearea !== 'consent' || count($args) < 2) {
        return false;
    }
    $requestid = (int) array_shift($args);
    $request = \local_oksigeniafamily\local\consent::get($requestid);
    if (!$request) {
        return false;
    }

    $allowed = false;
    $link = \local_oksigeniafamily\local\consent_link::read(optional_param('t', '', PARAM_ALPHANUMEXT));
    if ($link && $link['requestid'] === $requestid) {
        $allowed = $link['source'] === 'key'
            ? in_array($link['childuserid'], \local_oksigeniafamily\local\consent::students($requestid), true)
            : (bool) \local_oksigeniafamily\local\consent::children_for_adult($request, $link['adultid']);
    }
    if (!$allowed && isloggedin() && !isguestuser()) {
        $requestcontext = context::instance_by_id((int) $request->contextid, IGNORE_MISSING);
        $allowed = $requestcontext && has_capability('local/oksigeniafamily:requestconsent', $requestcontext);
        if (!$allowed) {
            $adults = $DB->get_records(
                'local_oksigeniafamily_adult',
                ['userid' => $USER->id, 'status' => \local_oksigeniafamily\api::ACTIVE],
                '',
                'id'
            );
            foreach ($adults as $adult) {
                if (\local_oksigeniafamily\local\consent::children_for_adult($request, (int) $adult->id)) {
                    $allowed = true;
                    break;
                }
            }
        }
    }
    if (!$allowed) {
        return false;
    }

    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';
    $file = get_file_storage()->get_file($context->id, 'local_oksigeniafamily', 'consent', $requestid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }
    send_stored_file($file, 0, 0, $forcedownload, $options);
}

/**
 * For Oksigenia Aty: on a child's page in the app of an adult of their family, the open requests for consent about the
 * child, with what this adult answered; each row opens the request to answer it or change the answer.
 *
 * @param int $adultuserid The adult's account (their own or the account of Aty).
 * @param int $childuserid The child.
 * @return array One card, or none.
 */
function local_oksigeniafamily_oksigeniaaty_child_cards(int $adultuserid, int $childuserid): array {
    return \local_oksigeniafamily\local\consent_view::child_card($adultuserid, $childuserid, time());
}

/**
 * For the home of the app (local_oksigeniaaty): the requests for consent that still wait for this adult's answer, each
 * with a button to answer it.
 *
 * @param int $userid The person.
 * @return array The card, or none.
 */
function local_oksigeniafamily_oksigeniaaty_home_cards(int $userid): array {
    return \local_oksigeniafamily\local\consent_view::home_card($userid, time());
}

/**
 * For «Your work», the dashboard block of the Oksigenia plugins: the family records, for whoever runs them, and the
 * requests for consent, with what closes today without an answer.
 *
 * @param int $userid The person.
 * @return array Its doors; none when the person runs nothing here.
 */
function local_oksigeniafamily_oksigenia_work(int $userid): array {
    return \local_oksigeniafamily\local\work::for_user($userid);
}
