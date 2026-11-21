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
 * Tests for «Not of my family»: who can take whom out of a family, with made-up people.
 *
 * @package    local_oksigeniafamily
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniafamily\local\notyours
 */
#[\PHPUnit\Framework\Attributes\CoversClass(notyours::class)]
final class notyours_test extends \advanced_testcase {
    /**
     * An adult of the family takes out someone who joined with a code in the last month, and nobody else can.
     */
    public function test_who_can_take_out_whom(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $mother = $gen->create_user();
        $stranger = $gen->create_user();
        $child = $gen->create_user();

        $familyid = api::create_family('cifra', 'cifra:h1');
        api::add_child($familyid, (int) $child->id, 'cifra:c1');
        $motherid = api::add_adult($familyid, ['firstname' => 'Marta', 'lastname' => 'Gil', 'userid' => $mother->id,
            'sourcekey' => 'cifra:a1']);
        $fatherid = api::add_adult($familyid, ['firstname' => 'Pedro', 'lastname' => 'Sosa', 'sourcekey' => 'cifra:a2']);
        $newid = api::add_adult($familyid, ['firstname' => 'Laura', 'lastname' => 'Ruiz', 'phone' => '+34600000001']);

        // The newcomer, by an adult of the family: yes. The school's adult, or oneself: no. From outside: no.
        $this->assertSame(notyours::ASK, notyours::state($newid, (int) $mother->id));
        $this->assertSame(notyours::CANNOT, notyours::state($fatherid, (int) $mother->id));
        $this->assertSame(notyours::CANNOT, notyours::state($motherid, (int) $mother->id));
        $this->assertSame(notyours::CANNOT, notyours::state($newid, (int) $stranger->id));

        // After a month, it is the school's.
        $DB->set_field('local_oksigeniafamily_adult', 'timecreated', time() - notyours::WINDOW - 1, ['id' => $newid]);
        $this->assertSame(notyours::CANNOT, notyours::remove($newid, (int) $mother->id));
        $DB->set_field('local_oksigeniafamily_adult', 'timecreated', time(), ['id' => $newid]);

        $this->assertSame(notyours::DONE, notyours::remove($newid, (int) $mother->id));
        $this->assertSame(api::LEFT, $DB->get_field('local_oksigeniafamily_adult', 'status', ['id' => $newid]));
        $this->assertSame(notyours::GONE, notyours::state($newid, (int) $mother->id));
        $this->assertSame(api::ACTIVE, $DB->get_field('local_oksigeniafamily_adult', 'status', ['id' => $fatherid]));

        $context = notyours::context($newid, notyours::DONE);
        $this->assertFalse($context['ask']);
        $this->assertStringContainsString('Laura Ruiz', $context['message']);
    }

    /**
     * The notice that someone joined gives an adult of the family «Not of my family», and the school the record.
     */
    public function test_the_notice_leads_here(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $mother = $gen->create_user();
        $child = $gen->create_user();
        $familyid = api::create_family('cifra', 'cifra:h2');
        api::add_child($familyid, (int) $child->id, 'cifra:c2');
        $motherid = api::add_adult($familyid, ['firstname' => 'Marta', 'lastname' => 'Gil', 'userid' => $mother->id]);
        $newid = api::add_adult($familyid, ['firstname' => 'Laura', 'lastname' => 'Ruiz', 'phone' => '+34600000002']);

        $sink = $this->redirectMessages();
        notices::new_adult(
            $DB->get_record('local_oksigeniafamily_adult', ['id' => $newid]),
            (int) $child->id,
            [$DB->get_record('local_oksigeniafamily_adult', ['id' => $motherid])]
        );
        $messages = array_values(array_filter($sink->get_messages(), fn($m) => (int) $m->useridto === (int) $mother->id));
        $sink->close();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('/local/oksigeniafamily/notyours.php?a=' . $newid, $messages[0]->contexturl);
        $this->assertSame(get_string('notice:newadult:notyours', 'local_oksigeniafamily'), $messages[0]->contexturlname);
    }
}
