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

namespace local_oksigeniafamily;

/**
 * Tests for the API that other plugins use.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_oksigeniafamily\api
 */
#[\PHPUnit\Framework\Attributes\CoversClass(api::class)]
final class api_test extends \advanced_testcase {
    /**
     * A family with an adult (with an account and a phone) and two children: who is family of whom, by account, by
     * adult and by phone; a child's key in the school's data; and a child who leaves.
     */
    public function test_records(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $parent = $generator->create_user();
        $ana = $generator->create_user();
        $leo = $generator->create_user();

        $familyid = api::create_family('csv', 'household:7');
        $adultid = api::add_adult($familyid, [
            'firstname' => 'Pilar', 'lastname' => 'Gil', 'phone' => '+34600000001', 'phoneverified' => true, 'userid' => $parent->id,
        ]);
        $anaid = api::add_child($familyid, (int) $ana->id, 'cifra:11');
        $leoid = api::add_child($familyid, (int) $leo->id);
        // Adding the same student again keeps their record.
        $this->assertSame($anaid, api::add_child($familyid, (int) $ana->id));
        // A child of Infantil, with no account yet, only by their key.
        api::add_child($familyid, null, 'cifra:12');

        $this->assertEqualsCanonicalizing([(int) $ana->id, (int) $leo->id], api::children_of_user((int) $parent->id));
        $this->assertTrue(api::is_guardian((int) $parent->id, (int) $ana->id));
        $this->assertFalse(api::is_guardian((int) $ana->id, (int) $leo->id));
        $this->assertTrue(api::adult_has_child($adultid, (int) $leo->id));
        $this->assertCount(3, api::children_of_adult($adultid));
        $this->assertSame([$adultid], array_map('intval', array_keys(api::adults_of_child((int) $ana->id))));
        $this->assertSame($adultid, (int) api::adult_by_phone('+34600000001')->id);
        $this->assertNull(api::adult_by_phone('+34600000009'));
        $this->assertSame('cifra:11', api::child_source_key((int) $ana->id, 'cifra'));
        $this->assertNull(api::child_source_key((int) $ana->id, 'oneroster'));

        $sink = $this->redirectEvents();
        api::unlink_child($leoid, 'manual');
        $events = array_filter($sink->get_events(), fn($event) => $event instanceof event\child_unlinked);
        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertSame((int) $leo->id, (int) $event->relateduserid);
        $this->assertSame('manual', $event->other['reason']);
        $this->assertNull($event->other['adultid']);
        $this->assertSame([(int) $ana->id], api::children_of_user((int) $parent->id));
        $this->assertFalse(api::adult_has_child($adultid, (int) $leo->id));
    }

    /**
     * Claiming a student's code: a new adult starts a family of their own, the same adult again (by a phone someone vouches
     * for) stays in it, another adult starts another; the student's other adults are told; a wrong, revoked or expired code
     * fails.
     */
    public function test_claim(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $ana = $generator->create_user();
        $mother = $generator->create_user();
        $code = api::issue_claim_code((int) $ana->id);
        $this->assertMatchesRegularExpression('/^[2-9A-HJKMNP-Z]{8}$/', $code);

        // The mother, with an account, claims it first; then the father, with only a phone, typed in lower case.
        $first = api::claim($code, ['firstname' => 'Marta', 'userid' => $mother->id]);
        $this->assertSame((int) $ana->id, $first->childuserid);
        $this->assertTrue(api::is_guardian((int) $mother->id, (int) $ana->id));

        $messages = $this->redirectMessages();
        $typed = strtolower(substr($code, 0, 4) . '-' . substr($code, 4));
        $second = api::claim($typed, ['firstname' => 'Juan', 'phone' => '+34600000002']);
        $this->assertNotEquals($first->familyid, $second->familyid);
        $this->assertCount(2, api::adults_of_child((int) $ana->id));
        // The mother hears of the new adult.
        $this->assertContains((int) $mother->id, array_map('intval', array_column($messages->get_messages(), 'useridto')));

        // The father by his phone, which WhatsApp vouches for, twice: the second time he is the same adult, in the same family.
        $vouched = api::claim($code, ['phone' => '+34600000002'], true);
        $this->assertNotSame((int) $second->id, (int) $vouched->id);
        $again = api::claim($code, ['phone' => '+34600000002'], true);
        $this->assertSame((int) $vouched->id, (int) $again->id);

        foreach (['WRONGCOD', ''] as $wrong) {
            try {
                api::claim($wrong, ['phone' => '+34600000003']);
                $this->fail('A wrong code must not join anyone.');
            } catch (\moodle_exception $e) {
                $this->assertSame('claiminvalid', $e->errorcode);
            }
        }
        api::revoke_claim_codes((int) $ana->id);
        $this->expectException(\moodle_exception::class);
        api::claim($code, ['phone' => '+34600000004']);
    }

