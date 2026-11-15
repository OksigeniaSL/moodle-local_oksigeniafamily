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
$need = optional_param('need', '', PARAM_ALPHA);
$need = $status === 'active' && in_array($need, families::needs(), true) ? $need : '';
$current = optional_param('page', 0, PARAM_INT);
$scripturl = new moodle_url('/local/oksigeniafamily/index.php');
$params = array_filter(['q' => $q, 'status' => $status === 'closed' ? $status : '', 'need' => $need]);

admin_externalpage_setup('local_oksigeniafamily_families', '', $params, $scripturl, ['nosearch' => true]);
page::dress(get_string('families', $component));

$now = time();
$number = fn(int $value) => format_float($value, 0);
$counts = families::counts();
$found = families::search($q, $status, $current, $need, $now);
$flags = $status === 'active' ? families::flags($found['families'], $found['people'], $need, $now) : [];
$rows = [];
foreach ($found['families'] as $family) {
    $rows[] = [
        'url' => (new moodle_url('/local/oksigeniafamily/family.php', ['id' => $family->id]))->out(false),
        'adults' => $family->adults ? implode(', ', $family->adults) : get_string('family:noadults', $component),
        'children' => $family->kids ? implode(', ', $family->kids) : get_string('family:nochildren', $component),
        'source' => page::source($family->source),
        'closed' => $family->status === 'closed',
        'flags' => $flags[$family->id] ?? [],
        'allgood' => $status === 'active' && empty($flags[$family->id]),
    ];
}
$filter = function (string $name, int $count) use ($q, $status, $scripturl, $component, $number) {
    $params = array_filter(['q' => $q, 'status' => $name === 'closed' ? $name : '']);
    return [
        'url' => (new moodle_url($scripturl, $params))->out(false),
        'label' => get_string('list:' . $name, $component),
        'count' => $number($count),
        'current' => $status === $name,
    ];
};

// What the active families are missing: a touch shows only those, and another one shows them all again.
$needgroups = [];
if ($status === 'active') {
    $needcounts = families::need_counts($now);
    foreach (families::needs() as $key) {
        [$group, $icon] = families::NEEDS[$key];
        $needgroups[$group][] = [
            'needurl' => (new moodle_url($scripturl, array_filter(['q' => $q, 'need' => $key === $need ? '' : $key])))->out(false),
            'needkind' => $group === 'missing' ? 'warn' : 'info',
            'needicon' => $icon,
            'needcount' => $number($needcounts[$key]),
            'needlabel' => get_string('need:' . $key, $component),
            'needhint' => get_string('need:' . $key . ':hint', $component),
            'needcurrent' => $key === $need,
            'neednone' => !$needcounts[$key],
        ];
    }
}
$groups = [];
foreach (['missing', 'know'] as $group) {
    if (!empty($needgroups[$group])) {
        $groups[] = ['grouptitle' => get_string('need:group:' . $group, $component), 'needs' => $needgroups[$group]];
    }
}

// The pages, without Moodle's paging bar: with a filter or a search, nobody needs more than a few.
$total = (int) $found['total'];
$pager = false;
if ($total > families::PERPAGE) {
    $pageurl = fn(int $page) => (new moodle_url($scripturl, $params + ['page' => $page]))->out(false);
    $pager = [
        'prevurl' => $current > 0 ? $pageurl($current - 1) : '',
        'nexturl' => ($current + 1) * families::PERPAGE < $total ? $pageurl($current + 1) : '',
        'range' => get_string('list:range', $component, (object) [
            'from' => $number($current * families::PERPAGE + 1),
            'to' => $number(min($total, ($current + 1) * families::PERPAGE)),
            'total' => $number($total),
        ]),
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_oksigeniafamily/families', [
    'nav' => page::nav('families'),
    'newurl' => (new moodle_url('/local/oksigeniafamily/adult.php'))->out(false),
    'importurl' => (new moodle_url('/local/oksigeniafamily/import.php'))->out(false),
    'codesurl' => (new moodle_url('/local/oksigeniafamily/codes.php'))->out(false),
    'lede' => get_string('list:lede', $component, (object) array_map($number, $counts)),
    'hasneeds' => !empty($groups),
    'needgroups' => $groups,
    'action' => $scripturl->out(false),
    'q' => $q,
    'closedfilter' => $status === 'closed',
    'need' => $need,
    'filters' => [$filter('active', $counts['active']), $filter('closed', $counts['closed'])],
    'showing' => $need === '' ? false : [
        'showingtext' => get_string('need:showing', $component, get_string('need:' . $need, $component)),
        'clearurl' => (new moodle_url($scripturl, array_filter(['q' => $q])))->out(false),
    ],
    'hasany' => $counts['active'] + $counts['closed'] > 0,
    'hasrows' => !empty($rows),
    'rows' => $rows,
    'pager' => $pager,
]);
echo $OUTPUT->footer();
