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

/**
 * Tests for the pieces of the claim page.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_oksigeniafamily\local\links
 * @covers \local_oksigeniafamily\local\phone
 * @covers \local_oksigeniafamily\local\throttle
 */
#[\PHPUnit\Framework\Attributes\CoversClass(links::class)]
final class claim_test extends \advanced_testcase {
    /**
     * Phones in one form: spaces and signs out, 00 as +, the site's country code when there is none.
     */
    public function test_phone(): void {
        $this->assertSame('+34600123456', phone::normalise('600 12 34 56'));
        $this->assertSame('+34600123456', phone::normalise('+34 600-123-456'));
        $this->assertSame('+351912345678', phone::normalise('00351 912 345 678'));
        $this->assertSame('+595981123456', phone::normalise('981 123 456', '+595'));
        $this->assertSame('', phone::normalise('12'));
        $this->assertSame('', phone::normalise('no'));
    }

    /**
     * What plugins return is checked: only items with a label and a web address.
     */
    public function test_links(): void {
        $this->assertSame([], links::clean(null));
        $clean = links::clean([
            ['label' => 'Comedor', 'url' => 'https://example.com/local/oksigeniameals/k.php?t=abc', 'pin' => '4826'],
            ['label' => 'Sin dirección'],
            ['url' => 'https://example.com/sin-nombre'],
        ]);
        $this->assertCount(1, $clean);
        $this->assertSame('4826', $clean[0]['pin']);
        $this->assertSame('', $clean[0]['note']);

        $this->resetAfterTest();
        $adult = (object) ['id' => 1, 'familyid' => 1, 'userid' => null];
        $this->assertIsArray(links::for_child($adult, 2));
    }

    /**
     * Ten wrong codes from one address and it waits; another address is not held up.
     */
    public function test_throttle(): void {
        $this->resetAfterTest();

        for ($i = 0; $i < throttle::LIMIT; $i++) {
            $this->assertFalse(throttle::blocked('192.0.2.1'));
            throttle::failed('192.0.2.1');
        }
        $this->assertTrue(throttle::blocked('192.0.2.1'));
        $this->assertFalse(throttle::blocked('192.0.2.2'));
    }
}
