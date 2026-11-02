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

        // Made more than a day ago, with the deadline less than a day away: the reminder is due.
        $DB->update_record('local_oksigeniafamily_request', (object) [
            'id' => $data->requestid,
            'timecreated' => $now - 2 * DAYSECS,
            'deadline' => $now + HOURSECS,
            'keepdays' => 0,
        ]);
        $this->assertSame([$data->requestid], consent::due_reminders($now));

        $this->assertSame(0, consent::purge($now));
        $this->assertSame(1, consent::purge($now + 2 * HOURSECS));
        $this->assertNull(consent::get($data->requestid));
    }
}