    /**
     * A number typed first by someone else does not catch its owner: when WhatsApp vouches for it later, they get their own
     * record, and only theirs answers to that number.
     */
    public function test_a_typed_number_does_not_catch_its_owner(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $number = '+14155550142';
        $hers = $generator->create_user();
        $his = $generator->create_user();

        $impostor = api::claim(api::issue_claim_code((int) $his->id), ['firstname' => 'Someone', 'phone' => $number]);
        $this->assertNull(api::adult_by_phone($number));
        $this->assertSame([], api::adults_by_phone($number));

        $owner = api::claim(api::issue_claim_code((int) $hers->id), ['firstname' => 'Owner', 'phone' => $number], true);
        $this->assertNotSame((int) $impostor->id, (int) $owner->id);
        $this->assertNotSame((int) $impostor->familyid, (int) $owner->familyid);
        $this->assertSame((int) $owner->id, (int) api::adult_by_phone($number)->id);
        $this->assertSame([(int) $hers->id], array_values(array_map(
            fn($child) => (int) $child->userid,
            api::children_of_adult((int) $owner->id)
        )));
        $this->assertSame(0, (int) $impostor->phoneverified);
    }

    /**
     * A caller who already knows the adult says so: a second child joins the same record, and one who has left starts anew.
     */
    public function test_claim_by_a_known_adult(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $first = api::claim(api::issue_claim_code((int) $generator->create_user()->id), ['firstname' => 'Ana']);

        $second = api::claim(
            api::issue_claim_code((int) $generator->create_user()->id),
            ['firstname' => 'Ana', 'adultid' => (int) $first->id]
        );
        $this->assertSame((int) $first->id, (int) $second->id);
        $this->assertCount(2, api::children_of_adult((int) $first->id));

        api::remove_adult((int) $first->id);
        $third = api::claim(
            api::issue_claim_code((int) $generator->create_user()->id),
            ['firstname' => 'Ana', 'adultid' => (int) $first->id]
        );
        $this->assertNotSame((int) $first->id, (int) $third->id);
    }

    /**
     * A number stops counting as theirs when it changes, unless whoever changes it vouches for the new one.
     */
    public function test_a_changed_number_is_not_vouched_for(): void {
        $this->resetAfterTest();
        $familyid = api::create_family();
        $adultid = api::add_adult($familyid, ['firstname' => 'Ana', 'phone' => '+14155550143', 'phoneverified' => true]);
        $this->assertSame($adultid, (int) api::adult_by_phone('+14155550143')->id);

        api::update_adult($adultid, ['firstname' => 'Ana María']);
        $this->assertSame($adultid, (int) api::adult_by_phone('+14155550143')->id);

        api::update_adult($adultid, ['phone' => '+14155550144']);
        $this->assertNull(api::adult_by_phone('+14155550144'));

        api::update_adult($adultid, ['phone' => '+14155550145', 'phoneverified' => true]);
        $this->assertSame($adultid, (int) api::adult_by_phone('+14155550145')->id);
    }

