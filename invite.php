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
 * «Invite another adult»: the QR another adult of the household scans to join this family, with all its children.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniafamily\local\join;
use local_oksigeniafamily\local\page;

require_login(null, false);
if (isguestuser()) {
    throw new require_login_exception('guestsarenotallowed');
}
$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/oksigeniafamily/invite.php'));
$PAGE->set_pagelayout('standard');
page::dress(get_string('invite:title', 'local_oksigeniafamily'));

$context = join::invite_context((int) $USER->id);

echo $OUTPUT->header();
if ($context) {
    echo $OUTPUT->render_from_template('local_oksigeniafamily/join', $context);
} else {
    echo $OUTPUT->notification(get_string('invite:none', 'local_oksigeniafamily'), \core\output\notification::NOTIFY_INFO);
}
echo $OUTPUT->footer();
