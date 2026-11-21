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
 * are welcomed; one of them finds their household with every child in it, and whoever else has a child's code gets that
 * child alone.
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
     * The household's adults, as the records have them: id => [quiet, status].
     *
     * @param int $familyid
     * @return array
     */
    private function adults_of(int $familyid): array {
        global $DB;

        $adults = [];
        foreach ($DB->get_records('local_oksigeniafamily_adult', ['familyid' => $familyid], 'id') as $adult) {
            $adults[(int) $adult->id] = [(int) $adult->quiet, $adult->status];
        }

        return $adults;
    }

    /**
     * Whoever has the elder's code and is none of the household's adults (the student sees the code on their own page)
     * gets the elder alone, in a family of their own, as with any code: not the younger, not the household, whatever
     * e-mail or phone they type, since a typed one proves nothing. The household goes on waiting for its adults, as it
     * was, and the next one with the code gets a family of their own too.
     */
    public function test_code_alone_gets_the_child_alone(): void {
        $this->resetAfterTest();
        $h = $this->household();
        $before = $this->adults_of($h['familyid']);

        $ana = api::claim(api::issue_claim_code($h['leo']), [
            'firstname' => 'Ana', 'lastname' => 'Paz', 'email' => 'marta@example.invalid', 'phone' => '+34600000001',
        ]);

        $this->assertNotSame($h['familyid'], (int) $ana->familyid);
        $this->assertSame([$h['leo']], array_map(
            fn($child) => (int) $child->userid,
            array_values(api::children_of_adult((int) $ana->id))
        ));
        $this->assertSame(0, (int) $ana->phoneverified);
        $this->assertSame($before, $this->adults_of($h['familyid']));
        $this->assertCount(2, api::children_of_adult($h['mother']));
        // Leo is in both now; the household is still the one his parents will find.
        $this->assertSame([(int) $ana->id], array_map('intval', array_keys(api::adults_of_child($h['leo']))));

        $luis = api::claim(api::issue_claim_code($h['leo']), ['firstname' => 'Luis']);
        $this->assertNotContains((int) $luis->familyid, [$h['familyid'], (int) $ana->familyid]);
        $this->assertCount(1, api::children_of_adult((int) $luis->id));
        $this->assertSame($before, $this->adults_of($h['familyid']));

        // An account that is none of its adults, by its e-mail, does not get into it either.
        $other = $this->getDataGenerator()->create_user(['email' => 'someone@example.invalid']);
        $signedin = api::claim(api::issue_claim_code($h['leo']), ['userid' => (int) $other->id, 'firstname' => 'Eva']);
        $this->assertNotSame($h['familyid'], (int) $signedin->familyid);
        $this->assertCount(1, api::children_of_adult((int) $signedin->id));
        $this->assertSame($before, $this->adults_of($h['familyid']));
    }

    /**
     * The mother, by the number the school's data has for her when someone vouches for it (WhatsApp, for whoever writes
     * from it), is that adult: she wakes, with both sons, and nobody new comes into the household.
     */
    public function test_vouched_phone_is_that_adult(): void {
        $this->resetAfterTest();
        $h = $this->household();

        $marta = api::claim(api::issue_claim_code($h['leo']), ['phone' => '+34600000001'], true);

        $this->assertSame($h['mother'], (int) $marta->id);
        $this->assertSame([$h['mother'] => [0, api::ACTIVE], $h['father'] => [1, api::ACTIVE]], $this->adults_of($h['familyid']));
        $this->assertCount(2, api::children_of_adult($h['mother']));
    }

    /**
     * A brother, signed in with his own account, never becomes an adult of the household he is a child of with the
     * code of his brother, while it waits or once his parents have joined, nor of a family of his own with his brother.
     */
    public function test_brother_is_never_an_adult(): void {
        global $DB;
        $this->resetAfterTest();
        $h = $this->household();
        $hugo = $this->getDataGenerator()->create_user(['email' => 'hugo@school.example.invalid']);
        api::add_child($h['familyid'], (int) $hugo->id, 'cifra:103');
        $adults = $DB->count_records('local_oksigeniafamily_adult');
        $families = $DB->count_records('local_oksigeniafamily_family');

        foreach (['waiting', 'joined'] as $when) {
            if ($when === 'joined') {
                api::wake_adult($h['mother']);
            }
            try {
                api::claim(api::issue_claim_code($h['leo']), ['userid' => (int) $hugo->id, 'firstname' => 'Hugo']);
                $this->fail('A brother joins as an adult (' . $when . ').');
            } catch (\moodle_exception $e) {
                $this->assertSame('claimself', $e->errorcode, $when);
            }
        }
        $this->assertSame($adults, $DB->count_records('local_oksigeniafamily_adult'));
        $this->assertSame($families, $DB->count_records('local_oksigeniafamily_family'));
        $this->assertSame([], api::families_of_user((int) $hugo->id));
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
     * account is linked and she wakes, and the father stays quiet.
     */
    public function test_account_by_email_is_that_adult(): void {
        global $DB;
        $this->resetAfterTest();
        $h = $this->household();
        $marta = $this->getDataGenerator()->create_user(['firstname' => 'MARTA', 'email' => 'Marta@Example.invalid']);

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

    /**
     * An account that has the e-mail the school's data has for the mother, but another first name (a teacher's address,
     * typed for her by mistake), is not her: it gets the student alone, in a family of its own, and the household goes on
     * waiting for its adults.
     */
    public function test_account_by_email_needs_the_name(): void {
        global $DB;
        $this->resetAfterTest();
        $h = $this->household();
        $pedro = $this->getDataGenerator()->create_user(['firstname' => 'Pedro', 'email' => 'marta@example.invalid']);

        $claimed = api::claim(api::issue_claim_code($h['leo']), ['userid' => (int) $pedro->id, 'firstname' => 'Pedro']);

        $this->assertNotSame($h['mother'], (int) $claimed->id);
        $this->assertNotSame($h['familyid'], (int) $claimed->familyid);
        $record = $DB->get_record('local_oksigeniafamily_adult', ['id' => $h['mother']]);
        $this->assertSame([0, 1], [(int) $record->userid, (int) $record->quiet]);
        $this->assertCount(1, api::children_of_adult((int) $claimed->id));
    }
}