    /**
     * A phone that was only typed proves nothing. With a valid code and another parent's number, nobody gets into that
     * parent's family: a new adult in a new family keeps the number, and that parent stays exactly as they were. Only
     * when someone vouches for the number does it join them. An account still joins by itself, and keeps the phone it
     * gives.
     */
    public function test_claim_by_phone_only_when_verified(): void {
        global $DB;
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $own = $generator->create_user();
        $stranger = $generator->create_user();
        $number = '+14155550101';
        $familyid = api::create_family('csv', 'F1');
        $parent = api::add_adult($familyid, ['firstname' => 'Marta', 'lastname' => 'Gil', 'phone' => $number, 'phoneverified' => true]);
        api::add_child($familyid, (int) $own->id);
        $before = $DB->get_record('local_oksigeniafamily_adult', ['id' => $parent]);

        // Somebody holding the code of another student types Marta's number.
        $code = api::issue_claim_code((int) $stranger->id);
        $sink = $this->redirectEvents();
        $joined = api::claim($code, ['firstname' => 'Someone', 'phone' => $number]);
        $updates = array_filter($sink->get_events(), fn($event) => $event instanceof event\adult_updated);
        $sink->close();

        $this->assertNotSame($parent, (int) $joined->id);
        $this->assertNotSame($familyid, (int) $joined->familyid);
        $this->assertSame($number, $joined->phone);
        $this->assertSame([], $updates);
        $this->assertEquals($before, $DB->get_record('local_oksigeniafamily_adult', ['id' => $parent]));
        $this->assertFalse(api::adult_has_child($parent, (int) $stranger->id));
        $this->assertSame([(int) $own->id], array_map(
            fn($child) => (int) $child->userid,
            array_values(api::children_of_adult($parent))
        ));
        $this->assertSame(1, $DB->count_records('local_oksigeniafamily_child', ['familyid' => $familyid]));

        // Saying nothing is the same as saying it is not verified, whatever the code.
        $other = api::claim(api::issue_claim_code((int) $own->id), ['phone' => $number]);
        $this->assertNotSame($parent, (int) $other->id);
        $this->assertNotSame($familyid, (int) $other->familyid);

        // With the number vouched for, it is Marta: she gets the student, in her family.
        $verified = api::claim($code, ['phone' => $number], true);
        $this->assertSame($parent, (int) $verified->id);
        $this->assertSame($familyid, (int) $verified->familyid);
        $this->assertTrue(api::adult_has_child($parent, (int) $stranger->id));

        // A new number, vouched for or not, is a new adult.
        $fresh = api::claim($code, ['phone' => '+14155550109'], true);
        $this->assertNotSame($parent, (int) $fresh->id);

        // An account joins by itself, and it is its own phone that it changes.
        $mother = $generator->create_user();
        $withaccount = api::add_adult(api::create_family(), ['firstname' => 'Ana', 'userid' => $mother->id]);
        $signedin = api::claim($code, ['firstname' => 'Ana', 'userid' => $mother->id, 'phone' => '+14155550108']);
        $this->assertSame($withaccount, (int) $signedin->id);
        $this->assertSame('+14155550108', $signedin->phone);
        $this->assertSame($number, $DB->get_field('local_oksigeniafamily_adult', 'phone', ['id' => $parent]));
    }

    /**
     * Those who manage the families get the notice with a link to the record, where they can remove the adult; the
     * student's other adults get it without one.
     */
    public function test_claim_notice_links_to_the_record(): void {
        global $DB;
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $ana = $generator->create_user();
        $mother = $generator->create_user();
        $manager = $generator->create_user();
        $generator->role_assign($DB->get_field('role', 'id', ['shortname' => 'manager']), $manager->id);
        $code = api::issue_claim_code((int) $ana->id);
        api::claim($code, ['firstname' => 'Marta', 'userid' => $mother->id]);

        $sink = $this->redirectMessages();
        $joined = api::claim($code, ['firstname' => 'Juan', 'phone' => '+14155550102']);
        $messages = [];
        foreach ($sink->get_messages() as $message) {
            $messages[(int) $message->useridto] = $message;
        }
        $this->assertStringContainsString('family.php?id=' . $joined->familyid, $messages[$manager->id]->contexturl);
        $this->assertEmpty($messages[$mother->id]->contexturl);
    }

