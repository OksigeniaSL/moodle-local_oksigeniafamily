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
 * Tests for what a student's own pages show their family to join: the code, its QR and the steps.
 *
 * @package    local_oksigeniafamily
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniafamily\api::join_for_student
 * @covers     \local_oksigeniafamily\local\letters::join_steps
 * @covers     \local_oksigeniafamily\local\join
 * @covers     \local_oksigeniafamily\output\mobile
 */
final class join_test extends \advanced_testcase {
    /**
     * One code for the student's pages, the same each time, which joins the student; a letter's code stays good.
     */
    public function test_one_code_shown_again(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('enabled', 1, 'local_oksigeniafamily');
        $student = $this->getDataGenerator()->create_user(['firstname' => 'Leo']);
        $letter = api::issue_claim_code((int) $student->id);

        $join = api::join_for_student((int) $student->id);

        $this->assertMatchesRegularExpression('/^[2-9A-HJKMNP-Z]{4}-[2-9A-HJKMNP-Z]{4}$/', $join['code']);
        $this->assertStringEndsWith('/local/oksigeniafamily/claim.php#code=' . $join['code'], $join['url']);
        $this->assertSame($join['code'], api::join_for_student((int) $student->id)['code']);
        $this->assertSame((int) $student->id, api::code_student($join['code']));
        $this->assertSame((int) $student->id, api::code_student($letter));
        $this->assertSame(0, $join['adults']);
        $this->assertSame(0, $join['inapp']);

        // The page decides the size and the ink of the QR.
        $this->assertStringContainsString('fill="currentColor"', $join['qrsvg']);
        $this->assertStringContainsString('viewBox="', $join['qrsvg']);
        $this->assertStringNotContainsString('width=', $join['qrsvg']);

        // Who joins with it counts.
        api::claim($join['code'], ['firstname' => 'Ana', 'lastname' => 'Paz']);
        $this->assertSame(1, api::join_for_student((int) $student->id)['adults']);

        // Near its end, a new one; the one before stops, the letter's does not.
        $DB->set_field('local_oksigeniafamily_claim', 'expires', time() + DAYSECS, ['purpose' => 'page']);
        $next = api::join_for_student((int) $student->id);
        $this->assertNotSame($join['code'], $next['code']);
        $this->assertNull(api::code_student($join['code']));
        $this->assertSame((int) $student->id, api::code_student($letter));
        $this->assertSame(1, $DB->count_records('local_oksigeniafamily_claim', ['purpose' => 'page', 'status' => 'active']));

        $this->assertNull(api::join_for_student(-5));
    }

    /**
     * Where families do not get into the app, the letter's steps, the same on both phones.
     */
    public function test_steps_without_the_app(): void {
        $this->resetAfterTest();
        set_config('familyapp', 0, 'local_oksigeniaaty');

        $steps = letters::join_steps('example.org/familia');

        $this->assertSame($steps['ios'], $steps['android']);
        $this->assertSame(['scan', 'form', 'check'], array_column($steps['ios'], 'kind'));
        $this->assertStringContainsString('example.org/familia', $steps['ios'][0]['text']);
    }

    /**
     * Where they do, an iPhone installs the app first; Android finds it from «Open in the app».
     */
    public function test_steps_with_the_app(): void {
        if (!class_exists('\\local_oksigeniaaty\\api')) {
            $this->markTestSkipped('Oksigenia Aty is not installed.');
        }
        $this->resetAfterTest();
        set_config('familyapp', 1, 'local_oksigeniaaty');
        set_config('enabled', 1, 'local_oksigeniafamily');
        set_config('enablewebservices', 1);
        set_config('enablemobilewebservice', 1);
        set_config('forcedurlscheme', 'oksigeniaaty', 'tool_mobile');
        set_config('typeoflogin', 1, 'tool_mobile');
        \core\plugininfo\auth::enable_plugin('webservice', 1);

        $steps = letters::join_steps('example.org/familia');

        $this->assertSame(['install', 'scan', 'form', 'open', 'pin', 'siblings'], array_column($steps['ios'], 'kind'));
        $this->assertSame(['scan', 'form', 'open', 'pin', 'siblings'], array_column($steps['android'], 'kind'));
    }

    /**
     * Who gets «For your family»: a student, or a child of a family; not a teacher, an adult of a family or a family's
     * own account; and only where the centre has it on.
     */
    public function test_who_gets_the_page(): void {
        $this->resetAfterTest();
        set_config('enabled', 1, 'local_oksigeniafamily');
        set_config('studentpage', 1, 'local_oksigeniafamily');
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $student = $gen->create_user();
        $teacher = $gen->create_user();
        $both = $gen->create_user();
        $gen->enrol_user($student->id, $course->id, 'student');
        $gen->enrol_user($teacher->id, $course->id, 'editingteacher');
        $gen->enrol_user($both->id, $course->id, 'student');
        $gen->enrol_user($both->id, $gen->create_course()->id, 'teacher');
        $nobody = $gen->create_user();
        $own = $gen->create_user(['auth' => 'webservice']);

        $this->assertTrue(join::eligible((int) $student->id));
        $this->assertFalse(join::eligible((int) $teacher->id));
        $this->assertFalse(join::eligible((int) $both->id));
        $this->assertFalse(join::eligible((int) $nobody->id));
        $this->assertFalse(join::eligible((int) $own->id));
        $this->assertFalse(join::eligible((int) get_admin()->id));

        // A child of a family has it, with or without a course.
        $family = api::create_family();
        api::add_child($family, (int) $nobody->id);
        $this->assertTrue(join::eligible((int) $nobody->id));

        // An adult of a family does not, even enrolled as a student somewhere.
        $parent = $gen->create_user();
        $gen->enrol_user($parent->id, $course->id, 'student');
        api::add_adult($family, ['firstname' => 'Eva', 'userid' => (int) $parent->id]);
        $this->assertFalse(join::eligible((int) $parent->id));

        set_config('studentpage', 0, 'local_oksigeniafamily');
        $this->assertFalse(join::eligible((int) $student->id));
    }

