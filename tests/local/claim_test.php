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
     * Phones in one form: spaces and signs out, 00 as +, the site's country code when there is none, and no guess when the
     * site has none set.
     */
    public function test_phone(): void {
        $this->assertSame('+34600123456', phone::normalise('600 12 34 56', '+34'));
        $this->assertSame('', phone::normalise('600 12 34 56'));
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

    /**
     * The other brakes count apart, each up to its limit: a code however it is typed, an account, a visitor's address (a
     * whole IPv6 line as one).
     */
    public function test_limits(): void {
        $this->resetAfterTest();

        for ($i = 0; $i < throttle::LIMITS['code'][0]; $i++) {
            $this->assertFalse(throttle::over('code', 'abcd-efgh'));
            throttle::count('code', $i % 2 ? 'ABCDEFGH' : 'abcd efgh');
        }
        $this->assertTrue(throttle::over('code', 'ABCD-EFGH'));
        $this->assertFalse(throttle::over('code', 'ABCD-EFGJ'));
        $this->assertFalse(throttle::over('mailto', 'ABCDEFGH'));

        for ($i = 0; $i < throttle::LIMITS['mailfrom'][0]; $i++) {
            throttle::count('mailfrom', '2001:db8:1:2::' . dechex($i + 1));
        }
        $this->assertTrue(throttle::over('mailfrom', '2001:db8:1:2::ffff'));
        $this->assertFalse(throttle::over('mailfrom', '2001:db8:1:3::1'));
        // A wrong code is a brake of its own.
        $this->assertFalse(throttle::blocked('2001:db8:1:2::1'));

        $this->expectException(\coding_exception::class);
        throttle::over('nonsense', 'x');
    }
}
