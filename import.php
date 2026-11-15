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
 * Importing families from a CSV file: upload it, see what would change, and apply it. The file waits between the two
 * steps in the plugin's own area (see local\import\job), and it is deleted when it has gone in or the person leaves it;
 * if they just go away, the daily round erases it the day after. A small file goes in at once; a big one, in the
 * background by batches, and this page shows how it goes and what it did. A file of more than a few thousand people is
 * also looked at in the background, and this page shows its preview when it is ready.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_oksigeniafamily\local\import\csv_file;
use local_oksigeniafamily\local\import\importer;
use local_oksigeniafamily\local\import\job;
use local_oksigeniafamily\local\import\preview;
use local_oksigeniafamily\local\page;

$component = 'local_oksigeniafamily';
$sample = optional_param('sample', 0, PARAM_BOOL);
$jobid = optional_param('job', 0, PARAM_INT);
$complete = optional_param('complete', 0, PARAM_BOOL);
$scripturl = new moodle_url('/local/oksigeniafamily/import.php');
$listurl = new moodle_url('/local/oksigeniafamily/index.php');

admin_externalpage_setup('local_oksigeniafamily_families', '', null, $scripturl, ['nosearch' => true]);

if ($sample) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="families-example.csv"');
    echo csv_file::sample();
    exit;
}

// Step three: apply it, or leave it.
if ($jobid && (optional_param('apply', 0, PARAM_BOOL) || optional_param('cancel', 0, PARAM_BOOL))) {
    require_sesskey();
    if (!optional_param('apply', 0, PARAM_BOOL)) {
        job::cancel($jobid, (int) $USER->id);
        redirect($scripturl);
    }
    $typed = optional_param('confirmleaving', -1, PARAM_INT);
    try {
        $job = job::start($jobid, $typed >= 0 ? $typed : null, (int) $USER->id);
    } catch (moodle_exception $e) {
        redirect($scripturl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
    if ($job->status === job::DONE) {
        \core\notification::success(get_string('import:done', $component, (object) job::counts($job)));
        redirect($listurl);
    }
    redirect(new moodle_url($scripturl, ['job' => $jobid]));
}

// An import on its way in, or what it did.
$job = $jobid ? job::get($jobid) : null;
$stored = $job && $job->status === job::PREVIEW && (int) $job->userid === (int) $USER->id ? job::stored_preview($job) : null;
if ($stored) {
    page::dress(get_string('import:preview', $component));
    page::once_forms();
    echo $OUTPUT->header();
    echo $OUTPUT->render_from_template('local_oksigeniafamily/import_preview', $stored + [
        'nav' => page::nav('import'),
        'action' => $scripturl->out(false),
        'sesskey' => sesskey(),
        'job' => (int) $job->id,
        'background' => (int) $job->filerows > job::NOWROWS,
    ]);
    echo $OUTPUT->footer();
    exit;
}
if ($job && $job->status !== job::PREVIEW) {
    $view = job::export($job);
    if ($view['busy']) {
        // The page comes back on its own while the import goes in.
        $PAGE->set_periodic_refresh_delay(5);
    }
    page::dress(get_string('import:job', $component));
    echo $OUTPUT->header();
    echo $OUTPUT->render_from_template('local_oksigeniafamily/import_job', $view + [
        'nav' => page::nav('import'),
        'importurl' => $scripturl->out(false),
        'listurl' => $listurl->out(false),
    ]);
    echo $OUTPUT->footer();
    exit;
}

// Step two: what would change.
$error = '';
$preview = null;
if (!empty($_FILES['csvfile'])) {
    require_sesskey();
    $upload = $_FILES['csvfile'];
    if ($upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
        $error = get_string('import:file:nofile', $component);
    } else {
        $text = file_get_contents($upload['tmp_name']);
        $read = csv_file::read($text);
        if ($read['error'] === 'missing') {
            $error = get_string('import:file:missing', $component, implode(', ', $read['missing']));
        } else if ($read['error'] !== '') {
            $error = get_string('import:file:' . $read['error'], $component);
        } else if (count($read['rows']) > job::PREVIEWNOWROWS) {
            // Too many people to look at inside the page: the preview is worked out in the background.
            $jobid = job::prepare_later($text, (string) $upload['name'], (bool) $complete, count($read['rows']), (int) $USER->id);
            redirect(new moodle_url($scripturl, ['job' => $jobid]));
        } else {
            $preview = importer::plan($read['rows'], 'csv', (bool) $complete);
            $rows = count($read['rows']);
            $jobid = job::prepare($text, (string) $upload['name'], (bool) $complete, $preview, $rows, (int) $USER->id);
        }
    }
}

page::dress(get_string($preview ? 'import:preview' : 'import', $component));
page::once_forms();

echo $OUTPUT->header();
if ($preview) {
    echo $OUTPUT->render_from_template('local_oksigeniafamily/import_preview', preview::view($preview, $read['unknown']) + [
        'nav' => page::nav('import'),
        'action' => $scripturl->out(false),
        'sesskey' => sesskey(),
        'job' => $jobid,
        'background' => $rows > job::NOWROWS,
    ]);
} else {
    $jobs = array_map(fn($one) => job::export($one), job::recent(time()));
    echo $OUTPUT->render_from_template('local_oksigeniafamily/import', [
        'nav' => page::nav('import'),
        'action' => $scripturl->out(false),
        'sesskey' => sesskey(),
        'error' => $error,
        'sampleurl' => (new moodle_url($scripturl, ['sample' => 1]))->out(false),
        'jobs' => $jobs,
        'hasjobs' => !empty($jobs),
        'columns' => array_map(fn($column) => [
            'name' => $column,
            'help' => get_string('import:column:' . $column, $component, importer::phone_prefix()),
            'required' => in_array($column, csv_file::REQUIRED, true),
        ], ['family', 'type', 'firstname', 'lastname', 'email', 'phone', 'username', 'idnumber', 'key']),
    ]);
}
echo $OUTPUT->footer();
