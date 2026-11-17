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
 * Tests for the quiet adults: those the school's data brings stay out of reach of the other plugins until they join or
 * are welcomed, and whoever has a child's code finds their household with every child in it.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_oksigeniafamily\api
 */
#[\PHPUnit\Framework\Attributes\CoversClass(api::class)]
final class quiet_test extends \advanced_testcase {
    /**
     * A household as the school's data brings it: two quiet parents, one of them with an account, and two brothers, the
     * younger without one.
     *
     * @return array familyid, leo (the elder's account), mother and father (adult ids), teacher (the father's account)
     */
    private function household(): array {
        $generator = $this->getDataGenerator();
        $leo = $generator->create_user();
        $teacher = $generator->create_user(['email' => 'dario@school.example.invalid']);
        $familyid = api::create_family('cifra', 'cifra:hogar:1');
        $mother = api::add_adult($familyid, [
            'firstname' => 'Marta', 'email' => 'marta@example.invalid', 'phone' => '+34600000001',
            'phoneverified' => true, 'sourcekey' => 'cifra:900', 'quiet' => true,
        ]);
        $father = api::add_adult($familyid, [
            'firstname' => 'Darío', 'email' => 'dario@school.example.invalid', 'sourcekey' => 'cifra:901',
            'userid' => (int) $teacher->id, 'quiet' => true,
        ]);
        api::add_child($familyid, (int) $leo->id, 'cifra:101');
        api::add_child($familyid, null, 'cifra:102');

        return ['familyid' => $familyid, 'leo' => (int) $leo->id, 'mother' => $mother, 'father' => $father,
            'teacher' => (int) $teacher->id];
    }

    /**
     * Nobody reaches a quiet adult: not a student's adults, not many students' at once, not a family's accounts; the
     * whole roll says who is quiet; and once welcomed, they are there like any other.
     */
    public function test_quiet_until_welcomed(): void {
        $this->resetAfterTest();
        $h = $this->household();

        $this->assertSame([], api::adults_of_child($h['leo']));
        $this->assertSame([$h['leo'] => []], api::adults_of_children([$h['leo']]));
        $this->assertSame([], api::adult_users_of_family($h['familyid']));
        $roll = iterator_to_array(api::each_household());
        $this->assertSame([true, true], array_map(fn($adult) => $adult->quiet, $roll[$h['familyid']]->adults));
        // Their own account still sees their children: that is not writing to them.
        $this->assertSame([$h['leo']], api::children_of_user($h['teacher']));

        $this->assertTrue(api::wake_adult($h['mother']));
        $this->assertFalse(api::wake_adult($h['mother']));
        $this->assertSame([$h['mother']], array_map('intval', array_keys(api::adults_of_child($h['leo']))));
        $this->assertSame([], api::adult_users_of_family($h['familyid']));
    }

    /**
     * Whoever has the elder's code, without an account of the household, joins that household and finds the younger
     * there too; nobody else is told, since nobody there has joined; and the quiet parents stay quiet.
     */
    public function test_code_joins_the_waiting_household(): void {
        $this->resetAfterTest();
        $h = $this->household();
        $messages = $this->redirectMessages();

        $ana = api::claim(api::issue_claim_code($h['leo']), ['firstname' => 'Ana', 'lastname' => 'Paz']);

        $this->assertSame($h['familyid'], (int) $ana->familyid);
        $this->assertCount(2, api::children_of_adult((int) $ana->id));
        $this->assertSame([(int) $ana->id], array_map('intval', array_keys(api::adults_of_child($h['leo']))));
        $this->assertSame(0, $messages->count());

        // The household has someone now: the next code makes a family of its own, as before.
        $luis = api::claim(api::issue_claim_code($h['leo']), ['firstname' => 'Luis']);
        $this->assertNotSame($h['familyid'], (int) $luis->familyid);
    }

    /**
     * The father, signed in with the account the school's data found for him, is that adult: he wakes, with both sons.
     */
    public function test_known_account_wakes(): void {
        $this->resetAfterTest();
        $h = $this->household();

        $dario = api::claim(api::issue_claim_code($h['leo']), ['userid' => $h['teacher'], 'firstname' => 'Darío']);

        $this->assertSame($h['father'], (int) $dario->id);
        $this->assertSame([$h['father']], array_map('intval', array_keys(api::adults_of_child($h['leo']))));
        $this->assertSame([$h['teacher']], api::adult_users_of_family($h['familyid']));
        $this->assertCount(2, api::children_of_adult($h['father']));
    }

    /**
     * The mother, signed in with an account that has the e-mail the school's data has for her, is that adult too: her
     * account is linked and she wakes. An account with another e-mail is a new adult of the household.
     */
    public function test_account_by_email_is_that_adult(): void {
        global $DB;
        $this->resetAfterTest();
        $h = $this->household();
        $marta = $this->getDataGenerator()->create_user(['email' => 'Marta@Example.invalid']);

        $claimed = api::claim(api::issue_claim_code($h['leo']), ['userid' => (int) $marta->id, 'firstname' => 'Marta']);

        $this->assertSame($h['mother'], (int) $claimed->id);
        $record = $DB->get_record('local_oksigeniafamily_adult', ['id' => $h['mother']]);
        $this->assertSame([(int) $marta->id, 0], [(int) $record->userid, (int) $record->quiet]);
        $this->assertSame(1, (int) $DB->get_field('local_oksigeniafamily_adult', 'quiet', ['id' => $h['father']]));
    }

    /**
     * Nobody makes themselves quiet from the claim page.
     */
    public function test_claim_never_quiet(): void {
        global $DB;
        $this->resetAfterTest();
        $leo = $this->getDataGenerator()->create_user();

        $ana = api::claim(api::issue_claim_code((int) $leo->id), ['firstname' => 'Ana', 'quiet' => true]);

        $this->assertSame(0, (int) $DB->get_field('local_oksigeniafamily_adult', 'quiet', ['id' => $ana->id]));
    }
}
