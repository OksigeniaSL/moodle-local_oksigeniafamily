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
 * Tests for how names are compared.
 *
 * @package    local_oksigeniafamily
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniafamily\local\names
 */
#[\PHPUnit\Framework\Attributes\CoversClass(names::class)]
final class names_test extends \advanced_testcase {
    /**
     * A name is the same however it is typed; a first name is one person's when one starts the other, and never when
     * either is empty.
     */
    public function test_names(): void {
        $this->assertSame('jose maria pena', names::fold('  José   María  PEÑA '));
        $this->assertSame('', names::fold(' - '));

        $this->assertTrue(names::same_first('Darío', 'DARIO'));
        $this->assertTrue(names::same_first('María', 'María José'));
        $this->assertTrue(names::same_first('maria jose', 'MARÍA'));
        $this->assertFalse(names::same_first('Marta', 'Pedro'));
        $this->assertFalse(names::same_first('José', 'María José'));
        $this->assertFalse(names::same_first('', 'Ana'));
        $this->assertFalse(names::same_first('Ana', ' '));
    }
}
