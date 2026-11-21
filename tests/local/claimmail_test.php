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
 * Tests for a code claimed with the address of an account of the site: the link to that address, and what it says.
 *
 * @package    local_oksigeniafamily
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniafamily\local\claimmail
 */
#[\PHPUnit\Framework\Attributes\CoversClass(claimmail::class)]
final class claimmail_test extends \advanced_testcase {
    /**
     * Only one account that signs in as a person does is taken for an address.
     */
    public function test_account(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $teacher = $gen->create_user(['email' => 'eva@school.example']);

        $this->assertSame((int) $teacher->id, (int) claimmail::account('Eva@School.example')->id);
        $this->assertNull(claimmail::account('nobody@school.example'));
        $this->assertNull(claimmail::account('not an address'));

        // A family's own account in the app signs in by web services only: it is not the one.
        $gen->create_user(['email' => 'luis@home.example', 'auth' => 'webservice']);
        $this->assertNull(claimmail::account('luis@home.example'));

        // A student is nobody's parent: their address never takes a child, though it is an account's.
        $student = $gen->create_user(['email' => 'leo@school.example']);
        $gen->enrol_user($student->id, $gen->create_course()->id, 'student');
        $this->assertNull(claimmail::account('leo@school.example'));
        $this->assertTrue(claimmail::taken('LEO@school.example'));
        $this->assertTrue(claimmail::taken('luis@home.example'));
        $this->assertFalse(claimmail::taken('nobody@school.example'));
        $this->assertFalse(claimmail::taken(''));

        // Two accounts with one address: nobody can tell which.
        $gen->create_user(['email' => 'eva@school.example']);
        $this->assertNull(claimmail::account('eva@school.example'));
    }

    /**
     * The link goes to the account's address, and says the code, the account and the phone while it is good.
     */
    public function test_send_and_read(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $teacher = $gen->create_user(['firstname' => 'Eva', 'email' => 'eva@school.example']);
        $student = $gen->create_user(['firstname' => 'Leo']);
        $code = api::issue_claim_code((int) $student->id);
        $this->assertSame((int) $student->id, api::code_student(strtolower($code)));

        $sink = $this->redirectEmails();
        $this->assertTrue(claimmail::send($code, $teacher, (int) $student->id, '+34600111222'));
        $emails = $sink->get_messages();
        $sink->close();

        $this->assertCount(1, $emails);
        $this->assertSame('eva@school.example', $emails[0]->to);
        $body = quoted_printable_decode($emails[0]->body);
        $this->assertStringContainsString('Leo', $body);
        $this->assertMatchesRegularExpression('~/local/oksigeniafamily/claim\.php\?confirm=([A-Za-z0-9_-]+)~', $body);
        preg_match('~/local/oksigeniafamily/claim\.php\?confirm=([A-Za-z0-9_-]+)~', $body, $found);
        // The code never shows in the link.
        $this->assertStringNotContainsString($code, $found[1]);

        $this->assertSame(
            ['code' => $code, 'userid' => (int) $teacher->id, 'phone' => '+34600111222', 'adultid' => 0],
            claimmail::read($found[1])
        );
        $this->assertNull(claimmail::read('rubbish'));
        $this->assertNull(claimmail::read(substr($found[1], 0, -4) . 'AAAA'));

        // A code taken back no longer joins anyone.
        api::revoke_claim_codes((int) $student->id);
        $this->assertNull(api::code_student($code));
    }

    /**
     * The link sent to an address.
     *
     * @param string $code The code.
     * @param \stdClass $user The account.
     * @param int $childuserid The student.
     * @param string $phone The phone typed.
     * @param int $adultid The adult without an account that whoever typed the address became.
     * @return string The link's «confirm».
     */
    private function link(string $code, \stdClass $user, int $childuserid, string $phone = '', int $adultid = 0): string {
        $sink = $this->redirectEmails();
        claimmail::send($code, $user, $childuserid, $phone, $adultid);
        $emails = $sink->get_messages();
        $sink->close();
        preg_match(
            '~/local/oksigeniafamily/claim\.php\?confirm=([A-Za-z0-9_-]+)~',
            quoted_printable_decode($emails[0]->body),
            $found
        );

        return $found[1];
    }

    /**
     * A link works once: used, it says nothing more, and using it again fails; given back (the claim could not be done
     * at that moment), it works again. Another link for the same code is a link of its own.
     */
    public function test_spend_once(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $teacher = $gen->create_user(['firstname' => 'Eva', 'email' => 'eva@school.example']);
        $student = $gen->create_user(['firstname' => 'Leo']);
        $code = api::issue_claim_code((int) $student->id);
        $sealed = $this->link($code, $teacher, (int) $student->id);

        $this->assertTrue(claimmail::spend($sealed));
        $this->assertNull(claimmail::read($sealed));
        $this->assertFalse(claimmail::spend($sealed));
        $this->assertFalse(claimmail::spend('rubbish'));
        // What is kept is a hash, never the link nor what it says.
        $kept = $DB->get_records('local_oksigeniafamily_spent');
        $this->assertCount(1, $kept);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', reset($kept)->stamp);

        claimmail::unspend($sealed);
        $this->assertNotNull(claimmail::read($sealed));
        $this->assertTrue(claimmail::spend($sealed));

        $this->waitForSecond();
        $again = $this->link($code, $teacher, (int) $student->id);
        $this->assertNotSame($sealed, $again);
        $this->assertTrue(claimmail::spend($again));

        // Those used longer ago than a link lasts go when another is used.
        $DB->set_field('local_oksigeniafamily_spent', 'timecreated', time() - claimmail::DAYS * DAYSECS - 1);
        $this->waitForSecond();
        $this->assertTrue(claimmail::spend($this->link($code, $teacher, (int) $student->id)));
        $this->assertSame(1, $DB->count_records('local_oksigeniafamily_spent'));
    }

