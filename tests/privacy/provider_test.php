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

namespace local_oksigeniafamily\privacy;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_oksigeniafamily\api;

/**
 * Tests for the privacy provider: the records of an adult and of a student, exported and deleted with their account.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_oksigeniafamily\privacy\provider
 */
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
final class provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * Who has data, what is exported, and what deleting takes away, the codes of a student and who issued them included.
     */
    public function test_export_and_delete(): void {
        global $DB;
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $parent = $generator->create_user();
        $ana = $generator->create_user();
        $secretary = $generator->create_user();
        $nobody = $generator->create_user();
        $familyid = api::create_family('csv', 'F1');
        api::add_adult($familyid, ['firstname' => 'Marta', 'phone' => '+14155550101', 'userid' => $parent->id]);
        api::add_child($familyid, (int) $ana->id, 'csv:S-1');
        $this->setUser($secretary);
        api::issue_claim_code((int) $ana->id);

        $system = \context_system::instance();
        foreach ([$parent, $ana, $secretary] as $user) {
            $contexts = provider::get_contexts_for_userid((int) $user->id)->get_contextids();
            $this->assertSame([(int) $system->id], array_map('intval', $contexts));
        }
        $this->assertCount(0, provider::get_contexts_for_userid((int) $nobody->id));

        $list = new userlist($system, 'local_oksigeniafamily');
        provider::get_users_in_context($list);
        $this->assertEqualsCanonicalizing(
            [(int) $parent->id, (int) $ana->id, (int) $secretary->id],
            array_map('intval', $list->get_userids())
        );

        provider::export_user_data(new approved_contextlist($parent, 'local_oksigeniafamily', [$system->id]));
        $data = writer::with_context($system)->get_data([get_string('pluginname', 'local_oksigeniafamily')]);
        $this->assertSame('+14155550101', $data->adult[0]->phone);
        writer::reset();
        provider::export_user_data(new approved_contextlist($ana, 'local_oksigeniafamily', [$system->id]));
        $data = writer::with_context($system)->get_data([get_string('pluginname', 'local_oksigeniafamily')]);
        $this->assertSame('csv:S-1', $data->child[0]->sourcekey);
        $this->assertCount(1, $data->claims);
        $this->assertObjectNotHasProperty('codehash', $data->claims[0]);

        // Who issued a code loses the link to it, and the code stays for the student.
        provider::delete_data_for_user(new approved_contextlist($secretary, 'local_oksigeniafamily', [$system->id]));
        $this->assertSame(0, (int) $DB->get_field('local_oksigeniafamily_claim', 'usermodified', ['userid' => $ana->id]));

        provider::delete_data_for_users(new approved_userlist($system, 'local_oksigeniafamily', [(int) $ana->id]));
        $this->assertFalse($DB->record_exists('local_oksigeniafamily_child', ['userid' => $ana->id]));
        $this->assertFalse($DB->record_exists('local_oksigeniafamily_claim', ['userid' => $ana->id]));
        $this->assertTrue($DB->record_exists('local_oksigeniafamily_adult', ['userid' => $parent->id]));

        provider::delete_data_for_all_users_in_context($system);
        $this->assertSame(0, $DB->count_records('local_oksigeniafamily_family'));
        $this->assertSame(0, $DB->count_records('local_oksigeniafamily_adult'));
    }
}
