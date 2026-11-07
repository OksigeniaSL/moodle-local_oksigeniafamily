<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * The students' photos and what the families say about the use of their image: a zip of photos named after each
 * student's number, and a spreadsheet of answers whose columns are chosen after it is read. The spreadsheet waits
 * between the two steps in the user's own draft area, as with the import of families.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_oksigeniafamily\local\page;
use local_oksigeniafamily\local\photos;
use local_oksigeniafamily\local\standing;

$component = 'local_oksigeniafamily';
$scripturl = new moodle_url('/local/oksigeniafamily/photos.php');
$draft = optional_param('draft', 0, PARAM_INT);

admin_externalpage_setup('local_oksigeniafamily_photos', '', null, $scripturl, ['nosearch' => true]);

$fs = get_file_storage();
$usercontext = context_user::instance($USER->id);
$area = [$usercontext->id, 'user', 'draft'];
$csvname = 'oksigeniafamily-image.csv';
$kinds = photos::kinds();
$kindoptions = function (string $selected) use ($kinds, $component): array {
    $options = [];
    foreach ($kinds as $kind => $source) {
        $options[] = [
            'value' => $kind,
            'label' => $source === '' ? get_string('photos:kind:idnumber', $component)
                : get_string('photos:kind:source', $component, $source),
            'selected' => $kind === $selected,
        ];
    }
    return $options;
};
$kind = optional_param('kind', 'idnumber', PARAM_RAW_TRIMMED);
$kind = array_key_exists($kind, $kinds) ? $kind : 'idnumber';
$unmatched = function (array $names) use ($component): void {
    if ($names) {
        $shown = array_slice($names, 0, 50);
        $more = count($names) > 50 ? ' …' : '';
        \core\notification::warning(get_string('photos:unmatched', $component, s(implode(', ', $shown)) . $more));
    }
};

if (photos::enabled() && !empty($_FILES['zipfile'])) {
    // The photos: stored at once.
    require_sesskey();
    $upload = $_FILES['zipfile'];
    if ($upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
        \core\notification::error(get_string('photos:nofile', $component));
    } else {
        $result = photos::import_zip($upload['tmp_name'], $kind);
        if ($result === null) {
            \core\notification::error(get_string('photos:badzip', $component));
        } else {
            \core\notification::success(get_string('photos:done', $component, (object) $result));
            $unmatched($result['names']);
        }
    }
    redirect($scripturl);
}

if (photos::enabled() && $draft && (optional_param('apply', 0, PARAM_BOOL) || optional_param('cancel', 0, PARAM_BOOL))) {
    // The answers, second step: loaded with the columns chosen, or left.
    require_sesskey();
    $file = $fs->get_file($area[0], $area[1], $area[2], $draft, '/', $csvname);
    if ($file && optional_param('apply', 0, PARAM_BOOL)) {
        $read = standing::read_csv($file->get_content());
        $idcolumn = optional_param('idcolumn', -1, PARAM_INT);
        $answercolumn = optional_param('answercolumn', -1, PARAM_INT);
        $count = count($read['headers']);
        $valid = $idcolumn >= 0 && $answercolumn >= 0 && $idcolumn < $count && $answercolumn < $count;
        if (!$valid || $idcolumn === $answercolumn) {
            \core\notification::error(get_string('image:pick', $component));
            redirect(new moodle_url($scripturl, ['draft' => $draft, 'kind' => $kind]));
        }
        $result = standing::import_rows($read['rows'], $idcolumn, $answercolumn, $kind);
        \core\notification::success(get_string('image:done', $component, (object) $result));
        $unmatched($result['names']);
    }
    $fs->delete_area_files($area[0], $area[1], $area[2], $draft);
    redirect($scripturl);
}

$columns = null;
if (photos::enabled() && !empty($_FILES['csvfile'])) {
    // The answers, first step: the file is read and kept for the second.
    require_sesskey();
    $upload = $_FILES['csvfile'];
    if ($upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
        \core\notification::error(get_string('image:nofile', $component));
        redirect($scripturl);
    }
    $text = (string) file_get_contents($upload['tmp_name']);
    $read = standing::read_csv($text);
    if (!$read['headers'] || !$read['rows']) {
        \core\notification::error(get_string('image:empty', $component));
        redirect($scripturl);
    }
    $draft = file_get_unused_draft_itemid();
    $fs->create_file_from_string([
        'contextid' => $area[0], 'component' => $area[1], 'filearea' => $area[2], 'itemid' => $draft,
        'filepath' => '/', 'filename' => $csvname,
    ], $text);
    $columns = $read;
} else if (photos::enabled() && $draft) {
    // Back to the second step, after choosing the columns wrong.
    $file = $fs->get_file($area[0], $area[1], $area[2], $draft, '/', $csvname);
    $columns = $file ? standing::read_csv($file->get_content()) : null;
    $draft = $file ? $draft : 0;
}

page::dress(get_string('photos', $component));
page::once_forms();

$context = [
    'nav' => page::nav('photos'),
    'enabled' => photos::enabled(),
    'action' => $scripturl->out(false),
    'sesskey' => sesskey(),
];
if ($columns) {
    $guess = standing::guess_columns($columns['headers']);
    $options = function (int $selected) use ($columns): array {
        $list = [];
        foreach ($columns['headers'] as $index => $header) {
            $list[] = [
                'value' => $index,
                'label' => $header !== '' ? $header : '#' . ($index + 1),
                'selected' => $index === $selected,
            ];
        }
        return $list;
    };
    $context['columns'] = [
        'draft' => $draft,
        'rows' => count($columns['rows']),
        'idoptions' => $options($guess['id']),
        'answeroptions' => $options($guess['answer']),
        'kinds' => $kindoptions($kind),
    ];
} else {
    $summary = standing::summary();
    $context['count'] = get_string('photos:count', $component, photos::count());
    $context['summary'] = get_string('image:summary', $component, (object) $summary);
    $grace = get_config($component, 'gracedays');
    $grace = $grace === false || $grace === '' ? 30 : max(0, (int) $grace);
    $context['grace'] = get_string('photos:grace', $component, $grace);
    $context['kinds'] = $kindoptions($kind);
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_oksigeniafamily/photos', $context);
echo $OUTPUT->footer();
