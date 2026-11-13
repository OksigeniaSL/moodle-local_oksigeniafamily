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

namespace local_oksigeniafamily\local;

use local_oksigeniafamily\api;
use local_oksigeniafamily\task\consent_send;

/**
 * Tests for the requests for consent: answers, how each student stands, the personal links, what is sent, the answers
 * from WhatsApp and Telegram, the reminder and the retention.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_oksigeniafamily\local\consent
 * @covers \local_oksigeniafamily\local\consent_link
 * @covers \local_oksigeniafamily\local\consent_notices
 * @covers \local_oksigeniafamily\local\consent_marandu
 * @covers \local_oksigeniafamily\local\consent_view
 * @covers \local_oksigeniafamily\task\consent_send
 */
#[\PHPUnit\Framework\Attributes\CoversClass(consent::class)]
final class consent_test extends \advanced_testcase {
    /**
     * A family with two adults (one with an account, one with only an e-mail) and a student, another student with no
     * family, and a request about both made by a teacher.
     *
     * @param bool $needall Whether every adult has to consent.
     * @return \stdClass ana, leo (students), parent (the account), teacher, adultid (with the account), mailid (the
     *                   adult with only an e-mail) and requestid.
     */
    private function setup_family(bool $needall = false): \stdClass {
        $generator = $this->getDataGenerator();
        $data = (object) [
            'ana' => $generator->create_user(['firstname' => 'Ana']),
            'leo' => $generator->create_user(['firstname' => 'Leo']),
            'parent' => $generator->create_user(['firstname' => 'Juan', 'lastname' => 'Ruiz']),
            'teacher' => $generator->create_user(),
        ];
        $familyid = api::create_family();
        $data->adultid = api::add_adult($familyid, ['firstname' => 'Juan', 'lastname' => 'Ruiz', 'userid' => $data->parent->id]);
        $data->mailid = api::add_adult($familyid, ['firstname' => 'Pilar', 'lastname' => 'Sanz', 'email' => 'pilar@example.com']);
        api::add_child($familyid, (int) $data->ana->id);
        $data->requestid = consent::create(\context_system::instance(), [
            'title' => 'Salida al Teide',
            'message' => '<p>Salimos a las 8:30.</p>',
            'deadline' => time() + 3 * DAYSECS,
            'needall' => $needall,
        ], [(int) $data->ana->id, (int) $data->leo->id], (int) $data->teacher->id);

        return $data;
    }

    /**
     * One adult is enough by default; a «no» from the other counts as a no, and whoever made it hears of it once.
     */
    public function test_one_adult_is_enough(): void {
        global $DB;
        $this->resetAfterTest();
        $data = $this->setup_family();
        $request = consent::get($data->requestid);
        $sink = $this->redirectMessages();

        $this->assertSame(consent::PENDING, consent::student_state($request, (int) $data->ana->id)->status);
        consent::answer($data->requestid, (int) $data->ana->id, $data->mailid, consent::YES, 'email', [
            'address' => 'pilar@example.com',
        ]);
        $state = consent::student_state($request, (int) $data->ana->id);
        $this->assertSame(consent::YES, $state->status);
        $this->assertFalse($state->conflict);

        consent::answer($data->requestid, (int) $data->ana->id, $data->adultid, consent::NO, 'app', [
            'userid' => (int) $data->parent->id,
        ]);
        $state = consent::student_state($request, (int) $data->ana->id);
        $this->assertSame(consent::NO, $state->status);
        $this->assertTrue($state->conflict);
        // Another answer while they still disagree tells nobody again.
        consent::answer($data->requestid, (int) $data->ana->id, $data->adultid, consent::NO, 'app', [
            'userid' => (int) $data->parent->id,
        ]);
        $conflicts = array_filter($sink->get_messages(), fn($message) => $message->eventtype === 'consentconflict');
        $this->assertCount(1, $conflicts);
        $this->assertEquals($data->teacher->id, reset($conflicts)->useridto);

        // Changing their mind ends it; every answer stays in the history.
        consent::answer($data->requestid, (int) $data->ana->id, $data->adultid, consent::YES, 'web');
        $this->assertSame(consent::YES, consent::student_state($request, (int) $data->ana->id)->status);
        $this->assertSame(4, $DB->count_records('local_oksigeniafamily_answer', ['requestid' => $data->requestid]));

        $counts = consent::counts(consent::state($request));
        $this->assertSame(1, $counts['yes']);
        $this->assertSame(1, $counts['pending']);
        $this->assertSame(1, $counts['nofamily']);
    }

