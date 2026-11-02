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

/**
 * Tests for the import: the plan, what it does, and repeating it.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_oksigeniafamily\local\import\importer
 */
#[\PHPUnit\Framework\Attributes\CoversClass(importer::class)]
final class importer_test extends \advanced_testcase {
    /**
     * Rows out of a CSV text.
     *
     * @param string[] $lines The lines after the headings.
     * @return array
     */
    private function rows(array $lines): array {
        $head = 'family,type,firstname,lastname,email,phone,username,idnumber,key';

        return csv_file::read($head . "\n" . implode("\n", $lines) . "\n")['rows'];
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
     * The plan touches nothing; applying it makes the family; and the same file again changes nothing at all.
     */
    public function test_plan_apply_and_repeat(): void {
        global $DB;
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $ana = $generator->create_user(['username' => 'ana.ruiz']);
        $leo = $generator->create_user(['username' => 'leo.ruiz', 'idnumber' => '20412']);
        $marta = $generator->create_user(['username' => 'marta.gil']);
        set_config('phoneprefix', '+1', 'local_oksigeniafamily');
        $rows = $this->rows([
            'F1,adult,Marta,Gil,MARTA@example.com,415 555 0101,marta.gil,,',
            'F1,adult,Juan,Ruiz,,4155550102,,,J-7',
            'F1,child,,,,,ana.ruiz,,',
            'F1,child,,,,,,20412,S-20412',
            'F1,child,,,,,,,S-3082',
            'F2,adult,Carlos,Vega,,,,,',
            'F2,child,,,,,ana.ruiz,,',
        ]);

        $plan = importer::plan($rows);
        $this->assertSame([0, 0, 0], $this->totals());
        $this->assertSame([], $plan['errors']);
        $this->assertSame(2, $plan['counts']['familiesnew']);
        $this->assertSame(3, $plan['counts']['adultsnew']);
        $this->assertSame(4, $plan['counts']['childrennew']);

        importer::apply($plan);
        $this->assertSame([2, 3, 4], $this->totals());

        $family = $DB->get_record('local_oksigeniafamily_family', ['source' => 'csv', 'sourcekey' => 'F1']);
        $adult = $DB->get_record('local_oksigeniafamily_adult', ['familyid' => $family->id, 'firstname' => 'Marta']);
        $this->assertSame('marta@example.com', $adult->email);
        $this->assertSame('+14155550101', $adult->phone);
        $this->assertSame((int) $marta->id, (int) $adult->userid);
        $juan = $DB->get_record('local_oksigeniafamily_adult', ['familyid' => $family->id, 'firstname' => 'Juan']);
        $this->assertSame('csv:J-7', $juan->sourcekey);
        $this->assertNull($juan->userid);
        $this->assertEqualsCanonicalizing([(int) $ana->id, (int) $leo->id], api::children_of_user((int) $marta->id));
        $this->assertSame('csv:S-20412', api::child_source_key((int) $leo->id, 'csv'));
        $this->assertTrue($DB->record_exists('local_oksigeniafamily_child', [
            'familyid' => $family->id, 'sourcekey' => 'csv:S-3082',
        ]));

        // The same file again: nothing to do, and nothing happens.
        $again = importer::plan($rows);
        foreach (['familiesnew', 'adultsnew', 'adultschanged', 'childrennew', 'childrenchanged', 'errors'] as $count) {
            $this->assertSame(0, $again['counts'][$count], $count);
        }
        $this->assertSame(7, $again['counts']['same']);
        $sink = $this->redirectEvents();
        importer::apply($again);
        $this->assertSame([2, 3, 4], $this->totals());
        $this->assertSame(0, $sink->count());
    }

    /**
     * A later file changes what it says and nothing else: an empty cell does not wipe a phone, a new phone says which
     * one the adult had, and a child known by their key gets their account when they have one.
     */
    public function test_later_file(): void {
        global $DB;
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $ana = $generator->create_user(['username' => 'ana.ruiz']);
        importer::apply(importer::plan($this->rows([
            'F1,adult,Marta,Gil,marta@example.com,+14155550101,,,',
            'F1,child,,,,,,,S-1',
        ])));
        $childid = (int) $DB->get_field('local_oksigeniafamily_child', 'id', ['sourcekey' => 'csv:S-1']);

        // The same adult told by name, with no e-mail and a new phone; a new adult; the child now has an account.
        $plan = importer::plan($this->rows([
            'F1,adult,marta,gil,,+14155550109,,,',
            'F1,adult,Juan,Ruiz,,+14155550109,,,',
            'F1,child,,,,,ana.ruiz,,S-1',
        ]));
        $this->assertSame(0, $plan['counts']['familiesnew']);
        $this->assertSame(1, $plan['counts']['adultsnew']);
        $this->assertSame(1, $plan['counts']['adultschanged']);
        $this->assertSame(1, $plan['counts']['childrenchanged']);
        $this->assertSame(0, $plan['counts']['childrennew']);

        $sink = $this->redirectEvents();
        importer::apply($plan);
        $updated = array_values(array_filter(
            $sink->get_events(),
            fn($event) => $event instanceof \local_oksigeniafamily\event\adult_updated
        ));
        $this->assertCount(1, $updated);
        $this->assertSame('+14155550101', $updated[0]->other['oldphone']);

        $adult = $DB->get_record('local_oksigeniafamily_adult', ['email' => 'marta@example.com']);
        $this->assertSame('+14155550109', $adult->phone);
        $this->assertSame('Marta', $adult->firstname);
        $this->assertSame([2], [$DB->count_records('local_oksigeniafamily_adult')]);
        $child = $DB->get_record('local_oksigeniafamily_child', ['id' => $childid]);
        $this->assertSame((int) $ana->id, (int) $child->userid);
        $this->assertSame(1, $DB->count_records('local_oksigeniafamily_child'));
    }

    /**
     * Each row that cannot go in says why, in its line, and the rest goes on; a family left without adults or without
     * children stays out.
     */
    public function test_errors_by_row(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $generator->create_user(['username' => 'ana.ruiz', 'idnumber' => 'DUP']);
        $generator->create_user(['username' => 'leo.ruiz', 'idnumber' => 'DUP']);
        $generator->create_user(['username' => 'baja', 'suspended' => 1]);
        $lines = [
            'F1,adult,Marta,,,,,,',
            'F1,child,,,,,ana.ruiz,,',
            ',adult,Sin,Familia,,,,,',
            'F1,grandparent,Rosa,,,,,,',
            'F1,adult,Pablo,,no-es-correo,,,,',
            'F1,adult,Pablo,,,12,,,',
            'F1,adult,,,,,,,',
            'F1,child,,,,,,,',
            'F1,child,,,,,noexiste,,',
            'F1,child,,,,,,NOPE,',
            'F1,child,,,,,,DUP,',
            'F1,child,,,,,baja,,',
            'F2,adult,Solo,Adulto,,,,,',
            'F3,child,,,,,ana.ruiz,,',
        ];
        $plan = importer::plan($this->rows($lines));

        $lines = array_column($plan['errors'], 'line');
        $this->assertSame([4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15], $lines);
        $messages = array_column($plan['errors'], 'message', 'line');
        $this->assertStringContainsString('grandparent', $messages[5]);
        $this->assertStringContainsString('no-es-correo', $messages[6]);
        $this->assertStringContainsString('"12"', $messages[7]);
        $this->assertStringContainsString('noexiste', $messages[10]);
        $this->assertStringContainsString('NOPE', $messages[11]);
        $this->assertStringContainsString('DUP', $messages[12]);
        $this->assertStringContainsString('baja', $messages[13]);
        $this->assertStringContainsString('F2', $messages[14]);
        $this->assertStringContainsString('F3', $messages[15]);
        $this->assertSame(1, $plan['counts']['familiesnew']);

        importer::apply($plan);
        $this->assertSame([1, 1, 1], $this->totals());
    }

    /**
     * Two adults of a family are told apart: a couple sharing a phone are two people, the same key is one person even with
     * another name, and an adult who gave no name is found by their phone.
     */
    public function test_telling_adults_apart(): void {
        global $DB;
        $this->resetAfterTest();

        $this->getDataGenerator()->create_user(['username' => 'ana.ruiz']);
        importer::apply(importer::plan($this->rows([
            'F1,adult,Marta,Gil,,+14155550101,,,',
            'F1,adult,Juan,Ruiz,,+14155550101,,,',
            'F1,adult,,,,+14155550103,,,',
            'F1,adult,Lola,Pons,,,,,L-1',
            'F1,child,,,,,ana.ruiz,,',
        ])));
        $this->assertSame(4, $DB->count_records('local_oksigeniafamily_adult'));

        $plan = importer::plan($this->rows([
            'F1,adult,Pedro,Mora,,+14155550103,,,',
            'F1,adult,Dolores,Pons,,,,,L-1',
        ]));
        $this->assertSame(0, $plan['counts']['adultsnew']);
        $this->assertSame(2, $plan['counts']['adultschanged']);
        importer::apply($plan);
        $this->assertSame('Dolores', $DB->get_field('local_oksigeniafamily_adult', 'firstname', ['sourcekey' => 'csv:L-1']));
        $this->assertSame('Pedro', $DB->get_field('local_oksigeniafamily_adult', 'firstname', ['phone' => '+14155550103']));
    }

    /**
     * With a complete list, children without an account who are not in it leave; those with an account do not, and
     * without saying it is complete, nobody does.
     */
    public function test_complete_list(): void {
        global $DB;
        $this->resetAfterTest();

        $ana = $this->getDataGenerator()->create_user(['username' => 'ana.ruiz']);
        importer::apply(importer::plan($this->rows([
            'F1,adult,Marta,Gil,,,,,',
            'F1,child,,,,,ana.ruiz,,',
            'F1,child,,,,,,,S-1',
            'F1,child,,,,,,,S-2',
        ])));
        // Another source is not this list's business.
        $other = api::create_family('oneroster', 'household');
        api::add_child($other, null, 'oneroster:abc');

        $rows = $this->rows(['F1,adult,Marta,Gil,,,,,', 'F1,child,,,,,,,S-1']);
        $this->assertSame([], importer::plan($rows)['leaving']);

        $plan = importer::plan($rows, 'csv', true);
        $this->assertSame(1, $plan['counts']['childrenleaving']);
        $this->assertSame('csv:S-2', $plan['leaving'][0]->sourcekey);
        importer::apply($plan);

        $this->assertSame(api::LEFT, $DB->get_field('local_oksigeniafamily_child', 'status', ['sourcekey' => 'csv:S-2']));
        $this->assertSame(api::ACTIVE, $DB->get_field('local_oksigeniafamily_child', 'status', ['sourcekey' => 'csv:S-1']));
        $this->assertSame(api::ACTIVE, $DB->get_field('local_oksigeniafamily_child', 'status', ['userid' => $ana->id]));
        $this->assertSame(api::ACTIVE, $DB->get_field('local_oksigeniafamily_child', 'status', ['sourcekey' => 'oneroster:abc']));

        // Listed again, they come back.
        $plan = importer::plan($this->rows(['F1,adult,Marta,Gil,,,,,', 'F1,child,,,,,,,S-2']));
        $this->assertSame(1, $plan['counts']['childrennew']);
        importer::apply($plan);
        $this->assertSame(api::ACTIVE, $DB->get_field('local_oksigeniafamily_child', 'status', ['sourcekey' => 'csv:S-2']));
        $this->assertSame(3, $DB->count_records('local_oksigeniafamily_child', ['familyid' => $plan['families'][0]['id']]));
    }

    /**
     * A closed family does not take the rows of its key: a new one starts.
     */
    public function test_closed_family(): void {
        global $DB;
        $this->resetAfterTest();

        $this->getDataGenerator()->create_user(['username' => 'ana.ruiz']);
        $rows = $this->rows(['F1,adult,Marta,Gil,,,,,', 'F1,child,,,,,ana.ruiz,,']);
        importer::apply(importer::plan($rows));
        api::close_family((int) $DB->get_field('local_oksigeniafamily_family', 'id', ['sourcekey' => 'F1']));

        $plan = importer::plan($rows);
        $this->assertSame(1, $plan['counts']['familiesnew']);
    }
}
