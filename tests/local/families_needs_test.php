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

/**
 * Tests for what each family is missing, on the list of families.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_oksigeniafamily\local;

use local_oksigeniafamily\api;

/**
 * Tests for what each family is missing, on the list of families: the tiles, the filter and the words on each card.
 *
 * @covers \local_oksigeniafamily\local\families
 */
#[\PHPUnit\Framework\Attributes\CoversClass(families::class)]
final class families_needs_test extends \advanced_testcase {
    /**
     * Five families of example data, each missing something else, counted, filtered and said in words.
     */
    public function test_needs(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $now = time();

        // Up to date: an adult with an account and a confirmed phone, a student with an account.
        $marta = $gen->create_user(['firstname' => 'Marta', 'lastname' => 'Gil']);
        $ana = $gen->create_user(['firstname' => 'Ana', 'lastname' => 'Gil']);
        $good = api::create_family();
        api::add_adult($good, [
            'firstname' => 'Marta', 'lastname' => 'Gil', 'userid' => $marta->id, 'phone' => '+34600111222', 'phoneverified' => 1,
        ]);
        api::add_child($good, (int) $ana->id);

        // Nobody with an account, no phone, a code given and not used, a consent nobody answered.
        $hugo = $gen->create_user(['firstname' => 'Hugo', 'lastname' => 'Rey']);
        $call = api::create_family();
        api::add_adult($call, ['firstname' => 'Luis', 'lastname' => 'Rey']);
        api::add_child($call, (int) $hugo->id);
        api::issue_claim_code((int) $hugo->id);
        consent::create(
            \context_system::instance(),
            ['title' => 'Excursión', 'deadline' => $now + DAYSECS],
            [(int) $hugo->id],
            (int) get_admin()->id
        );

        // A phone not confirmed, a student only with the school's key, and the school's «no» to their image.
        $pilar = $gen->create_user(['firstname' => 'Pilar', 'lastname' => 'Sanz']);
        $know = api::create_family();
        api::add_adult($know, [
            'firstname' => 'Pilar', 'lastname' => 'Sanz', 'userid' => $pilar->id, 'phone' => '+34600333444',
        ]);
        api::add_child($know, null, 'csv:S-1');
        standing::record('csv:S-1', standing::IMAGE, false);

        // A code that was used is no longer a need; an adult's «yes» after the school's «no» lets the image be shown.
        $leo = $gen->create_user(['firstname' => 'Leo', 'lastname' => 'Paz']);
        $used = api::create_family();
        $lola = $gen->create_user();
        $adult = api::add_adult($used, [
            'firstname' => 'Lola', 'lastname' => 'Paz', 'userid' => $lola->id, 'phone' => '+34600555666', 'phoneverified' => 1,
        ]);
        api::add_child($used, (int) $leo->id);
        $code = api::issue_claim_code((int) $leo->id);
        api::claim($code, ['firstname' => 'Toni', 'lastname' => 'Paz', 'phone' => '+34600777888'], true);
        standing::record('user:' . $leo->id, standing::IMAGE, false);
        standing::record('user:' . $leo->id, standing::IMAGE, true, $adult);

        // A closed family counts for nothing.
        $closed = api::create_family();
        api::add_adult($closed, ['firstname' => 'Eva', 'lastname' => 'Mora']);
        api::close_family($closed);

        $this->assertSame([
            'pending' => 1, 'noaccount' => 1, 'code' => 1, 'phone' => 2, 'student' => 1, 'image' => 1,
        ], families::need_counts($now));

        $ids = function (string $need) use ($now): array {
            $found = families::search('', 'active', 0, $need, $now);
            return array_map(fn($family) => (int) $family->id, $found['families']);
        };
        $this->assertSame([$call], $ids('pending'));
        $this->assertSame([$call], $ids('code'));
        $this->assertSame([$call, $know], $ids('phone'));
        $this->assertSame([$know], $ids('student'));
        $this->assertSame([$know], $ids('image'));
        $this->assertSame([], families::search('Rey', 'active', 0, 'student', $now)['families']);
        $this->assertCount(4, families::search('', 'active', 0, 'nonsense', $now)['families']);

        $all = families::search('', 'active', 0, '', $now);
        $flags = families::flags($all['families'], $all['people'], 'code', $now);
        $texts = fn(int $id) => array_column($flags[$id], 'flagtext');
        $this->assertSame([], $flags[$good]);
        $this->assertSame([
            get_string('flag:pendingone', 'local_oksigeniafamily'),
            get_string('flag:noaccount', 'local_oksigeniafamily'),
            get_string('flag:code', 'local_oksigeniafamily', 'Hugo Rey'),
            get_string('flag:nophone', 'local_oksigeniafamily'),
        ], $texts($call));
        $this->assertTrue($flags[$call][2]['flagcurrent']);
        $this->assertFalse($flags[$call][0]['flagcurrent']);
        $this->assertSame([
            get_string('flag:phone', 'local_oksigeniafamily'),
            get_string('flag:student', 'local_oksigeniafamily', 'csv:S-1'),
            get_string('flag:image', 'local_oksigeniafamily', 'csv:S-1'),
        ], $texts($know));
        $this->assertSame([], $flags[$used]);

        // With the consents off, they have no tile.
        set_config('consents', '0', 'local_oksigeniafamily');
        $this->assertArrayNotHasKey('pending', families::need_counts($now));
    }
}