    /**
     * The page, on the site and in the app; and the card on Aty's home until somebody of the family joins.
     */
    public function test_page_and_card(): void {
        $this->resetAfterTest();
        set_config('enabled', 1, 'local_oksigeniafamily');
        set_config('studentpage', 1, 'local_oksigeniafamily');
        $gen = $this->getDataGenerator();
        $student = $gen->create_user();
        $gen->enrol_user($student->id, $gen->create_course()->id, 'student');
        $this->setUser($student);

        $context = join::context((int) $student->id);
        $this->assertFalse($context['twophones']);
        $this->assertSame(['scan', 'form', 'check'], array_column($context['steps'], 'kind'));
        $this->assertFalse($context['hasstatus']);

        $view = \local_oksigeniafamily\output\mobile::mobile_join_view([]);
        $this->assertStringContainsString($context['code'], $view['templates'][0]['html']);
        $this->assertStringContainsString('<svg', $view['templates'][0]['html']);
        $this->assertFalse(\local_oksigeniafamily\output\mobile::mobile_join_init([])['disabled']);

        $card = join::home_card((int) $student->id);
        $this->assertCount(1, $card);
        $this->assertSame('mobile_join_view', $card[0]['items'][0]['method']);

        // Once somebody of the family joins, the card goes and the page says so.
        api::claim($context['code'], ['firstname' => 'Ana', 'lastname' => 'Paz']);
        $this->assertSame([], join::home_card((int) $student->id));
        $this->assertSame(get_string('join:joined', 'local_oksigeniafamily'), join::context((int) $student->id)['status']);

        // A teacher has no page in the app's menu.
        $teacher = $gen->create_user();
        $gen->enrol_user($teacher->id, $gen->create_course()->id, 'editingteacher');
        $this->setUser($teacher);
        $this->assertTrue(\local_oksigeniafamily\output\mobile::mobile_join_init([])['disabled']);
    }

    /**
     * An adult brings in another adult of the household: they join the same family, with all its children.
     */
    public function test_invite_another_adult(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('enabled', 1, 'local_oksigeniafamily');
        $gen = $this->getDataGenerator();
        $leo = $gen->create_user(['firstname' => 'Leo']);
        $lola = $gen->create_user(['firstname' => 'Lola']);
        $teacher = $gen->create_user(['firstname' => 'Eva']);
        $eva = api::claim(api::issue_claim_code((int) $leo->id), ['userid' => (int) $teacher->id, 'firstname' => 'Eva']);
        api::claim(api::issue_claim_code((int) $lola->id), ['userid' => (int) $teacher->id, 'firstname' => 'Eva']);
        $family = (int) $eva->familyid;

        $invite = api::invite_for_family($family);
        $this->assertSame($invite['code'], api::invite_for_family($family)['code']);

        $sink = $this->redirectMessages();
        $luis = api::claim($invite['code'], ['firstname' => 'Luis', 'lastname' => 'Rey']);
        $sink->close();

        $this->assertSame($family, (int) $luis->familyid);
        $this->assertCount(2, api::children_of_adult((int) $luis->id));
        $this->assertSame(1, $DB->count_records('local_oksigeniafamily_family', ['status' => 'active']));

        // A child of the family cannot come in as an adult.
        try {
            api::claim($invite['code'], ['userid' => (int) $leo->id, 'firstname' => 'Leo']);
            $this->fail('A child joins as an adult.');
        } catch (\moodle_exception $e) {
            $this->assertSame('claimself', $e->errorcode);
        }

        // Eva's page: the QR, without the step of adding another child, which the invitation already does.
        $context = join::invite_context((int) $teacher->id);
        $this->assertSame($invite['code'], $context['code']);
        $this->assertNotContains('siblings', array_column($context['steps'], 'kind'));
        $this->setUser($teacher);
        $view = \local_oksigeniafamily\output\mobile::mobile_invite_view([]);
        $this->assertStringContainsString($invite['code'], $view['templates'][0]['html']);

        // Somebody with no family has nothing to invite to.
        $this->assertNull(join::invite_context((int) $gen->create_user()->id));
    }

    /**
     * Where the page is, for Meals and for a message to families: without a shortener, the full address.
     */
    public function test_page_address(): void {
        $this->resetAfterTest();

        $this->assertStringEndsWith('/local/oksigeniafamily/join.php', api::family_page_url());
        $this->assertStringEndsWith('/local/oksigeniafamily/join.php?id=42', api::family_page_url(42));
        $this->assertSame(api::family_page_url(), api::family_page_short());
    }
}
