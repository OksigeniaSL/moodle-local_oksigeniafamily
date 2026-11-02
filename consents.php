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
 * The requests for consent of a course, or of the whole site.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniafamily\local\consent_view;
use local_oksigeniafamily\local\page;

$contextid = optional_param('contextid', 0, PARAM_INT);
$context = $contextid ? context::instance_by_id($contextid) : context_system::instance();
$component = 'local_oksigeniafamily';
$title = get_string('consent:title', $component);
$params = $context->contextlevel == CONTEXT_SYSTEM ? [] : ['contextid' => $context->id];
page::consent_setup($context, '/local/oksigeniafamily/consents.php', $params, $title);

$site = $context->contextlevel == CONTEXT_SYSTEM;
$requests = $site
    ? $DB->get_records('local_oksigeniafamily_request', null, 'deadline DESC, id DESC', '*', 0, 200)
    : $DB->get_records('local_oksigeniafamily_request', ['contextid' => $context->id], 'deadline DESC, id DESC');
$rows = consent_view::rows($requests, time(), $site);

echo $OUTPUT->header();
if (!$site) {
    echo $OUTPUT->heading($title);
}
echo $OUTPUT->render_from_template('local_oksigeniafamily/consents', [
    'nav' => $site ? page::nav('consents') : null,
    'newurl' => (new moodle_url('/local/oksigeniafamily/consent_edit.php', $params))->out(false),
    'hasrows' => (bool) $rows,
    'wherecolumn' => $site,
    'rows' => $rows,
]);
echo $OUTPUT->footer();
