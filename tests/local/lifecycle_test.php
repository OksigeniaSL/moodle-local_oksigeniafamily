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
 * Tests for the records keeping up to date on their own.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_oksigeniafamily\local\lifecycle
 * @covers \local_oksigeniafamily\observer
 */
#[\PHPUnit\Framework\Attributes\CoversClass(lifecycle::class)]
final class lifecycle_test extends \advanced_testcase {
    /**
     * A suspended student leaves; the family waits for the grace period, then closes, then is erased after its
     * retention; a deleted student leaves at once.
     */
    public function test_round(): void {
        global $DB;
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $ana = $generator->create_user();
        $leo = $generator->create_user();
        $familyid = api::create_family();
        $adultid = api::add_adult($familyid, ['firstname' => 'Pilar', 'phone' => '+34600000001']);
        $anaid = api::add_child($familyid, (int) $ana->id);
        $otherfamily = api::create_family();
        api::add_adult($otherfamily, ['firstname' => 'Luis']);
        $leoid = api::add_child($otherfamily, (int) $leo->id);

        // Suspended: leaves with the daily round.
        $DB->set_field('user', 'suspended', 1, ['id' => $ana->id]);
        set_config('gracedays', 30, 'local_oksigeniafamily');
        $now = time();
        lifecycle::run($now);
        $this->assertSame(api::LEFT, $DB->get_field('local_oksigeniafamily_child', 'status', ['id' => $anaid]));
        // Within the grace period the family stays open.
        $this->assertSame(api::ACTIVE, $DB->get_field('local_oksigeniafamily_family', 'status', ['id' => $familyid]));

        // After it, the family closes and its adult leaves.
        lifecycle::run($now + 31 * DAYSECS);
        $this->assertSame(api::CLOSED, $DB->get_field('local_oksigeniafamily_family', 'status', ['id' => $familyid]));
        $this->assertSame(api::LEFT, $DB->get_field('local_oksigeniafamily_adult', 'status', ['id' => $adultid]));
        $this->assertNull(api::adult_by_phone('+34600000001'));

        // After the retention period, it is erased with its adults.
        set_config('retentiondays', 0, 'local_oksigeniafamily');
        lifecycle::run(time() + 1);
        $this->assertFalse($DB->record_exists('local_oksigeniafamily_family', ['id' => $familyid]));
        $this->assertFalse($DB->record_exists('local_oksigeniafamily_adult', ['id' => $adultid]));

        // A deleted account leaves at once.
        delete_user($leo);
        $this->assertSame(api::LEFT, $DB->get_field('local_oksigeniafamily_child', 'status', ['id' => $leoid]));
    }

    /**
     * A student suspended for a while (the summer, a sync that went wrong) comes back to the family when the account is
     * active again, and the family's «no» to the use of their image is still there. One who left for another reason does
     * not come back by itself.
     */
    public function test_suspended_student_comes_back(): void {
        global $DB;
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $ana = $generator->create_user();
        $eva = $generator->create_user();
        $parent = $generator->create_user();
        $familyid = api::create_family();
        $adultid = api::add_adult($familyid, ['firstname' => 'Pilar', 'userid' => (int) $parent->id]);
        $anaid = api::add_child($familyid, (int) $ana->id);
        $evaid = api::add_child($familyid, (int) $eva->id);
        standing::record('user:' . $ana->id, standing::IMAGE, false);
        $this->assertFalse(standing::may_show(standing::keys_for_user((int) $ana->id)));
        set_config('gracedays', 30, 'local_oksigeniafamily');

        // Ana is suspended and leaves with the round, with the reason; Eva is taken out by hand.
        $DB->set_field('user', 'suspended', 1, ['id' => $ana->id]);
        api::unlink_child($evaid, 'manual');
        $now = time();
        lifecycle::run($now);
        $this->assertSame(api::LEFT, $DB->get_field('local_oksigeniafamily_child', 'status', ['id' => $anaid]));
        $this->assertSame('suspended', $DB->get_field('local_oksigeniafamily_child', 'leftreason', ['id' => $anaid]));
        $this->assertSame('manual', $DB->get_field('local_oksigeniafamily_child', 'leftreason', ['id' => $evaid]));

        // Well past the grace period of the photos, the «no» is still there while the account is.
        lifecycle::run($now + 40 * DAYSECS);
        $this->assertSame(1, $DB->count_records('local_oksigeniafamily_standing', ['childkey' => 'user:' . $ana->id]));

        // Back from the suspension: she is in her family again, with the role of her parent, and her «no» stands.
        $DB->set_field('local_oksigeniafamily_family', 'status', api::ACTIVE, ['id' => $familyid]);
        $DB->set_field('local_oksigeniafamily_adult', 'status', api::ACTIVE, ['id' => $adultid]);
        $DB->set_field('user', 'suspended', 0, ['id' => $ana->id]);
        lifecycle::run($now + 41 * DAYSECS);
        $this->assertSame(api::ACTIVE, $DB->get_field('local_oksigeniafamily_child', 'status', ['id' => $anaid]));
        $this->assertNull($DB->get_field('local_oksigeniafamily_child', 'leftreason', ['id' => $anaid]));
        $this->assertTrue(api::adult_has_child($adultid, (int) $ana->id));
        $this->assertFalse(standing::may_show(standing::keys_for_user((int) $ana->id)));
        // Eva, taken out by hand, stays out.
        $this->assertSame(api::LEFT, $DB->get_field('local_oksigeniafamily_child', 'status', ['id' => $evaid]));
    }

