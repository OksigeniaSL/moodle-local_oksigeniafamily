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
}