    /**
     * The phone a link carries was only typed: it never takes the place of a number someone vouched for, though it
     * fills in an adult who had none.
     */
    public function test_link_phone_never_replaces_a_vouched_one(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $teacher = $gen->create_user(['email' => 'eva@school.example']);
        $leo = $gen->create_user();
        $lola = $gen->create_user();
        $familyid = api::create_family('csv', 'F1');
        $eva = api::add_adult($familyid, ['userid' => (int) $teacher->id, 'phone' => '+34600111000', 'phoneverified' => true]);
        api::add_child($familyid, (int) $leo->id);

        $claimed = api::claim(api::issue_claim_code((int) $lola->id), ['userid' => (int) $teacher->id, 'phone' => '+34600999999']);

        $this->assertSame($eva, (int) $claimed->id);
        $record = $DB->get_record('local_oksigeniafamily_adult', ['id' => $eva]);
        $this->assertSame(['+34600111000', 1], [$record->phone, (int) $record->phoneverified]);
        $this->assertTrue(api::adult_has_child($eva, (int) $lola->id));

        // Without a number of their own, the one given stays, as typed.
        $mother = $gen->create_user();
        $ana = api::add_adult(api::create_family(), ['userid' => (int) $mother->id]);
        api::claim(api::issue_claim_code((int) $leo->id), ['userid' => (int) $mother->id, 'phone' => '+34600222000']);
        $record = $DB->get_record('local_oksigeniafamily_adult', ['id' => $ana]);
        $this->assertSame(['+34600222000', 0], [$record->phone, (int) $record->phoneverified]);
    }

    /**
     * A teacher who is also a parent types their address on the claim page: they join at once as an adult without an
     * account and without the address, as anyone else would; once they confirm the link, the child is in their account
     * and that first adult goes away. One that is more than that (somebody else is in its family) stays.
     */
    public function test_merge(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $teacher = $gen->create_user(['firstname' => 'Eva', 'email' => 'eva@school.example']);
        $leo = $gen->create_user(['firstname' => 'Leo']);
        $code = api::issue_claim_code((int) $leo->id);

        // What the claim page does with an address that is an account's.
        $this->assertTrue(claimmail::taken('eva@school.example'));
        $stray = api::claim($code, ['firstname' => 'Eva', 'lastname' => 'Gil', 'email' => '']);
        $sealed = $this->link($code, $teacher, (int) $leo->id, '', (int) $stray->id);
        $this->assertSame((int) $stray->id, claimmail::read($sealed)['adultid']);

        // What it does when the link is confirmed.
        $this->assertTrue(claimmail::spend($sealed));
        $done = api::claim($code, ['userid' => (int) $teacher->id, 'firstname' => 'Eva', 'email' => $teacher->email]);
        $this->assertTrue(claimmail::merge((int) $stray->id, $done));

        $this->assertSame(api::LEFT, $DB->get_field('local_oksigeniafamily_adult', 'status', ['id' => $stray->id]));
        $this->assertSame(api::CLOSED, $DB->get_field('local_oksigeniafamily_family', 'status', ['id' => $stray->familyid]));
        $this->assertSame([(int) $done->id], array_map('intval', array_keys(api::adults_of_child((int) $leo->id))));
        $this->assertTrue(api::is_guardian((int) $teacher->id, (int) $leo->id));
        $this->assertFalse(claimmail::merge((int) $stray->id, $done));

        // A first adult whose family has somebody else stays, for the school to look at.
        $lola = $gen->create_user(['firstname' => 'Lola']);
        $code = api::issue_claim_code((int) $lola->id);
        $other = api::claim($code, ['firstname' => 'Eva']);
        api::add_adult((int) $other->familyid, ['firstname' => 'Luis']);
        $done = api::claim($code, ['userid' => (int) $teacher->id, 'firstname' => 'Eva']);
        $this->assertFalse(claimmail::merge((int) $other->id, $done));
        $this->assertSame(api::ACTIVE, $DB->get_field('local_oksigeniafamily_adult', 'status', ['id' => $other->id]));

        // With an invitation both are in the family that invited them: the account stays, alone.
        set_config('enabled', 1, 'local_oksigeniafamily');
        $mother = $gen->create_user(['firstname' => 'Ana', 'email' => 'ana@school.example']);
        $invite = api::invite_for_family((int) $other->familyid)['code'];
        $typed = api::claim($invite, ['firstname' => 'Ana']);
        $done = api::claim($invite, ['userid' => (int) $mother->id, 'firstname' => 'Ana']);
        $this->assertSame((int) $typed->familyid, (int) $done->familyid);
        $this->assertTrue(claimmail::merge((int) $typed->id, $done));
        $this->assertSame(api::LEFT, $DB->get_field('local_oksigeniafamily_adult', 'status', ['id' => $typed->id]));
        $this->assertTrue(api::is_guardian((int) $mother->id, (int) $lola->id));
    }
}
