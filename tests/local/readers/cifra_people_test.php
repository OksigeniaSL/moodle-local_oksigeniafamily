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

// Copied from local_oksigeniaayvurenda (tests/core/readers/cifra_people_test.php) by kit/lectores/lectores.py in hq.
// Do not edit it here: change it there and run the script again.

namespace local_oksigeniafamily\local\readers;

/**
 * Tests for the people of a Cifra list, as readers.
 *
 * @package    local_oksigeniafamily
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniafamily\local\readers\cifra_people
 */
#[\PHPUnit\Framework\Attributes\CoversClass(cifra_people::class)]
final class cifra_people_test extends \basic_testcase {
    /**
     * A made-up list: a repeated person counts once, who left is apart, a teacher has no group, two siblings with
     * the family's email are two readers.
     */
    public function test_readers(): void {
        $people = json_decode(file_get_contents(__DIR__ . '/../../fixtures/cifra/alumnos.json'), true);
        $found = cifra_people::readers($people, strtotime('2026-10-24 12:00:00 UTC'));
        $this->assertSame(['id:cifra:7003'], $found['gone']);
        $this->assertCount(4, $found['readers']);
        $this->assertSame('cifra:7005', $found['readers'][3]['externalid']);
        $this->assertEquals(['externalid' => 'cifra:7001', 'firstname' => 'Bruno', 'lastname' => 'Olmo Ríos',
            'email' => 'bruno.olmo@example.invalid', 'group' => 'ESO 1º A', 'stage' => stage_guesser::guess('ESO 1º A'),
            'type' => 'student'], $found['readers'][0]);
        $this->assertSame('Lago', $found['readers'][1]['lastname']);
        $this->assertSame('teacher', $found['readers'][2]['type']);
        $this->assertArrayNotHasKey('group', $found['readers'][2]);
        // Before the day they left, they were still there.
        $this->assertCount(5, cifra_people::readers($people, strtotime('2026-09-01 12:00:00 UTC'))['readers']);
    }

    /**
     * The families come from each student's record: the same mother of two brothers is one adult with both, a relative
     * without an id is known by their email, the legal guardian says so, and a student who left brings nobody.
     */
    public function test_families(): void {
        $now = strtotime('2026-10-31 12:00:00 UTC');
        $mother = ['parentesco' => 'Madre', 'tutor' => 'Sí', 'usuario' => ['id' => 900, 'nombre' => 'Marta',
            'apellidos' => 'Arenas Faro', 'email' => 'Marta@Example.invalid', 'telefono_movil' => '600 000 001']];
        $records = [
            ['id' => 10, 'familiares' => [$mother]],
            ['id' => 11, 'familiares' => [$mother, ['parentesco' => 'Abuelo', 'tutor' => 'No',
                'usuario' => ['nombre' => 'Ramón', 'apellido1' => 'Faro', 'apellido2' => 'Lago',
                    'email' => 'ramon@example.invalid']]]],
            ['id' => 12, 'fecha_baja' => '2026-06-30', 'familiares' => [['parentesco' => 'Padre', 'tutor' => true,
                'usuario' => ['id' => 901, 'nombre' => 'Gael']]]],
            ['id' => 13, 'familiares' => [['parentesco' => 'Tía', 'usuario' => ['nombre' => 'Sin forma de reconocerla']]]],
        ];
        $families = cifra_people::families($records, $now);
        $this->assertSame(['cifra:900', 'cifra:mail:ramon@example.invalid'], array_column($families, 'key'));
        $this->assertSame(['key' => 'cifra:900', 'firstname' => 'Marta', 'lastname' => 'Arenas Faro',
            'email' => 'marta@example.invalid', 'phone' => '600000001', 'relation' => 'Madre', 'guardian' => true,
            'children' => ['cifra:10', 'cifra:11']], $families[0]);
        $this->assertSame(['Faro Lago', false, ['cifra:11']], [$families[1]['lastname'], $families[1]['guardian'],
            $families[1]['children']]);
    }
}
