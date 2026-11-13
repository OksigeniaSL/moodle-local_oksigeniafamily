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

namespace local_oksigeniafamily\local\import;

use local_oksigeniafamily\local\page;
use local_oksigeniafamily\task\import_apply;

/**
 * A file of families on its way in. Looking at it keeps the file in the plugin's own area, with what the preview said.
 * Applying it does it at once when the file is small, as always; otherwise a background task goes through the families
 * by batches, each batch in its own transaction, and the import page and a notification say how it goes and what it
 * did. A university's file is far more than a web request can hold.
 *
 * A batch that went in stays if a later one fails, and running the task again goes on from there: the import never
 * duplicates anything, so the families already in are simply found as they are.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class job {
    /** The file area where the file waits, under the id of its import. */
    public const AREA = 'import';

    /** Families per batch in the background, each batch in its own transaction. */
    public const BATCH = 500;

    /** A file with up to this many rows goes in at once, inside the page, as it always did. */
    public const NOWROWS = 200;

    /** Looked at, not applied yet. */
    public const PREVIEW = 'preview';

    /** Waiting for the background task. */
    public const QUEUED = 'queued';

    /** Being applied in the background. */
    public const RUNNING = 'running';

    /** Applied. */
    public const DONE = 'done';

    /** Stopped by an error: what went in stays, and Moodle runs the task again later. */
    public const FAILED = 'failed';

    /** Not applied, and it will not be: the records changed under the brake of a complete list, or the file is gone. */
    public const STOPPED = 'stopped';

    /** Days an import is kept for the page once it has finished. */
    public const KEEPDAYS = 30;

    /** How many finished imports the page shows. */
    public const RECENT = 5;

    /** @var \core\lock\lock_factory|null The locks of the imports, one factory per process. */
    private static $locks = null;

    /** What an import counts. */
    private const COUNTS = ['familiesnew', 'adultsnew', 'adultschanged', 'childrennew', 'childrenchanged', 'childrenleaving',
        'errors'];

    /**
     * Keep a file that has just been looked at, with what its preview said, until it is applied or left.
     *
     * @param string $text The file.
     * @param string $filename Its name, as it was loaded.
     * @param bool $complete Whether it is the complete list of its source.
     * @param array $plan Its plan, from importer::plan().
     * @param int $rows How many rows of people it has.
     * @param int $userid Who looked at it.
     * @param string $source Where it comes from.
     * @return int The import.
     */
    public static function prepare(
        string $text,
        string $filename,
        bool $complete,
        array $plan,
        int $rows,
        int $userid,
        string $source = 'csv'
    ): int {
        global $DB;

        $now = time();
        $filename = \core_text::substr(clean_param($filename, PARAM_FILE), 0, 255);
        $jobid = (int) $DB->insert_record('local_oksigeniafamily_import', (object) [
            'userid' => $userid,
            'source' => $source,
            'filename' => $filename === '' ? null : $filename,
            'complete' => $complete ? 1 : 0,
            'status' => self::PREVIEW,
            'filerows' => $rows,
            'guarded' => empty($plan['guarded']) ? 0 : 1,
            'leaving' => count($plan['leaving']),
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        get_file_storage()->create_file_from_string(self::filerecord($jobid), $text);

        return $jobid;
    }

    /**
     * An import.
     *
     * @param int $jobid The import.
     * @return \stdClass|null
     */
    public static function get(int $jobid): ?\stdClass {
        global $DB;

        return $DB->get_record('local_oksigeniafamily_import', ['id' => $jobid]) ?: null;
    }

    /**
     * Leave a file that was only looked at: the file goes, and nothing changes.
     *
     * @param int $jobid The import.
     * @param int $userid Who looked at it: nobody else can leave it.
     * @return void
     */
    public static function cancel(int $jobid, int $userid): void {
        global $DB;

        if ($DB->record_exists('local_oksigeniafamily_import', ['id' => $jobid, 'userid' => $userid, 'status' => self::PREVIEW])) {
            self::erase($jobid);
        }
    }

    /**
     * Whether the brake of a complete list lets a file through: when the preview asked for it, the number typed has to be
     * how many children leave.
     *
     * @param bool $guarded Whether the preview asked for it.
     * @param int $leaving How many children leave.
     * @param int|null $typed The number typed, if any.
     * @return bool
     */
    public static function confirmed(bool $guarded, int $leaving, ?int $typed): bool {
        return !$guarded || $typed === $leaving;
    }

    /**
     * Apply a file that was looked at. The brake comes first, against what the preview said; then a small file goes in at
     * once, as it always did, and a big one waits for the background task.
     *
     * @param int $jobid The import.
     * @param int|null $typed The number of children leaving, as typed.
     * @param int $userid Who applies it: the person who looked at it.
     * @return \stdClass The import: done, with what it did, or queued.
     * @throws \moodle_exception import:gone when it is no longer waiting, import:guard:mismatch when the number typed is not
     *     how many children leave (then the file goes, and nothing changes).
     */
    public static function start(int $jobid, ?int $typed, int $userid): \stdClass {
        global $DB;

        $job = $DB->get_record('local_oksigeniafamily_import', ['id' => $jobid, 'userid' => $userid, 'status' => self::PREVIEW]);
        if (!$job || !self::file($job)) {
            throw new \moodle_exception('import:gone', 'local_oksigeniafamily');
        }
        if (!self::confirmed((bool) $job->guarded, (int) $job->leaving, $typed)) {
            self::erase($jobid);
            throw new \moodle_exception('import:guard:mismatch', 'local_oksigeniafamily');
        }
        $job->confirmed = $job->guarded ? $typed : null;

        // Two imports never go in at the same time: a small file waits too while a big one is going in.
        if ((int) $job->filerows <= self::NOWROWS) {
            $lock = self::lock();
            if ($lock) {
                try {
                    return self::apply_now($job, $typed);
                } finally {
                    $lock->release();
                }
            }
        }
        $job->status = self::QUEUED;
        $job->timemodified = time();
        $DB->update_record('local_oksigeniafamily_import', $job);
        import_apply::queue($jobid);

        return $job;
    }

    /**
     * Apply a small file in one go, inside the page: its plan again, the brake against it, and all of it or nothing.
     *
     * @param \stdClass $job The import.
     * @param int|null $typed The number of children leaving, as typed.
     * @return \stdClass The import, done.
     */
    private static function apply_now(\stdClass $job, ?int $typed): \stdClass {
        $read = csv_file::read(self::file($job)->get_content());
        $plan = importer::plan($read['rows'], $job->source, (bool) $job->complete);
        if ($plan['guarded'] && $typed !== count($plan['leaving'])) {
            self::erase((int) $job->id);
            throw new \moodle_exception('import:guard:mismatch', 'local_oksigeniafamily');
        }
        importer::apply($plan, $job->source);

        $job->timestarted = time();
        $job->families = $job->familiesdone = count($plan['families']);
        $job->leavingtotal = $job->leavingdone = count($plan['leaving']);
        $counts = array_intersect_key($plan['counts'], array_flip(self::COUNTS));
        self::finish($job, self::DONE, null, $counts, false);

        return $job;
    }

    /**
     * Apply an import in the background: its plan again from the file, then the families by batches, each batch in its
     * own transaction, and then the children who leave, also by batches. The page shows how far it got after each one.
     *
     * The first time, the brake of a complete list looks again at the records as they are now: if as many children would
     * no longer leave as the person confirmed, nothing is applied. Once it has started, running it again after a failure
     * goes on without asking: the families already in are found as they are, and the children who left are no longer there.
     *
     * @param int $jobid The import.
     * @param int $batch Families, or children leaving, per batch.
     * @param callable|null $afterbatch Called after each batch with the import, as it stands.
     * @return bool False when another import is going in, so that this one has to wait its turn.
     */
    public static function run(int $jobid, int $batch = self::BATCH, ?callable $afterbatch = null): bool {
        $job = self::get($jobid);
        if (!$job || !in_array($job->status, [self::QUEUED, self::RUNNING, self::FAILED], true)) {
            return true;
        }
        $lock = self::lock();
        if (!$lock) {
            return false;
        }
        try {
            self::run_locked($job, max(1, $batch), $afterbatch);
        } finally {
            $lock->release();
        }

        return true;
    }

    /**
     * Apply an import in the background, with the lock held.
     *
     * @param \stdClass $job The import.
     * @param int $batch Families, or children leaving, per batch.
     * @param callable|null $afterbatch Called after each batch.
     * @return void
     */
    private static function run_locked(\stdClass $job, int $batch, ?callable $afterbatch): void {
        global $DB;

        \core_php_time_limit::raise();
        raise_memory_limit(MEMORY_HUGE);
        $file = self::file($job);
        if (!$file) {
            self::finish($job, self::STOPPED, 'import:job:nofile');
            return;
        }
        $read = csv_file::read($file->get_content());
        $plan = importer::plan($read['rows'], $job->source, (bool) $job->complete);
        $first = empty($job->timestarted);
        $again = $job->status !== self::QUEUED;
        $leaving = count($plan['leaving']);
        if ($first && $plan['guarded'] && ($job->confirmed === null || (int) $job->confirmed !== $leaving)) {
            self::finish($job, self::STOPPED, 'import:job:changed');
            return;
        }

        $counts = $first ? array_fill_keys(self::COUNTS, 0) : self::counts($job);
        if ($first) {
            $counts['errors'] = count($plan['errors']);
        }
        $now = time();
        $job->status = self::RUNNING;
        $job->timestarted = $job->timestarted ?: $now;
        $job->families = count($plan['families']);
        $job->familiesdone = 0;
        $job->leavingtotal = (int) $job->leavingdone + $leaving;
        $job->counts = json_encode($counts);
        $job->error = null;
        $job->timemodified = $now;
        $DB->update_record('local_oksigeniafamily_import', $job);

        try {
            foreach (array_chunk($plan['families'], $batch) as $families) {
                $transaction = $DB->start_delegated_transaction();
                try {
                    foreach (importer::apply_families($families, $job->source) as $name => $value) {
                        $counts[$name] += $value;
                    }
                    $job->familiesdone += count($families);
                    self::progress($job, $counts);
                    $transaction->allow_commit();
                } catch (\Throwable $e) {
                    $transaction->rollback($e);
                }
                if ($afterbatch) {
                    $afterbatch($job);
                }
            }
            foreach (array_chunk($plan['leaving'], $batch) as $children) {
                $transaction = $DB->start_delegated_transaction();
                try {
                    $counts['childrenleaving'] += importer::apply_leaving($children);
                    $job->leavingdone += count($children);
                    self::progress($job, $counts);
                    $transaction->allow_commit();
                } catch (\Throwable $e) {
                    $transaction->rollback($e);
                }
                if ($afterbatch) {
                    $afterbatch($job);
                }
            }
        } catch (\Throwable $e) {
            // What went in stays, as the last batch that went in left it; Moodle runs the task again later, and the person
            // hears of it the first time only.
            $job = self::get((int) $job->id);
            $job->status = self::FAILED;
            $job->error = \core_text::substr($e->getMessage(), 0, 1000);
            $job->timemodified = time();
            $DB->update_record('local_oksigeniafamily_import', $job);
            if (!$again) {
                self::notify($job);
            }
            throw $e;
        }
        self::finish($job, self::DONE, null, $counts);
    }

    /**
     * Keep how far an import got, inside the transaction of its batch: what went in and the count of it go together.
     *
     * @param \stdClass $job The import.
     * @param int[] $counts What it did so far.
     * @return void
     */
    private static function progress(\stdClass $job, array $counts): void {
        global $DB;

        $job->counts = json_encode($counts);
        $job->timemodified = time();
        $DB->update_record('local_oksigeniafamily_import', (object) [
            'id' => $job->id,
            'familiesdone' => $job->familiesdone,
            'leavingdone' => $job->leavingdone,
            'counts' => $job->counts,
            'timemodified' => $job->timemodified,
        ]);
    }

    /**
     * Close an import: done or stopped, with what it did. Its file goes, and whoever applied it in the background is told.
     *
     * @param \stdClass $job The import.
     * @param string $status done or stopped.
     * @param string|null $error Why it stopped: a string of the plugin, shown in the language of whoever reads it.
     * @param int[]|null $counts What it did, if anything.
     * @param bool $notify Whether to tell whoever applied it.
     * @return void
     */
    private static function finish(
        \stdClass $job,
        string $status,
        ?string $error,
        ?array $counts = null,
        bool $notify = true
    ): void {
        global $DB;

        $now = time();
        $job->status = $status;
        $job->error = $error;
        if ($counts !== null) {
            $job->counts = json_encode($counts);
        }
        $job->timefinished = $now;
        $job->timemodified = $now;
        $DB->update_record('local_oksigeniafamily_import', $job);
        self::erase_file((int) $job->id);
        if ($notify) {
            self::notify($job);
        }
    }

    /**
     * What an import did so far.
     *
     * @param \stdClass $job The import.
     * @return int[] familiesnew, adultsnew, adultschanged, childrennew, childrenchanged, childrenleaving and errors.
     */
    public static function counts(\stdClass $job): array {
        $stored = json_decode((string) ($job->counts ?? ''), true);
        $counts = array_fill_keys(self::COUNTS, 0);
        foreach ($counts as $name => $zero) {
            $counts[$name] = is_array($stored) ? (int) ($stored[$name] ?? 0) : 0;
        }

        return $counts;
    }

    /**
     * Whether Moodle is still going to run the task of an import that stopped by an error: it gives up after a number
     * of tries.
     *
     * @param int $jobid The import.
     * @return bool
     */
    public static function will_retry(int $jobid): bool {
        foreach (\core\task\manager::get_adhoc_tasks(import_apply::class) as $task) {
            if ((int) ($task->get_custom_data()->jobid ?? 0) === $jobid) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tell whoever applied an import how it ended, or that it stopped: with the app, by e-mail or on the site, as they
     * chose.
     *
     * @param \stdClass $job The import.
     * @return void
     */
    private static function notify(\stdClass $job): void {
        $user = \core_user::get_user((int) $job->userid);
        if (!$user || !empty($user->deleted) || !empty($user->suspended)) {
            return;
        }
        // The notice is for those who manage the families: someone who no longer does is not told.
        if (!has_capability('local/oksigeniafamily:manage', \context_system::instance(), $user)) {
            return;
        }
        $previous = force_current_language(!empty($user->lang) ? $user->lang : '');
        try {
            $component = 'local_oksigeniafamily';
            $a = (object) (self::counts($job) + [
                'file' => (string) ($job->filename ?: get_string('import:job:nofilename', $component)),
                'done' => (int) $job->familiesdone,
                'total' => (int) $job->families,
                'reason' => self::reason($job),
            ]);
            $kind = $job->status === self::DONE ? 'done' : ($job->status === self::FAILED ? 'failed' : 'stopped');
            $text = get_string('import:notify:' . $kind, $component, $a);
            $url = new \moodle_url('/local/oksigeniafamily/import.php', ['job' => $job->id]);

            $message = new \core\message\message();
            $message->component = $component;
            $message->name = 'import';
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = $user;
            $message->subject = get_string('import:notify:subject:' . $kind, $component);
            $message->fullmessage = $text . "\n\n" . $url->out(false);
            $message->fullmessageformat = FORMAT_PLAIN;
            $link = \html_writer::link($url, get_string('import:notify:see', $component));
            $message->fullmessagehtml = \html_writer::tag('p', s($text)) . \html_writer::tag('p', $link);
            $message->smallmessage = $text;
            $message->notification = 1;
            $message->contexturl = $url->out(false);
            $message->contexturlname = get_string('import:notify:see', $component);
            $message->courseid = SITEID;
            message_send($message);
        } finally {
            force_current_language($previous);
        }
    }

    /**
     * The imports the page shows: those on their way, of anyone, and the last that finished.
     *
     * @param int $now The time.
     * @return \stdClass[]
     */
    public static function recent(int $now): array {
        global $DB;

        [$busyin, $busyparams] = $DB->get_in_or_equal([self::QUEUED, self::RUNNING, self::FAILED], SQL_PARAMS_NAMED, 'busy');
        $busy = $DB->get_records_select('local_oksigeniafamily_import', "status $busyin", $busyparams, 'id DESC');
        [$endin, $endparams] = $DB->get_in_or_equal([self::DONE, self::STOPPED], SQL_PARAMS_NAMED, 'end');
        $finished = $DB->get_records_select(
            'local_oksigeniafamily_import',
            "status $endin AND timemodified > :since",
            $endparams + ['since' => $now - self::KEEPDAYS * DAYSECS],
            'id DESC',
            '*',
            0,
            self::RECENT
        );

        return array_values($busy + $finished);
    }

    /**
     * An import as the pages show it.
     *
     * @param \stdClass $job The import.
     * @return array For the templates local_oksigeniafamily/import_job and local_oksigeniafamily/import.
     */
    public static function export(\stdClass $job): array {
        $component = 'local_oksigeniafamily';
        $counts = self::counts($job);
        $stats = [];
        foreach (['familiesnew', 'adultsnew', 'adultschanged', 'childrennew', 'childrenleaving', 'errors'] as $name) {
            if ($counts[$name] > 0 || in_array($name, ['familiesnew', 'adultsnew', 'childrennew'], true)) {
                $stats[] = [
                    'value' => self::number($counts[$name]),
                    'label' => get_string('import:stat:' . $name, $component),
                    'bad' => $name === 'errors',
                ];
            }
        }
        $busy = in_array($job->status, [self::QUEUED, self::RUNNING], true);
        $progress = [];
        // Waiting, it does not know its families yet: it reads the file when it starts.
        if ($job->status === self::RUNNING || $job->status === self::FAILED) {
            $progress[] = self::bar('import:job:families', (int) $job->familiesdone, (int) $job->families);
            if ((int) $job->leavingtotal > 0) {
                $progress[] = self::bar('import:job:leaving', (int) $job->leavingdone, (int) $job->leavingtotal);
            }
        }
        $user = \core_user::get_user((int) $job->userid);
        $note = '';
        if ($job->status === self::FAILED) {
            $retry = self::will_retry((int) $job->id) ? 'import:job:failed:retry' : 'import:job:failed:over';
            $note = get_string($retry, $component, (string) $job->error);
        } else if ($job->status === self::STOPPED) {
            $note = self::reason($job);
        }
        $kinds = [
            self::QUEUED => 'muted',
            self::RUNNING => 'new',
            self::DONE => 'ok',
            self::FAILED => 'bad',
            self::STOPPED => 'warn',
        ];

        return [
            'id' => (int) $job->id,
            'url' => (new \moodle_url('/local/oksigeniafamily/import.php', ['job' => $job->id]))->out(false),
            'filename' => (string) ($job->filename ?: get_string('import:job:nofilename', $component)),
            'status' => get_string('import:job:' . $job->status, $component),
            'kind' => $kinds[$job->status] ?? 'muted',
            'busy' => $busy,
            'finished' => $job->status === self::DONE,
            'by' => get_string('import:job:by', $component, (object) [
                'name' => $user && empty($user->deleted) ? fullname($user) : get_string('notice:someone', $component),
                'time' => userdate((int) $job->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
            ]),
            'progress' => $progress,
            'hasprogress' => !empty($progress),
            'progresslabel' => $busy && $progress ? $progress[0]['label'] : '',
            'stats' => $stats,
            'hasstats' => $job->status !== self::QUEUED,
            'note' => $note,
            'complete' => !empty($job->complete),
        ];
    }

    /**
     * Why an import stopped, in the language in use: one of the plugin's strings, or the error as it came.
     *
     * @param \stdClass $job The import.
     * @return string
     */
    private static function reason(\stdClass $job): string {
        $error = (string) ($job->error ?? '');
        if ($error !== '' && get_string_manager()->string_exists($error, 'local_oksigeniafamily')) {
            return get_string($error, 'local_oksigeniafamily');
        }

        return $error;
    }

    /**
     * A bar of progress.
     *
     * @param string $string The string, with done and total.
     * @param int $done How many went.
     * @param int $total Out of how many.
     * @return array label, done, total and percent.
     */
    private static function bar(string $string, int $done, int $total): array {
        $percent = $total > 0 ? (int) floor(100 * min($done, $total) / $total) : 0;

        return [
            'label' => get_string($string, 'local_oksigeniafamily', (object) [
                'done' => self::number($done),
                'total' => self::number($total),
            ]),
            'done' => $done,
            'total' => $total,
            'percent' => $percent,
        ];
    }

    /**
     * A whole number as people read it in the language in use: 12,500 or 12.500.
     *
     * @param int $number The number.
     * @return string
     */
    private static function number(int $number): string {
        return number_format($number, 0, get_string('decsep', 'langconfig'), get_string('thousandssep', 'langconfig'));
    }

    /**
     * Erase the old imports: previews nobody applied, after a day, and the rest once they are past their days on the page.
     * An import that is still going in is never erased.
     *
     * @param int $now The time.
     * @return int How many.
     */
    public static function purge(int $now): int {
        global $DB;

        [$endin, $params] = $DB->get_in_or_equal([self::DONE, self::STOPPED, self::FAILED], SQL_PARAMS_NAMED, 'end');
        $ids = $DB->get_fieldset_select(
            'local_oksigeniafamily_import',
            'id',
            "(status = :preview AND timemodified < :previewbefore) OR (status $endin AND timemodified < :before)",
            $params + [
                'preview' => self::PREVIEW,
                'previewbefore' => $now - DAYSECS,
                'before' => $now - self::KEEPDAYS * DAYSECS,
            ]
        );
        foreach ($ids as $id) {
            if (!self::will_retry((int) $id)) {
                self::erase((int) $id);
            }
        }

        return count($ids);
    }

    /**
     * The file of an import, if it is still there.
     *
     * @param \stdClass $job The import.
     * @return \stored_file|null
     */
    private static function file(\stdClass $job): ?\stored_file {
        $record = self::filerecord((int) $job->id);
        $file = get_file_storage()->get_file(
            $record['contextid'],
            $record['component'],
            $record['filearea'],
            $record['itemid'],
            $record['filepath'],
            $record['filename']
        );

        return $file ?: null;
    }

    /**
     * Where the file of an import is kept: in the plugin's own area, not in the person's drafts, which Moodle empties
     * after a few days.
     *
     * @param int $jobid The import.
     * @return array
     */
    private static function filerecord(int $jobid): array {
        return [
            'contextid' => \context_system::instance()->id,
            'component' => 'local_oksigeniafamily',
            'filearea' => self::AREA,
            'itemid' => $jobid,
            'filepath' => '/',
            'filename' => 'families.csv',
        ];
    }

    /**
     * Erase the file of an import.
     *
     * @param int $jobid The import.
     * @return void
     */
    private static function erase_file(int $jobid): void {
        get_file_storage()->delete_area_files(\context_system::instance()->id, 'local_oksigeniafamily', self::AREA, $jobid);
    }

    /**
     * Erase an import with its file.
     *
     * @param int $jobid The import.
     * @return void
     */
    private static function erase(int $jobid): void {
        global $DB;

        self::erase_file($jobid);
        $DB->delete_records('local_oksigeniafamily_import', ['id' => $jobid]);
    }

    /**
     * The lock that keeps two imports from going in at the same time, if it is free. The factory is one per process, so
     * that a process never takes the lock twice: some databases would let the same connection stack it.
     *
     * @return \core\lock\lock|null
     */
    public static function lock(): ?\core\lock\lock {
        if (self::$locks === null) {
            self::$locks = \core\lock\lock_config::get_lock_factory('local_oksigeniafamily_import');
        }
        $lock = self::$locks->get_lock('apply', 0);

        return $lock ?: null;
    }
}
