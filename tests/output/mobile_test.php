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

namespace local_oksigeniafamily\output;

use local_oksigeniafamily\api;
use local_oksigeniafamily\local\consent;

/**
 * Tests for the requests for consent in the app: the menu entry only for whoever has one, the list, and the page, where
 * what the school wrote travels as data and never inside the template.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_oksigeniafamily\output\mobile
 */
#[\PHPUnit\Framework\Attributes\CoversClass(mobile::class)]
final class mobile_test extends \advanced_testcase {
    /**
     * The menu entry, the list and the page of a request, for the adult and for someone else.
     */
    public function test_pages(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $child = $generator->create_user(['firstname' => 'Lucas', 'lastname' => 'Pérez']);
        $parent = $generator->create_user();
        $someone = $generator->create_user();
        $familyid = api::create_family();
        $adultid = api::add_adult($familyid, ['firstname' => 'Pilar', 'lastname' => 'Sanz', 'userid' => $parent->id]);
        api::add_child($familyid, (int) $child->id);
        $requestid = consent::create(\context_system::instance(), [
            'title' => 'Salida {{al}} Teide @if',
            'message' => '<p>Salimos a las 8:30.</p>',
            'deadline' => time() + 3 * DAYSECS,
        ], [(int) $child->id], (int) get_admin()->id);

        // Someone with nothing to answer: no menu entry, but the links still open inside the app.
        $this->setUser($someone);
        $init = mobile::mobile_init([]);
        $this->assertTrue($init['disabled']);
        $this->assertStringContainsString('local_oksigeniafamily_consent', $init['javascript']);
        $page = mobile::mobile_consent_view(['id' => $requestid]);
        $this->assertSame('{}', $page['otherdata']['request']);
        $this->assertSame('[]', $page['otherdata']['sections']);

        $this->setUser($parent);
        $this->assertFalse(mobile::mobile_init([])['disabled']);
        $list = json_decode(mobile::mobile_consents_view([])['otherdata']['items'], true);
        $this->assertCount(1, $list);
        $this->assertSame($requestid, $list[0]['id']);

        $page = mobile::mobile_consent_view(['id' => $requestid]);
        $request = json_decode($page['otherdata']['request'], true);
        $sections = json_decode($page['otherdata']['sections'], true);
        $this->assertSame($requestid, $request['id']);
        $this->assertTrue($request['open']);
        $this->assertSame($adultid, $sections[0]['adultid']);
        $this->assertSame((int) $child->id, $sections[0]['children'][0]['id']);
        $this->assertFalse($sections[0]['children'][0]['answered']);
        $this->assertSame(['a' . $adultid . 'c' . $child->id => ''], json_decode($page['otherdata']['comments'], true));
        // The title is data: the template the app compiles never has it.
        $this->assertStringContainsString('{{al}}', $request['title']);
        $this->assertStringNotContainsString('Teide', $page['templates'][0]['html']);
        $this->assertStringContainsString('local_oksigeniafamily_answer_consent', $page['templates'][0]['html']);

        consent::answer($requestid, (int) $child->id, $adultid, consent::NO, 'app');
        $sections = json_decode(mobile::mobile_consent_view(['id' => $requestid])['otherdata']['sections'], true);
        $this->assertTrue($sections[0]['children'][0]['answered']);
        $this->assertFalse($sections[0]['children'][0]['saidyes']);
    }
}