    /**
     * With needall, every adult has to consent, unless the school writes down a paper answer for the family.
     */
    public function test_every_adult(): void {
        $this->resetAfterTest();
        $data = $this->setup_family(true);
        $request = consent::get($data->requestid);

        consent::answer($data->requestid, (int) $data->ana->id, $data->mailid, consent::YES, 'email');
        $state = consent::student_state($request, (int) $data->ana->id);
        $this->assertSame(consent::PENDING, $state->status);
        $this->assertSame([$data->adultid], $state->missing);

        consent::answer($data->requestid, (int) $data->ana->id, $data->adultid, consent::YES, 'app');
        $this->assertSame(consent::YES, consent::student_state($request, (int) $data->ana->id)->status);

        // Leo has nobody in the records: a paper answer from someone not in them, written down by the school.
        consent::answer($data->requestid, (int) $data->leo->id, 0, consent::YES, 'school', [
            'userid' => (int) $data->teacher->id,
            'name' => 'Marta Gil',
        ]);
        $this->assertSame(consent::YES, consent::student_state($request, (int) $data->leo->id)->status);
    }

    /**
     * What cannot be answered: someone who is not family, a student not in the request, after the deadline, a request
     * called off, and a paper answer with no name.
     */
    public function test_refusals(): void {
        $this->resetAfterTest();
        $data = $this->setup_family();
        $stranger = api::add_adult(api::create_family(), ['firstname' => 'Luis']);
        $other = $this->getDataGenerator()->create_user();

        $refused = function (callable $answer, string $code): void {
            try {
                $answer();
                $this->fail('It should have been refused');
            } catch (\moodle_exception $e) {
                $this->assertSame($code, $e->errorcode);
            }
        };
        $ana = (int) $data->ana->id;
        $refused(fn() => consent::answer($data->requestid, $ana, $stranger, consent::YES, 'web'), 'consentnotfamily');
        $outsider = (int) $other->id;
        $refused(fn() => consent::answer($data->requestid, $outsider, $data->adultid, consent::YES, 'web'), 'consentnotfamily');
        $refused(fn() => consent::answer($data->requestid, $ana, 0, consent::YES, 'school'), 'consentnotfamily');
        $late = time() + 4 * DAYSECS;
        $refused(fn() => consent::answer($data->requestid, $ana, $data->adultid, consent::YES, 'web', [], $late), 'consentclosed');
        consent::cancel($data->requestid);
        $refused(fn() => consent::answer($data->requestid, $ana, $data->adultid, consent::YES, 'web'), 'consentclosed');
    }

    /**
     * A personal link stands for its request and its adult only; changed in any way, it is not valid.
     */
    public function test_link(): void {
        $this->resetAfterTest();

        $token = consent_link::token(12, 34, 'email');
        $this->assertSame(['requestid' => 12, 'adultid' => 34, 'source' => 'email'], consent_link::read($token));
        $this->assertNull(consent_link::read(str_replace('12-34-', '12-35-', $token)));
        $this->assertNull(consent_link::read(str_replace('-email-', '-link-', $token)));
        $this->assertNull(consent_link::read(substr($token, 0, -1) . (substr($token, -1) === '0' ? '1' : '0')));
        $this->assertNull(consent_link::read('nonsense'));
        $this->assertSame(consent_link::token(12, 34, 'email'), $token);

        // Behind a student's key: the student and the key, signed too.
        $key = consent_link::key_url(12, 56, 78)->get_param('t');
        $this->assertSame(['requestid' => 12, 'source' => 'key', 'childuserid' => 56, 'keyid' => 78], consent_link::read($key));
        $this->assertNull(consent_link::read(str_replace('-key78-', '-key79-', $key)));
    }

