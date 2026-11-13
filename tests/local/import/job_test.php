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

use local_oksigeniafamily\api;
use local_oksigeniafamily\task\import_apply;

/**
 * Tests for applying an import: a small file at once, a big one in the background by batches, what a failure halfway
 * leaves, the brake of a complete list before queueing and again before applying, and erasing the old ones.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_oksigeniafamily\local\import\job
 * @covers \local_oksigeniafamily\task\import_apply
 */
#[\PHPUnit\Framework\Attributes\CoversClass(job::class)]
final class job_test extends \advanced_testcase {
    /**
     * A CSV text.
     *
     * @param string[] $lines The lines after the headings.
     * @return string
     */
    private function csv(array $lines): string {
        return 'family,type,firstname,lastname,email,phone,username,idnumber,key' . "\n" . implode("\n", $lines) . "\n";
    }

    /**
     * Families of example data, each with an adult and two children without an account: three rows each.
     *
     * @param int $from The first family.
     * @param int $to The last family.
     * @return string[] Lines.
     */
    private function families(int $from, int $to): array {
        $lines = [];
        for ($i = $from; $i <= $to; $i++) {
            $lines[] = "F$i,adult,Adult$i,Example,adult$i@example.com,,,,";
            $lines[] = "F$i,child,,,,,,,S-$i-a";
            $lines[] = "F$i,child,,,,,,,S-$i-b";
        }

        return $lines;
    }

    /**
     * Somebody who manages the families, as whoever imports them does.
     *
     * @return \stdClass
     */
    private function manager(): \stdClass {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign($DB->get_field('role', 'id', ['shortname' => 'manager']), $user->id);

        return $user;
    }

    /**
     * Look at a file as the import page does, and keep it.
     *
     * @param string $text The file.
     * @param int $userid Who looks at it.
     * @param bool $complete Whether it is the complete list.
     * @return int The import.
     */
    private function prepare(string $text, int $userid, bool $complete = false): int {
        $read = csv_file::read($text);
        $plan = importer::plan($read['rows'], 'csv', $complete);

        return job::prepare($text, 'families.csv', $complete, $plan, count($read['rows']), $userid);
    }

    /**
     * What is in the tables.
     *
     * @return int[] Families, adults and children.
     */
    private function totals(): array {
        global $DB;

        return [
            $DB->count_records('local_oksigeniafamily_family'),
            $DB->count_records('local_oksigeniafamily_adult'),
            $DB->count_records('local_oksigeniafamily_child'),
        ];
    }

    /**
     * The tasks of the imports waiting to run.
     *
     * @return int
     */
    private function queued(): int {
        return count(\core\task\manager::get_adhoc_tasks(import_apply::class));
    }

    /**
     * Whether the file of an import is still kept.
     *
     * @param int $jobid The import.
     * @return bool
     */
    private function has_file(int $jobid): bool {
        $context = \context_system::instance();

        return !empty(get_file_storage()->get_area_files($context->id, 'local_oksigeniafamily', job::AREA, $jobid, '', false));
    }

    /**
     * A small file goes in at once, inside the page, as it always did; nothing waits in the background.
     */
    public function test_small_file_at_once(): void {
        $this->resetAfterTest();
        $user = $this->manager();

        $jobid = $this->prepare($this->csv($this->families(1, 10)), (int) $user->id);
        $this->assertSame(job::PREVIEW, job::get($jobid)->status);
        $this->assertTrue($this->has_file($jobid));
        $this->assertSame([0, 0, 0], $this->totals());

        $job = job::start($jobid, null, (int) $user->id);
        $this->assertSame(job::DONE, $job->status);
        $this->assertSame([10, 10, 20], $this->totals());
        $this->assertSame(10, job::counts($job)['familiesnew']);
        $this->assertSame(20, job::counts($job)['childrennew']);
        $this->assertFalse($this->has_file($jobid));
        $this->assertSame(0, $this->queued());

        // Somebody else cannot apply or leave a file another person looked at.
        $other = $this->prepare($this->csv($this->families(11, 12)), (int) $user->id);
        job::cancel($other, (int) $user->id + 1);
        $this->assertNotNull(job::get($other));
        try {
            job::start($other, null, (int) $user->id + 1);
            $this->fail('Applied by somebody else');
        } catch (\moodle_exception $e) {
            $this->assertSame('import:gone', $e->errorcode);
        }
        job::cancel($other, (int) $user->id);
        $this->assertNull(job::get($other));
        $this->assertFalse($this->has_file($other));
    }