    /**
     * Every adult with a number, and a change of details that says which phone the adult had.
     */
    public function test_phone_and_updates(): void {
        $this->resetAfterTest();

        $vouched = ['firstname' => 'Pilar', 'phone' => '+34600000001', 'phoneverified' => true];
        $first = api::add_adult(api::create_family(), $vouched);
        $second = api::add_adult(api::create_family(), $vouched);
        $this->assertEqualsCanonicalizing([$first, $second], array_map('intval', array_keys(api::adults_by_phone('+34600000001'))));

        $sink = $this->redirectEvents();
        $this->assertFalse(api::update_adult($first, ['phone' => '+34600000001']));
        $this->assertTrue(api::update_adult($first, [
            'phone' => '+34600000009',
            'phoneverified' => true,
            'email' => 'pilar@example.com',
        ]));
        $events = array_values(array_filter($sink->get_events(), fn($event) => $event instanceof event\adult_updated));
        $this->assertCount(1, $events);
        $this->assertSame('+34600000001', $events[0]->other['oldphone']);
        $this->assertSame([$second], array_map('intval', array_keys(api::adults_by_phone('+34600000001'))));
        $this->assertSame($first, (int) api::adult_by_phone('+34600000009')->id);
    }

    /**
     * A family started by another plugin, as the admissions team does at enrolment: found again by its household's key,
     * its adults by e-mail (in their record or in their account, in any case), and a child known only by that plugin's
     * key who gains their account later, in the same record.
     */
    public function test_family_from_another_plugin(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $familyid = api::create_family('eguahe', 'eguahe:12');
        $marta = api::add_adult($familyid, ['firstname' => 'Marta', 'email' => 'Marta.Gil@example.com', 'phone' => '+34600000002']);
        $father = $generator->create_user(['email' => 'luis.gil@example.com']);
        $luis = api::add_adult($familyid, ['firstname' => 'Luis', 'userid' => $father->id]);
        $childid = api::add_child($familyid, null, 'eguahe:30');

        $this->assertSame($familyid, api::family_by_key('eguahe', 'eguahe:12'));
        $this->assertNull(api::family_by_key('csv', 'eguahe:12'));
        $this->assertSame([$marta], array_map('intval', array_keys(api::adults_by_email(' marta.gil@EXAMPLE.com'))));
        $this->assertSame([$luis], array_map('intval', array_keys(api::adults_by_email('Luis.Gil@example.com'))));
        $this->assertSame([], api::adults_by_email(''));
        // A phone typed in a form is not vouched for.
        $this->assertSame([], api::adults_by_phone('+34600000002'));

        // The student's account arrives: the same child gains it.
        $student = $generator->create_user();
        $this->assertSame($childid, api::add_child($familyid, (int) $student->id, 'eguahe:30'));
        $this->assertSame([(int) $student->id], api::children_of_user((int) $father->id));

        // An adult who left, and a closed family, are no longer found.
        api::remove_adult($luis);
        $this->assertSame([], api::adults_by_email('luis.gil@example.com'));
        api::unlink_child($childid, 'manual');
        api::close_family($familyid);
        $this->assertNull(api::family_by_key('eguahe', 'eguahe:12'));
        $this->assertSame([], api::adults_by_email('marta.gil@example.com'));
    }

    /**
     * Who an adult is, for Oksigenia Marandu's list of numbers.
     */
    public function test_marandu_label(): void {
        global $CFG;
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/local/oksigeniafamily/lib.php');

        $ana = $this->getDataGenerator()->create_user(['firstname' => 'Ana']);
        $familyid = api::create_family();
        $adultid = api::add_adult($familyid, ['firstname' => 'Lucía', 'lastname' => 'Sanz', 'phone' => '+34600000001']);
        api::add_child($familyid, (int) $ana->id);

        $this->assertSame('Lucía Sanz (family of Ana)', local_oksigeniafamily_oksigeniamarandu_label($adultid));
        $this->assertNull(local_oksigeniafamily_oksigeniamarandu_label($adultid + 100));
    }

