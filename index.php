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
 * The family records of the site: the list, with its search, and the ways for families to get in.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_oksigeniafamily\local\families;
use local_oksigeniafamily\local\page;

$component = 'local_oksigeniafamily';
$q = optional_param('q', '', PARAM_RAW_TRIMMED);
$status = optional_param('status', 'active', PARAM_ALPHA) === 'closed' ? 'closed' : 'active';
$current = optional_param('page', 0, PARAM_INT);
$scripturl = new moodle_url('/local/oksigeniafamily/index.php');
$params = array_filter(['q' => $q, 'status' => $status === 'closed' ? $status : '']);

admin_externalpage_setup('local_oksigeniafamily_families', '', $params, $scripturl, ['nosearch' => true]);
page::dress(get_string('families', $component));

$counts = families::counts();
$found = families::search($q, $status, $current);
$rows = [];
foreach ($found['families'] as $family) {
    $rows[] = [
        'url' => (new moodle_url('/local/oksigeniafamily/family.php', ['id' => $family->id]))->out(false),
        'adults' => $family->adults ? implode(', ', $family->adults) : get_string('family:noadults', $component),
        'children' => $family->children ? implode(', ', $family->children) : get_string('family:nochildren', $component),
        'source' => page::source($family->source),
        'closed' => $family->status === 'closed',
    ];
}
$filter = function (string $name, int $count) use ($q, $status, $scripturl, $component) {
    $params = array_filter(['q' => $q, 'status' => $name === 'closed' ? $name : '']);
    return [
        'url' => (new moodle_url($scripturl, $params))->out(false),
        'label' => get_string('list:' . $name, $component),
        'count' => $count,
        'current' => $status === $name,
    ];
};

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_oksigeniafamily/families', [
    'nav' => page::nav('families'),
    'newurl' => (new moodle_url('/local/oksigeniafamily/adult.php'))->out(false),
    'importurl' => (new moodle_url('/local/oksigeniafamily/import.php'))->out(false),
    'codesurl' => (new moodle_url('/local/oksigeniafamily/codes.php'))->out(false),
    'lede' => get_string('list:lede', $component, (object) $counts),
    'action' => $scripturl->out(false),
    'q' => $q,
    'closedfilter' => $status === 'closed',
    'filters' => [$filter('active', $counts['active']), $filter('closed', $counts['closed'])],
    'hasany' => $counts['active'] + $counts['closed'] > 0,
    'hasrows' => !empty($rows),
    'rows' => $rows,
    'paging' => $OUTPUT->paging_bar($found['total'], $current, families::PERPAGE, new moodle_url($scripturl, $params)),
]);
echo $OUTPUT->footer();