    /**
     * What another plugin shows behind a student's key: the open requests still waiting for the family, without those
     * that need every adult; and the pages render, for the school, for an adult and behind the key.
     */
    public function test_pending_for_child_and_pages(): void {
        global $DB, $OUTPUT, $PAGE;
        $this->resetAfterTest();
        $data = $this->setup_family();
        $everyone = consent::create(\context_system::instance(), [
            'title' => 'Fotos del curso',
            'deadline' => time() + 5 * DAYSECS,
            'needall' => 1,
        ], [(int) $data->ana->id], (int) $data->teacher->id);
        $now = time();

        $pending = consent::pending_for_child((int) $data->ana->id, 7, $now);
        $this->assertCount(1, $pending);
        $this->assertSame($data->requestid, $pending[0]['requestid']);
        $link = consent_link::read($pending[0]['url']->get_param('t'));
        $this->assertSame((int) $data->ana->id, $link['childuserid']);
        $this->assertSame(7, $link['keyid']);
        consent::answer($data->requestid, (int) $data->ana->id, $data->mailid, consent::YES, 'key', ['address' => 'key:7']);
        $this->assertSame([], consent::pending_for_child((int) $data->ana->id, 7, $now));
        $this->assertCount(1, \local_oksigeniafamily\api::pending_consents((int) $data->leo->id, 7));

        $PAGE->set_url('/local/oksigeniafamily/answer.php');
        $request = consent::get($data->requestid);
        // On the tab of everyone: the report opens on those still missing, and Ana's family has already answered.
        $report = consent_view::report($request, $now, 'all');
        $html = $OUTPUT->render_from_template('local_oksigeniafamily/consent_report', $report + [
            'listurl' => '#', 'actionurl' => '#', 'sesskey' => 'x', 'canremind' => true, 'remindlabel' => 'x',
            'canaddnew' => false, 'cancancel' => true, 'csvurl' => '#', 'pdfurl' => '#',
        ]);
        $this->assertStringContainsString('Pilar Sanz', $html);
        $adult = $DB->get_record('local_oksigeniafamily_adult', ['id' => $data->adultid]);
        $url = new \moodle_url('/local/oksigeniafamily/consent.php', ['id' => $data->requestid]);
        $view = consent_view::answer($request, $adult, [(int) $data->ana->id], $now, $url, ['adultid' => $data->adultid]);
        $html = $OUTPUT->render_from_template('local_oksigeniafamily/consent_answer', $view);
        $this->assertStringContainsString('name="answer"', $html);
        $adults = [];
        foreach (\local_oksigeniafamily\api::adults_of_child((int) $data->ana->id) as $one) {
            $adults[(int) $one->id] = $one;
        }
        $view = consent_view::answer_by_key($request, (int) $data->ana->id, $adults, $now, $url, ['t' => 'x']);
        $html = $OUTPUT->render_from_template('local_oksigeniafamily/consent_answer', $view);
        $this->assertStringContainsString('name="adultid"', $html);
        $this->assertStringContainsString('Pilar Sanz', $html);
        // A request that needs every adult cannot be answered behind the key.
        $view = consent_view::answer_by_key(consent::get($everyone), (int) $data->ana->id, $adults, $now, $url, ['t' => 'x']);
        $html = $OUTPUT->render_from_template('local_oksigeniafamily/consent_answer', $view);
        $this->assertStringNotContainsString('name="answer"', $html);
    }

    /**
     * Sending: the adult with an account gets a notification of Moodle, the one with an e-mail gets their personal link.
     * The reminder only goes to those whose answer is missing.
     */
    public function test_send_and_remind(): void {
        $this->resetAfterTest();
        $data = $this->setup_family(true);
        $messages = $this->redirectMessages();
        $emails = $this->redirectEmails();

        $sent = consent_notices::send($data->requestid);
        $this->assertSame(1, $sent['app']);
        $this->assertSame(1, $sent['email']);
        $this->assertEquals($data->parent->id, $messages->get_messages()[0]->useridto);
        $mail = $emails->get_messages()[0];
        $this->assertSame('pilar@example.com', $mail->to);
        $this->assertStringContainsString('answer.php', quoted_printable_decode($mail->body));

        $messages->clear();
        $emails->clear();
        consent::answer($data->requestid, (int) $data->ana->id, $data->mailid, consent::YES, 'email');
        $sent = consent_notices::send($data->requestid, true);
        $this->assertSame(1, $sent['app']);
        $this->assertSame(0, $sent['email']);
        $this->assertCount(0, $emails->get_messages());
    }

