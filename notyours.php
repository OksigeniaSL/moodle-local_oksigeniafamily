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
 * «Not of my family»: from the notice that someone joined, an adult of the family takes them out (local\notyours).
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniafamily\local\notyours;
use local_oksigeniafamily\local\page;

require_login(null, false);
if (isguestuser()) {
    throw new require_login_exception('guestsarenotallowed');
}
$adultid = required_param('a', PARAM_INT);
$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/oksigeniafamily/notyours.php', ['a' => $adultid]));
$PAGE->set_pagelayout('standard');
page::dress(get_string('notyours:title', 'local_oksigeniafamily'));

$state = notyours::state($adultid, (int) $USER->id);
if ($state === notyours::ASK && optional_param('remove', 0, PARAM_BOOL) && confirm_sesskey()) {
    $state = notyours::remove($adultid, (int) $USER->id);
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_oksigeniafamily/notyours', notyours::context($adultid, $state));
echo $OUTPUT->footer();
