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
 * Tests for the letters and for whom they are for.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_oksigeniafamily\local\letters
 * @covers \local_oksigeniafamily\local\students
 */
#[\PHPUnit\Framework\Attributes\CoversClass(letters::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(students::class)]
final class letters_test extends \advanced_testcase {
    /**
     * The choices: who has no family or code, who has no code, and everyone; only hashes are kept, the letters' codes
     * work, and replacing stops the old ones.
     */
    public function test_issue_and_replace(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $ana = $generator->create_user(['firstname' => 'Ana', 'lastname' => 'Ruiz']);
        $leo = $generator->create_user(['firstname' => 'Leo', 'lastname' => 'Ruiz']);
        $nora = $generator->create_user(['firstname' => 'Nora', 'lastname' => 'Vega']);
        $users = [$ana->id => $ana, $leo->id => $leo, $nora->id => $nora];
        // Leo has a family, and Nora a code in use.
        $familyid = api::create_family();
        api::add_adult($familyid, ['firstname' => 'Marta']);
        api::add_child($familyid, (int) $leo->id);
        $noracode = api::issue_claim_code((int) $nora->id);

        $now = time();
        $state = students::state(array_keys($users), $now);
        $this->assertSame(1, $state[$leo->id]['adults']);
        $this->assertGreaterThan($now, $state[$nora->id]['expires']);
        $this->assertSame(
            [letters::PENDING => 1, letters::NOCODE => 2, letters::REPLACE => 3],
            letters::counts($state)
        );

        $pending = letters::issue($users, letters::PENDING, $now);
        $this->assertCount(1, $pending);
        $this->assertSame((int) $ana->id, (int) $pending[0]['user']->id);

        // Nobody repeats: Ana now has a code, and Leo a family.
        $this->assertSame([], letters::issue($users, letters::PENDING, $now));

        // No record holds the code in the clear.
        $row = $DB->get_record('local_oksigeniafamily_claim', ['userid' => $ana->id]);
        $this->assertSame(hash('sha256', $pending[0]['code']), $row->codehash);
        foreach ((array) $row as $value) {
            $this->assertNotSame($pending[0]['code'], $value);
        }
        $sink = $this->redirectEvents();

        // Without a code: Leo only, even with a family.
        $nocode = letters::issue($users, letters::NOCODE, $now);
        $this->assertSame([(int) $leo->id], array_map(fn($one) => (int) $one['user']->id, $nocode));
        foreach ($sink->get_events() as $event) {
            $this->assertStringNotContainsString($nocode[0]['code'], json_encode($event->get_data()));
        }

        // Everyone: the old codes stop, the new ones work.
        $replaced = letters::issue($users, letters::REPLACE, $now);
        $this->assertCount(3, $replaced);
        try {
            api::claim($noracode, ['firstname' => 'Luis']);
            $this->fail('A replaced code must not join anyone.');
        } catch (\moodle_exception $e) {
            $this->assertSame('claiminvalid', $e->errorcode);
        }
        $joined = api::claim($replaced[2]['code'], ['firstname' => 'Luis']);
        $this->assertSame((int) $nora->id, $joined->childuserid);
        $this->assertSame(1, students::state([(int) $nora->id], $now)[$nora->id]['claims']);
    }

    /**
     * Revoking says how many it stopped, and leaves a trace of who did it.
     */
    public function test_revoke(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $ana = $this->getDataGenerator()->create_user();
        $this->assertSame(0, api::revoke_claim_codes((int) $ana->id));

        api::issue_claim_code((int) $ana->id);
        $sink = $this->redirectEvents();
        $this->assertSame(1, api::revoke_claim_codes((int) $ana->id));
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(\local_oksigeniafamily\event\codes_revoked::class, $events[0]);
        $this->assertSame((int) $ana->id, (int) $events[0]->relateduserid);
        $this->assertSame(0, students::state([(int) $ana->id], time())[$ana->id]['expires']);
    }