    /**
     * An answer from WhatsApp or Telegram is kept with the number, and the person is told what was kept; a closed
     * request keeps nothing and says so.
     */
    public function test_marandu_answer(): void {
        global $DB;
        $this->resetAfterTest();
        $data = $this->setup_family();
        $ref = $data->requestid . ':' . $data->ana->id;

        $reply = consent_marandu::answer($ref, $data->mailid, consent::YES, 'whatsapp', '+34600000001', time());
        $this->assertStringContainsString('Ana', $reply);
        $row = $DB->get_record('local_oksigeniafamily_answer', ['requestid' => $data->requestid]);
        $this->assertSame('whatsapp', $row->channel);
        $this->assertSame('+34600000001', $row->address);
        $this->assertSame('Pilar Sanz', $row->adultname);

        $this->assertSame(
            get_string('consent:reply:failed', 'local_oksigeniafamily'),
            consent_marandu::answer('nonsense', $data->mailid, consent::YES, 'telegram', 'tg:1', time())
        );
        consent::cancel($data->requestid);
        $request = consent::get($data->requestid);
        $closed = get_string('consent:reply:closed', 'local_oksigeniafamily', (object) [
            'child' => 'Ana',
            'title' => 'Salida al Teide',
            'deadline' => page::date((int) $request->deadline),
        ]);
        $this->assertSame($closed, consent_marandu::answer($ref, $data->mailid, consent::NO, 'telegram', 'tg:1', time()));
        $this->assertSame(1, $DB->count_records('local_oksigeniafamily_answer', ['requestid' => $data->requestid]));
    }

    /**
     * What the person with an account has to answer, the reminder of the day before, and erasing after the retention.
     */
    public function test_for_user_reminders_and_purge(): void {
        global $DB;
        $this->resetAfterTest();
        $data = $this->setup_family();
        $now = time();

        $mine = consent::for_user((int) $data->parent->id, $now);
        $this->assertCount(1, $mine);
        $this->assertSame([(int) $data->ana->id], $mine[0]['children']);
        $this->assertSame([], consent::for_user((int) $data->teacher->id, $now));

        // The card on Ana's page in Aty: missing, and once answered, what was said.
        $card = consent_view::child_card((int) $data->parent->id, (int) $data->ana->id, $now);
        $this->assertCount(1, $card[0]['items']);
        // It opens the request inside the app, with the deadline as the row's date and not again in its text.
        $this->assertSame('mobile_consent_view', $card[0]['items'][0]['method']);
        $this->assertSame(['id' => (int) $data->requestid], $card[0]['items'][0]['args']);
        $this->assertArrayNotHasKey('url', $card[0]['items'][0]);
        $this->assertSame((int) consent::get($data->requestid)->deadline, $card[0]['items'][0]['date']);
        $this->assertSame(get_string('consent:aty:missing', 'local_oksigeniafamily'), $card[0]['items'][0]['detail']);
        // On the home of the app, the same request as something to do, with its button; gone once answered.
        $home = consent_view::home_card((int) $data->parent->id, $now);
        $this->assertCount(1, $home[0]['items']);
        $this->assertSame(get_string('consent:aty:answer', 'local_oksigeniafamily'), $home[0]['items'][0]['actionlabel']);
        $this->assertSame('close', $home[0]['items'][0]['kind']);
        $this->assertStringContainsString($data->ana->firstname, $home[0]['items'][0]['detail']);
        consent::answer($data->requestid, (int) $data->ana->id, $data->adultid, consent::YES, 'app');
        $this->assertSame([], consent_view::home_card((int) $data->parent->id, $now));
        $card = consent_view::child_card((int) $data->parent->id, (int) $data->ana->id, $now);
        $this->assertSame(get_string('consent:aty:yes', 'local_oksigeniafamily'), $card[0]['items'][0]['detail']);
        $this->assertSame([], consent_view::child_card((int) $data->parent->id, (int) $data->leo->id, $now));

        // Made more than a day ago, with the deadline less than a day away: the reminder is due.
        $DB->update_record('local_oksigeniafamily_request', (object) [
            'id' => $data->requestid,
            'timecreated' => $now - 2 * DAYSECS,
            'deadline' => $now + HOURSECS,
            'keepdays' => 0,
        ]);
        $this->assertSame([$data->requestid], consent::due_reminders($now));

        // Even one stored with no days to keep stays the minimum after its deadline: the answers are the outing's proof.
        $this->assertSame(0, consent::purge($now));
        $this->assertSame(0, consent::purge($now + 2 * HOURSECS));
        $this->assertSame(0, consent::purge($now + (consent::MINKEEPDAYS - 1) * DAYSECS));
        $this->assertSame(1, consent::purge($now + HOURSECS + consent::MINKEEPDAYS * DAYSECS + 1));
        $this->assertNull(consent::get($data->requestid));
    }

