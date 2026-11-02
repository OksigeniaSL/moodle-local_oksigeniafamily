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
 * Tests for the list of families and for adding a child with what they lacked.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_oksigeniafamily\local\families
 */
#[\PHPUnit\Framework\Attributes\CoversClass(families::class)]
final class families_test extends \advanced_testcase {
    /**
     * The search goes by adult, student, phone, e-mail and key; the list has only names; closed families apart.
     */
    public function test_search(): void {
        $this->resetAfterTest();

        $ana = $this->getDataGenerator()->create_user(['firstname' => 'Ana', 'lastname' => 'Ruiz']);
        $first = api::create_family('csv', 'F-1001');
        api::add_adult($first, [
            'firstname' => 'Marta', 'lastname' => 'Gil', 'phone' => '+14155550101', 'email' => 'marta@example.com',
        ]);
        api::add_child($first, (int) $ana->id);
        $second = api::create_family('manual');
        api::add_adult($second, ['firstname' => 'Carlos', 'lastname' => 'Vega']);
        api::add_child($second, null, 'csv:S-3082');
        $closed = api::create_family('manual');
        api::add_adult($closed, ['firstname' => 'Luis', 'lastname' => 'Sanz']);
        api::close_family($closed);

        $ids = fn(string $query, string $status = 'active') => array_map(
            fn($family) => (int) $family->id,
            families::search($query, $status, 0)['families']
        );
        $this->assertEqualsCanonicalizing([$first, $second], $ids(''));
        $this->assertSame([$first], $ids('Marta Gil'));
        $this->assertSame([$first], $ids('ana ruiz'));
        $this->assertSame([$first], $ids('marta@'));
        $this->assertSame([$first], $ids('415 555'));
        $this->assertSame([$first], $ids('F-1001'));
        $this->assertSame([$second], $ids('S-3082'));
        $this->assertSame([], $ids('Luis'));
        $this->assertSame([$closed], $ids('Luis', 'closed'));

        $list = families::search('Marta', 'active', 0);
        $this->assertSame(1, $list['total']);
        $this->assertSame(['Marta Gil'], $list['families'][0]->adults);
        $this->assertSame(['Ana Ruiz'], $list['families'][0]->children);
        // A closed family still shows who was in it.
        $this->assertSame(['Luis Sanz'], families::search('', 'closed', 0)['families'][0]->adults);
        // Someone without an account shows their key, and someone without a name, how to reach them.
        $this->assertSame(['csv:S-3082'], families::search('Carlos', 'active', 0)['families'][0]->children);
        $this->assertSame('+14155550101', families::adult_name((object) ['phone' => '+14155550101', 'email' => null]));

        $this->assertSame(['active' => 2, 'closed' => 1, 'adults' => 2, 'children' => 2], families::counts());
    }

    /**
     * Pages.
     */
    public function test_pages(): void {
        $this->resetAfterTest();

        for ($i = 1; $i <= families::PERPAGE + 2; $i++) {
            api::create_family();
        }
        $this->assertCount(families::PERPAGE, families::search('', 'active', 0)['families']);
        $this->assertCount(2, families::search('', 'active', 1)['families']);
        $this->assertSame(families::PERPAGE + 2, families::search('', 'active', 1)['total']);
    }

    /**
     * A child known by a key gets their account, and the other way round, without a second record; a student with an
     * account does not take the key of another.
     */
    public function test_child_gains_what_it_lacked(): void {
        global $DB;
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $ana = $generator->create_user();
        $leo = $generator->create_user();
        $familyid = api::create_family();

        $bykey = api::add_child($familyid, null, 'csv:1');
        $sink = $this->redirectEvents();
        $this->assertSame($bykey, api::add_child($familyid, (int) $ana->id, 'csv:1'));
        $this->assertCount(1, $sink->get_events());
        $this->assertInstanceOf(\local_oksigeniafamily\event\child_linked::class, $sink->get_events()[0]);
        $sink->close();
        $this->assertSame((int) $ana->id, (int) $DB->get_field('local_oksigeniafamily_child', 'userid', ['id' => $bykey]));

        $byaccount = api::add_child($familyid, (int) $leo->id);
        $this->assertSame($byaccount, api::add_child($familyid, (int) $leo->id, 'csv:2'));
        $this->assertSame('csv:2', $DB->get_field('local_oksigeniafamily_child', 'sourcekey', ['id' => $byaccount]));

        $other = $generator->create_user();
        $this->assertNotSame($byaccount, api::add_child($familyid, (int) $other->id, 'csv:2'));
        $this->assertSame(3, $DB->count_records('local_oksigeniafamily_child', ['familyid' => $familyid]));
    }
}