    /**
     * A closed family does not open again when a suspended student comes back.
     */
    public function test_closed_family_stays_closed(): void {
        global $DB;
        $this->resetAfterTest();

        $ana = $this->getDataGenerator()->create_user();
        $familyid = api::create_family();
        api::add_adult($familyid, ['firstname' => 'Pilar']);
        $anaid = api::add_child($familyid, (int) $ana->id);
        set_config('gracedays', 30, 'local_oksigeniafamily');

        $DB->set_field('user', 'suspended', 1, ['id' => $ana->id]);
        lifecycle::run(time());
        lifecycle::run(time() + 31 * DAYSECS);
        $this->assertSame(api::CLOSED, $DB->get_field('local_oksigeniafamily_family', 'status', ['id' => $familyid]));

        $DB->set_field('user', 'suspended', 0, ['id' => $ana->id]);
        lifecycle::run(time() + 32 * DAYSECS);
        $this->assertSame(api::LEFT, $DB->get_field('local_oksigeniafamily_child', 'status', ['id' => $anaid]));
        $this->assertSame(api::CLOSED, $DB->get_field('local_oksigeniafamily_family', 'status', ['id' => $familyid]));
    }

    /**
     * In a family that goes on, who left is erased after the retention period, and so are the codes that no longer work.
     */
    public function test_people_who_left_are_erased(): void {
        global $DB;
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $ana = $generator->create_user();
        $leo = $generator->create_user();
        $familyid = api::create_family();
        $stays = api::add_adult($familyid, ['firstname' => 'Marta', 'phone' => '+14155550101']);
        $goes = api::add_adult($familyid, ['firstname' => 'Luis', 'phone' => '+14155550102']);
        api::add_child($familyid, (int) $ana->id);
        $leoid = api::add_child($familyid, (int) $leo->id);
        api::remove_adult($goes);
        api::unlink_child($leoid, 'manual');
        api::issue_claim_code((int) $ana->id);
        api::revoke_claim_codes((int) $ana->id);
        api::issue_claim_code((int) $ana->id);

        set_config('retentiondays', 90, 'local_oksigeniafamily');
        lifecycle::purge_left(time() + 89 * DAYSECS);
        $this->assertTrue($DB->record_exists('local_oksigeniafamily_adult', ['id' => $goes]));
        $this->assertTrue($DB->record_exists('local_oksigeniafamily_child', ['id' => $leoid]));
        $this->assertSame(2, $DB->count_records('local_oksigeniafamily_claim', ['userid' => $ana->id]));

        lifecycle::purge_left(time() + 91 * DAYSECS);
        $this->assertFalse($DB->record_exists('local_oksigeniafamily_adult', ['id' => $goes]));
        $this->assertFalse($DB->record_exists('local_oksigeniafamily_child', ['id' => $leoid]));
        $this->assertTrue($DB->record_exists('local_oksigeniafamily_adult', ['id' => $stays]));
        // The withdrawn code has gone; the other one lasts its 60 days, and its retention starts when it expires.
        $this->assertSame(1, $DB->count_records('local_oksigeniafamily_claim', ['userid' => $ana->id]));
        lifecycle::purge_left(time() + 151 * DAYSECS);
        $this->assertSame(0, $DB->count_records('local_oksigeniafamily_claim', ['userid' => $ana->id]));
    }
}