    /**
     * A sending to more adults than a piece goes by pieces, each a task of its own; each adult it reaches gets a mark, so
     * that a piece Moodle runs again reaches nobody twice.
     */
    public function test_send_by_pieces(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $ana = $generator->create_user(['firstname' => 'Ana']);
        $teacher = $generator->create_user();
        $familyid = api::create_family();
        api::add_child($familyid, (int) $ana->id);
        $total = 2 * consent_send::PIECE + 1;
        for ($i = 1; $i <= $total; $i++) {
            api::add_adult($familyid, ['firstname' => 'Adult' . $i, 'email' => "adult$i@example.com"]);
        }
        $requestid = consent::create(\context_system::instance(), [
            'title' => 'Salida al Teide',
            'deadline' => time() + 3 * DAYSECS,
        ], [(int) $ana->id], (int) $teacher->id);
        $emails = $this->redirectEmails();

        consent_send::queue($requestid);
        ob_start();
        $this->runAdhocTasks(consent_send::class);
        $pieces = \core\task\manager::get_adhoc_tasks(consent_send::class);
        $this->assertCount(3, $pieces);
        $this->assertSame(0, $emails->count());
        $this->runAdhocTasks(consent_send::class);
        ob_end_clean();

        $this->assertSame($total, $emails->count());
        $this->assertSame($total, $DB->count_records('local_oksigeniafamily_sent', ['requestid' => $requestid]));
        $recipients = array_map(fn($email) => $email->to, $emails->get_messages());
        $this->assertCount($total, array_unique($recipients));

        // Moodle runs a piece again, as after a failure: nobody gets it twice.
        $emails->clear();
        $piece = reset($pieces)->get_custom_data();
        $this->assertCount(consent_send::PIECE, $piece->adults);
        $sent = consent_notices::send($requestid, false, null, $piece->adults, (int) $piece->round);
        $this->assertSame(0, $sent['email']);
        $this->assertSame(consent_send::PIECE, $sent['skipped']);
        $this->assertSame(0, $emails->count());

        // Erasing the request erases its marks.
        consent::delete($requestid);
        $this->assertSame(0, $DB->count_records('local_oksigeniafamily_sent'));
    }

    /**
     * A sending that stopped halfway goes on with whoever it had not reached; another sending, such as a second reminder,
     * has a number of its own and reaches them again.
     */
    public function test_retry_reaches_nobody_twice(): void {
        global $DB;
        $this->resetAfterTest();
        $data = $this->setup_family();
        $messages = $this->redirectMessages();
        $emails = $this->redirectEmails();

        // It stopped right after the adult with an account.
        $DB->insert_record('local_oksigeniafamily_sent', (object) [
            'requestid' => $data->requestid, 'roundid' => 77, 'adultid' => $data->adultid, 'timecreated' => time(),
        ]);
        $sent = consent_notices::send($data->requestid, false, null, null, 77);
        $this->assertSame(0, $sent['app']);
        $this->assertSame(1, $sent['email']);
        $this->assertSame(1, $sent['skipped']);
        $this->assertCount(0, $messages->get_messages());
        $this->assertSame('pilar@example.com', $emails->get_messages()[0]->to);

        // Once more: nobody.
        $emails->clear();
        $sent = consent_notices::send($data->requestid, false, null, null, 77);
        $this->assertSame(0, $sent['app'] + $sent['email']);
        $this->assertSame(2, $sent['skipped']);
        $this->assertSame(0, $emails->count());

        // A reminder is another sending: it reaches both again.
        $sent = consent_notices::send($data->requestid, true, null, null, 78);
        $this->assertSame(1, $sent['app']);
        $this->assertSame(1, $sent['email']);
        $this->assertSame(0, $sent['skipped']);

        // The task queued for a sending carries its own number, and a task from before the pieces uses its id.
        consent_send::queue($data->requestid, true);
        consent_send::queue($data->requestid, true);
        $rounds = array_map(
            fn($task) => (int) $task->get_custom_data()->round,
            \core\task\manager::get_adhoc_tasks(consent_send::class)
        );
        $this->assertCount(2, array_unique($rounds));
    }
}
