<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_oksigeniafamily\local;

use local_oksigeniafamily\api;

/**
 * Tests for the doors of Family in «Your work»: who gets which, their figures and what closes today.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_oksigeniafamily\local\work
 */
#[\PHPUnit\Framework\Attributes\CoversClass(work::class)]
final class work_test extends \advanced_testcase {
    /**
     * Whoever runs nothing gets no door; a manager gets the families and the requests of the whole site.
     */
    public function test_who_gets_a_door(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $nobody = $generator->create_user();
        $manager = $generator->create_user();
        $generator->role_assign($DB->get_field('role', 'id', ['shortname' => 'manager']), $manager->id);
        $parent = $generator->create_user();
        $child = $generator->create_user();
        $familyid = api::create_family();
        api::add_adult($familyid, ['firstname' => 'Ana', 'userid' => (int) $parent->id]);
        api::add_adult($familyid, ['firstname' => 'Luis', 'email' => 'luis@example.com']);
        api::add_child($familyid, (int) $child->id);

        $this->assertSame([], work::for_user((int) $nobody->id, time()));
        $this->assertSame([], work::for_user((int) $parent->id, time()));

        $doors = work::for_user((int) $manager->id, time());
        $this->assertSame(['local_oksigeniafamily', 'local_oksigeniafamily-consents'], array_column($doors, 'key'));
        $this->assertSame('1 family · 1 student · 1 adult with an account', $doors[0]['status']);
        $this->assertSame('None open', $doors[1]['status']);
        $this->assertSame([], $doors[1]['alerts']);
    }

    /**
     * A teacher sees their door only while a request of theirs is open, and is told what closes today with students
     * still without an answer.
     */
    public function test_a_teacher_and_what_closes_today(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $ana = $generator->create_and_enrol($course, 'student');
        $leo = $generator->create_and_enrol($course, 'student');
        $familyid = api::create_family();
        $adultid = api::add_adult($familyid, ['firstname' => 'Pilar', 'email' => 'pilar@example.com']);
        api::add_child($familyid, (int) $ana->id);
        $context = \context_course::instance($course->id);
        $now = time();

        $this->assertSame([], work::for_user((int) $teacher->id, $now));

        $this->redirectMessages();
        $today = consent::create(
            $context,
            ['title' => 'Salida al Teide', 'deadline' => consent::end_of_day($now, $teacher)],
            [(int) $ana->id, (int) $leo->id],
            (int) $teacher->id
        );
        consent::create(
            $context,
            ['title' => 'Fotos del año', 'deadline' => $now + 5 * DAYSECS],
            [(int) $ana->id],
            (int) $teacher->id
        );

        $doors = work::for_user((int) $teacher->id, $now);
        $this->assertCount(1, $doors);
        $door = $doors[0];
        $this->assertSame('local_oksigeniafamily-consents', $door['key']);
        $this->assertStringContainsString('contextid=' . $context->id, $door['url']);
        $this->assertSame('2 open · 3 students without an answer', $door['status']);
        $this->assertCount(1, $door['alerts']);
        $this->assertSame('«Salida al Teide»: 2 students without an answer; it closes today.', $door['alerts'][0]['text']);
        $this->assertSame('today', $door['alerts'][0]['level']);
        $this->assertStringContainsString('id=' . $today, $door['alerts'][0]['url']);

        // An answer takes the student off; with none left today, no alert.
        consent::answer($today, (int) $ana->id, $adultid, consent::YES, 'email');
        $door = work::for_user((int) $teacher->id, $now)[0];
        $this->assertSame('«Salida al Teide»: one student without an answer; it closes today.', $door['alerts'][0]['text']);
        consent::answer($today, (int) $leo->id, 0, consent::YES, 'school', ['name' => 'Su madre']);
        $door = work::for_user((int) $teacher->id, $now)[0];
        $this->assertSame([], $door['alerts']);
        $this->assertSame('2 open · 1 student without an answer', $door['status']);

        // A cancelled or closed request is not open: no door.
        consent::cancel($today);
        $this->assertCount(1, work::for_user((int) $teacher->id, $now));
        $this->assertSame([], work::for_user((int) $teacher->id, $now + 6 * DAYSECS));
    }

    /**
     * Without a time, what is worked out is kept for a minute.
     */
    public function test_kept_for_a_minute(): void {
        global $DB;
        $this->resetAfterTest();
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign($DB->get_field('role', 'id', ['shortname' => 'manager']), $manager->id);

        $first = work::for_user((int) $manager->id);
        api::create_family();
        $this->assertSame($first, work::for_user((int) $manager->id));
        \cache::make('local_oksigeniafamily', 'work')->purge();
        $this->assertSame('1 family · 0 students · 0 adults with an account', work::for_user((int) $manager->id)[0]['status']);
    }
}