    /**
     * Who a letter says, and a QR that takes the code after the # of the address, out of what the server sees.
     */
    public function test_context(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $ana = $this->getDataGenerator()->create_user(['firstname' => 'Ana', 'lastname' => 'Ruiz']);
        $issued = letters::issue([$ana->id => $ana], letters::REPLACE, time());
        $context = letters::context($issued, 'Year 5');
        $this->assertCount(1, $context);
        $this->assertStringContainsString('Ana Ruiz', $context[0]['title']);
        $this->assertSame('Year 5', $context[0]['group']);
        $this->assertMatchesRegularExpression('/^[2-9A-HJKMNP-Z]{4}-[2-9A-HJKMNP-Z]{4}$/', $context[0]['code']);
        $this->assertStringNotContainsString($issued[0]['code'], $context[0]['step1']);

        $svg = letters::qr_svg('https://example.com/local/oksigeniafamily/claim.php#code=ABCD-EFGH', 'QR');
        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringContainsString('aria-label="QR"', $svg);
        $this->assertMatchesRegularExpression('/<path fill="#000" d="(M\d+ \d+h\d+v1h-\d+z)+"/', $svg);
    }

    /**
     * Whom a group, a course or a cohort stands for: live students only, no teachers; a student on their own; and the
     * searches.
     */
    public function test_scopes_and_searches(): void {
        global $CFG;
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/cohort/lib.php');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['fullname' => 'Mathematics five', 'shortname' => 'MAT5']);
        $group = $generator->create_group(['courseid' => $course->id, 'name' => 'Blue group']);
        $cohort = $generator->create_cohort(['name' => 'Year five']);
        $ana = $generator->create_user(['firstname' => 'Ana', 'lastname' => 'Ruiz', 'username' => 'ana.ruiz']);
        $leo = $generator->create_user(['firstname' => 'Leo', 'lastname' => 'Ruiz', 'idnumber' => 'S-77']);
        $gone = $generator->create_user(['firstname' => 'Tomas', 'lastname' => 'Baja', 'suspended' => 1]);
        $teacher = $generator->create_user(['firstname' => 'Javier', 'lastname' => 'Luna']);
        foreach ([$ana, $leo, $gone] as $student) {
            $generator->enrol_user($student->id, $course->id, 'student');
            cohort_add_member($cohort->id, $student->id);
        }
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        foreach ([$ana, $gone, $teacher] as $member) {
            $generator->create_group_member(['groupid' => $group->id, 'userid' => $member->id]);
        }

        $ids = fn(string $scope, int $id) => array_map('intval', array_keys(students::of_scope($scope, $id)['users']));
        $this->assertSame([(int) $ana->id], $ids('group', (int) $group->id));
        $this->assertEqualsCanonicalizing([(int) $ana->id, (int) $leo->id], $ids('course', (int) $course->id));
        $this->assertEqualsCanonicalizing([(int) $ana->id, (int) $leo->id], $ids('cohort', (int) $cohort->id));
        $this->assertSame([(int) $ana->id], $ids('user', (int) $ana->id));
        $this->assertSame('Blue group', students::of_scope('group', (int) $group->id)['name']);
        $this->assertNull(students::of_scope('user', (int) $gone->id));
        $this->assertNull(students::of_scope('group', 0));
        $this->assertNull(students::of_scope('elsewhere', 1));
        $this->assertNull(students::of_scope('course', SITEID));

        $found = fn(string $query) => array_map('intval', array_keys(students::search($query)));
        $this->assertSame([(int) $ana->id], $found('Ana Ruiz'));
        $this->assertSame([(int) $ana->id], $found('ana.ru'));
        $this->assertSame([(int) $leo->id], $found('S-77'));
        $this->assertSame([], $found('Tomas'));

        $places = students::search_courses_and_groups('five');
        $this->assertSame('Mathematics five', $places['courses'][0]->fullname);
        $this->assertSame('Blue group', students::search_courses_and_groups('blue')['groups'][0]->name);
        $cohorts = students::cohorts('five');
        $this->assertCount(1, $cohorts);
        $this->assertSame(2, (int) $cohorts[0]->members);
        $this->assertSame([], students::cohorts('nothing'));
    }
}