    /**
     * The roll of households: each with its children and adults, names and e-mail from the account when the record has
     * none, a child known only by a key without a name, and the gone and the closed only when asked for.
     */
    public function test_households(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $parent = $generator->create_user(['firstname' => 'Marta', 'lastname' => 'Rey', 'email' => 'marta@example.com']);
        $lucia = $generator->create_user(['firstname' => 'Lucía', 'lastname' => 'Rey']);
        $hugo = $generator->create_user();

        $familyid = api::create_family('cifra', 'H-7');
        $martaid = api::add_adult($familyid, ['userid' => $parent->id]);
        $pabloid = api::add_adult($familyid, [
            'firstname' => 'Pablo', 'lastname' => 'Gil', 'email' => 'pablo@example.com',
            'phone' => '+34600000001', 'phoneverified' => true,
        ]);
        $luciaid = api::add_child($familyid, (int) $lucia->id, 'cifra:11');
        $keyonlyid = api::add_child($familyid, null, 'cifra:12');
        $hugoid = api::add_child($familyid, (int) $hugo->id);
        api::unlink_child($hugoid, 'manual');

        $closedid = api::create_family();
        api::add_adult($closedid, ['firstname' => 'Eva']);
        api::close_family($closedid);

        $households = api::households();
        $this->assertSame([$familyid], array_keys($households));
        $home = $households[$familyid];
        $this->assertSame('cifra', $home->source);
        $this->assertSame('H-7', $home->sourcekey);
        $this->assertSame([$luciaid, $keyonlyid], array_column($home->children, 'childid'));
        $this->assertSame('Lucía', $home->children[0]->firstname);
        $this->assertSame('cifra:11', $home->children[0]->sourcekey);
        $this->assertNull($home->children[1]->userid);
        $this->assertNull($home->children[1]->firstname);

        [$marta, $pablo] = $home->adults;
        $this->assertSame([$martaid, $pabloid], [$marta->adultid, $pablo->adultid]);
        $this->assertSame(['Marta', 'Rey', 'marta@example.com', null, false],
            [$marta->firstname, $marta->lastname, $marta->email, $marta->phone, $marta->phoneverified]);
        $this->assertSame(['Pablo', 'pablo@example.com', '+34600000001', true],
            [$pablo->firstname, $pablo->email, $pablo->phone, $pablo->phoneverified]);

        // Everyone, the gone child and the closed household included, with when.
        $all = api::households(false);
        $this->assertSame([$familyid, $closedid], array_keys($all));
        $gone = array_values(array_filter($all[$familyid]->children, fn($c) => $c->childid === $hugoid))[0];
        $this->assertSame(api::LEFT, $gone->status);
        $this->assertNotNull($gone->timeleft);
        $this->assertSame(api::CLOSED, $all[$closedid]->status);
        $this->assertNotNull($all[$closedid]->timeclosed);
    }

    /**
     * Moodle's parent role follows the records: given to each adult with an account over each child, taken away when
     * the child leaves, and none at all while the site chooses no role.
     */
    public function test_parent_role(): void {
        global $DB;
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $parent = $generator->create_user();
        $ana = $generator->create_user();
        $roleid = create_role('Parent', 'familyparent', '');
        set_role_contextlevels($roleid, [CONTEXT_USER]);
        $context = \context_user::instance((int) $ana->id);

        $familyid = api::create_family();
        api::add_adult($familyid, ['userid' => $parent->id]);
        $childid = api::add_child($familyid, (int) $ana->id);
        $this->assertFalse(user_has_role_assignment((int) $parent->id, $roleid, $context->id));

        set_config('parentroleid', $roleid, 'local_oksigeniafamily');
        local\roles::sync_all();
        $this->assertTrue(user_has_role_assignment((int) $parent->id, $roleid, $context->id));
        $this->assertTrue($DB->record_exists('role_assignments', ['component' => 'local_oksigeniafamily', 'itemid' => $familyid]));

        api::unlink_child($childid, 'manual');
        $this->assertFalse(user_has_role_assignment((int) $parent->id, $roleid, $context->id));
    }
}