    /**
     * A big file waits for the background task, which goes through it by batches, one transaction each, and says how far
     * it got after each one; whoever applied it is told when it is done.
     */
    public function test_big_file_by_batches(): void {
        $this->resetAfterTest();
        $user = $this->manager();
        $messages = $this->redirectMessages();

        $text = $this->csv($this->families(1, 70));
        $jobid = $this->prepare($text, (int) $user->id);
        $job = job::start($jobid, null, (int) $user->id);
        $this->assertSame(job::QUEUED, $job->status);
        $this->assertSame(1, $this->queued());
        $this->assertSame([0, 0, 0], $this->totals());
        $this->assertTrue(job::export(job::get($jobid))['busy']);

        $seen = [];
        $this->assertTrue(job::run($jobid, 25, function (\stdClass $job) use (&$seen) {
            global $DB;
            // What went in so far is in the tables, with its count.
            $seen[] = [(int) $job->familiesdone, $DB->count_records('local_oksigeniafamily_family')];
        }));
        $this->assertSame([[25, 25], [50, 50], [70, 70]], $seen);

        $job = job::get($jobid);
        $this->assertSame(job::DONE, $job->status);
        $this->assertSame([70, 70, 140], $this->totals());
        $counts = job::counts($job);
        $this->assertSame(70, $counts['familiesnew']);
        $this->assertSame(70, $counts['adultsnew']);
        $this->assertSame(140, $counts['childrennew']);
        $this->assertFalse($this->has_file($jobid));
        $this->assertFalse(job::export($job)['busy']);

        $sent = array_values(array_filter($messages->get_messages(), fn($message) => $message->eventtype === 'import'));
        $this->assertCount(1, $sent);
        $this->assertEquals($user->id, $sent[0]->useridto);
        $this->assertStringContainsString('70', $sent[0]->fullmessage);

        // Running it again changes nothing: it is done.
        $this->assertTrue(job::run($jobid));
        $this->assertSame([70, 70, 140], $this->totals());
    }

    /**
     * The task through Moodle's queue, as cron runs it.
     */
    public function test_task(): void {
        $this->resetAfterTest();
        $user = $this->manager();
        $this->redirectMessages();

        $jobid = $this->prepare($this->csv($this->families(1, 70)), (int) $user->id);
        job::start($jobid, null, (int) $user->id);
        ob_start();
        $this->runAdhocTasks(import_apply::class);
        ob_end_clean();
        $this->assertSame(job::DONE, job::get($jobid)->status);
        $this->assertSame([70, 70, 140], $this->totals());
        $this->assertSame(0, $this->queued());
    }

    /**
     * A failure halfway leaves the batches that went in, and the file; running it again goes on without duplicating
     * anything, and loading the same file again finds everything already there. The person hears of the failure once.
     */
    public function test_failure_halfway_and_repeat(): void {
        $this->resetAfterTest();
        $user = $this->manager();
        $messages = $this->redirectMessages();

        $text = $this->csv($this->families(1, 70));
        $jobid = $this->prepare($text, (int) $user->id);
        job::start($jobid, null, (int) $user->id);

        $fail = function (\stdClass $job) {
            throw new \RuntimeException('The database went away');
        };
        // It fails twice, as a task that Moodle runs again would.
        foreach ([1, 2] as $try) {
            try {
                job::run($jobid, 20, $fail);
                $this->fail('It did not stop');
            } catch (\RuntimeException $e) {
                $this->assertSame('The database went away', $e->getMessage());
            }
        }
        $job = job::get($jobid);
        $this->assertSame(job::FAILED, $job->status);
        $this->assertSame(20, (int) $job->familiesdone);
        $this->assertSame([20, 20, 40], $this->totals());
        $this->assertTrue($this->has_file($jobid));
        $this->assertNotEmpty(job::export($job)['note']);
        $failed = array_filter($messages->get_messages(), fn($message) => $message->eventtype === 'import');
        $this->assertCount(1, $failed);

        // Moodle runs it again: it goes on from there, without duplicating the twenty already in.
        $messages->clear();
        $this->assertTrue(job::run($jobid, 20));
        $job = job::get($jobid);
        $this->assertSame(job::DONE, $job->status);
        $this->assertSame([70, 70, 140], $this->totals());
        $this->assertSame(70, job::counts($job)['familiesnew']);
        $this->assertSame(140, job::counts($job)['childrennew']);
        $this->assertCount(1, array_filter($messages->get_messages(), fn($message) => $message->eventtype === 'import'));

        // The same file loaded again: nothing new, nothing duplicated.
        $again = $this->prepare($text, (int) $user->id);
        job::start($again, null, (int) $user->id);
        $this->assertTrue(job::run($again));
        $this->assertSame([70, 70, 140], $this->totals());
        $this->assertSame(0, job::counts(job::get($again))['familiesnew']);
        $this->assertSame(0, job::counts(job::get($again))['childrennew']);
    }

