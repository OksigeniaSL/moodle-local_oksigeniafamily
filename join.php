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
 * «For your family»: the student's QR, code and steps for their family to join, as the letter they take home has them;
 * for a student who eats at a canteen, Oksigenia Meals' way without installing anything below. With ?id=, the office
 * (whoever prints the letters) sees a student's, to show it to their family.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniafamily\local\join;
use local_oksigeniafamily\local\page;

$id = optional_param('id', 0, PARAM_INT);

require_login(null, false);
if (isguestuser()) {
    throw new require_login_exception('guestsarenotallowed');
}
$system = context_system::instance();
if ($id && $id !== (int) $USER->id) {
    require_capability('local/oksigeniafamily:manage', $system);
}
$student = $id ?: (int) $USER->id;
$PAGE->set_context($system);
$PAGE->set_url(new moodle_url('/local/oksigeniafamily/join.php', $id ? ['id' => $id] : []));
$PAGE->set_pagelayout('standard');
page::dress(get_string('join:title', 'local_oksigeniafamily'));

$context = join::eligible($student) ? join::context($student) : null;

// The canteen's way without installing anything, for a student who eats at one (Oksigenia Meals); it says which
// phone from the visitor's, and it brings its own sheet.
$canteen = '';
$help = '\\local_oksigeniameals\\local\\familyhelp';
if ($context && class_exists($help) && method_exists($help, 'section')) {
    // A phone, not a tablet: on the child's tablet the parent scans the QR with their own phone (Meals says which).
    $phone = method_exists($help, 'phone')
        ? $help::phone()
        : core_useragent::get_device_type() === core_useragent::DEVICETYPE_MOBILE;
    $section = $help::section($student, $phone);
    $canteen = (string) ($section['html'] ?? '');
    if ($canteen !== '' && !empty($section['css'])) {
        $PAGE->requires->css(new moodle_url((string) $section['css']));
    }
}

echo $OUTPUT->header();
if ($context) {
    echo $OUTPUT->render_from_template('local_oksigeniafamily/join', $context);
    if ($canteen !== '') {
        echo html_writer::div($canteen, 'local-oksigeniafamily okf-join okf-join-canteen');
    }
} else {
    echo $OUTPUT->notification(get_string('join:none', 'local_oksigeniafamily'), \core\output\notification::NOTIFY_INFO);
}
echo $OUTPUT->footer();