    /**
     * The brake of a complete list is checked before queueing: a wrong number applies nothing and queues nothing. Then,
     * before applying anything, the task looks again: if the children leaving are no longer the number confirmed, it stops
     * without touching anything.
     */
    public function test_guard_before_queueing_and_before_applying(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->manager();
        $messages = $this->redirectMessages();

        // Thirty families, one child each.
        $first = [];
        for ($i = 1; $i <= 30; $i++) {
            $first[] = "G$i,adult,Parent$i,Example,,,,,";
            $first[] = "G$i,child,,,,,,,T-$i";
        }
        importer::apply(importer::plan(csv_file::read($this->csv($first))['rows']));

        // A complete list with seventy new families and only twenty of the thirty: ten would leave, more than a fifth.
        $lines = array_merge($this->families(1, 70), array_slice($first, 0, 40));
        $text = $this->csv($lines);
        $jobid = $this->prepare($text, (int) $user->id, true);
        $job = job::get($jobid);
        $this->assertSame(1, (int) $job->guarded);
        $this->assertSame(10, (int) $job->leaving);

        try {
            job::start($jobid, 9, (int) $user->id);
            $this->fail('Applied with the wrong number');
        } catch (\moodle_exception $e) {
            $this->assertSame('import:guard:mismatch', $e->errorcode);
        }
        $this->assertNull(job::get($jobid));
        $this->assertFalse($this->has_file($jobid));
        $this->assertSame(0, $this->queued());
        $this->assertSame([30, 30, 30], $this->totals());

        // With the right number it waits for the task; but meanwhile somebody takes one of the ten out by hand.
        $jobid = $this->prepare($text, (int) $user->id, true);
        $this->assertSame(job::QUEUED, job::start($jobid, 10, (int) $user->id)->status);
        $this->assertSame(1, $this->queued());
        $childid = (int) $DB->get_field('local_oksigeniafamily_child', 'id', ['sourcekey' => 'csv:T-30']);
        api::unlink_child($childid, 'manual');
        $this->assertTrue(job::run($jobid));
        $job = job::get($jobid);
        $this->assertSame(job::STOPPED, $job->status);
        $this->assertSame([30, 30, 30], $this->totals());
        $this->assertSame(0, (int) $job->familiesdone);
        $this->assertFalse($this->has_file($jobid));
        $this->assertSame(
            get_string('import:job:changed', 'local_oksigeniafamily'),
            job::export($job)['note']
        );
        $this->assertCount(1, array_filter($messages->get_messages(), fn($message) => $message->eventtype === 'import'));

        // Loaded again, the brake asks for nine, and with nine it all goes in.
        $jobid = $this->prepare($text, (int) $user->id, true);
        $this->assertSame(9, (int) job::get($jobid)->leaving);
        job::start($jobid, 9, (int) $user->id);
        $this->assertTrue(job::run($jobid, 30));
        $job = job::get($jobid);
        $this->assertSame(job::DONE, $job->status);
        $this->assertSame(9, job::counts($job)['childrenleaving']);
        $this->assertSame(9, (int) $job->leavingdone);
        // Of the thirty, the twenty in the file stay.
        $this->assertSame(20, $DB->count_records_select(
            'local_oksigeniafamily_child',
            'status = :active AND ' . $DB->sql_like('sourcekey', ':key'),
            ['active' => api::ACTIVE, 'key' => 'csv:T-%']
        ));
        $this->assertSame([100, 100, 170], $this->totals());
    }

    /**
     * While a big import goes in, a small one waits its turn in the background instead of going in at the same time.
     */
    public function test_one_at_a_time(): void {
        $this->resetAfterTest();
        $user = $this->manager();

        $lock = job::lock();
        $this->assertNotNull($lock);
        try {
            $jobid = $this->prepare($this->csv($this->families(1, 3)), (int) $user->id);
            $this->assertSame(job::QUEUED, job::start($jobid, null, (int) $user->id)->status);
            $this->assertFalse(job::run($jobid));
            $this->assertSame([0, 0, 0], $this->totals());
        } finally {
            $lock->release();
        }
        $this->assertTrue(job::run($jobid));
        $this->assertSame([3, 3, 6], $this->totals());
    }

    /**
     * The daily round erases the previews nobody applied, after a day, and the finished imports after their days on the
     * page; never one still on its way.
     */
    public function test_purge(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->manager();
        $now = time();

        $old = $this->prepare($this->csv($this->families(1, 2)), (int) $user->id);
        $fresh = $this->prepare($this->csv($this->families(3, 4)), (int) $user->id);
        $done = $this->prepare($this->csv($this->families(5, 6)), (int) $user->id);
        job::start($done, null, (int) $user->id);
        $waiting = $this->prepare($this->csv($this->families(7, 76)), (int) $user->id);
        job::start($waiting, null, (int) $user->id);
        $DB->set_field('local_oksigeniafamily_import', 'timemodified', $now - DAYSECS - 60, ['id' => $old]);
        $DB->set_field('local_oksigeniafamily_import', 'timemodified', $now - (job::KEEPDAYS + 1) * DAYSECS, ['id' => $done]);
        $DB->set_field('local_oksigeniafamily_import', 'timemodified', $now - (job::KEEPDAYS + 1) * DAYSECS, ['id' => $waiting]);

        job::purge($now);
        $this->assertNull(job::get($old));
        $this->assertFalse($this->has_file($old));
        $this->assertNull(job::get($done));
        $this->assertNotNull(job::get($fresh));
        $this->assertTrue($this->has_file($fresh));
        $this->assertNotNull(job::get($waiting));

        // The page lists the one on its way.
        $recent = job::recent($now);
        $this->assertSame([(int) $waiting], array_map(fn($job) => (int) $job->id, $recent));
    }
}
