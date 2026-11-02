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

namespace local_oksigeniafamily\local\import;

/**
 * Tests for reading the file of families.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_oksigeniafamily\local\import\csv_file
 */
#[\PHPUnit\Framework\Attributes\CoversClass(csv_file::class)]
final class csv_file_test extends \basic_testcase {
    /**
     * A plain file: columns by name, empty cells left out, and the real line of each row.
     */
    public function test_read(): void {
        $read = csv_file::read(implode("\r\n", [
            'family,type,firstname,phone,username',
            'F1,adult,Marta,+14155550101,',
            '',
            'F1,child,,,ana.ruiz',
        ]));

        $this->assertSame('', $read['error']);
        $this->assertCount(2, $read['rows']);
        $this->assertSame(2, $read['rows'][0]['line']);
        $this->assertSame(
            ['family' => 'F1', 'type' => 'adult', 'firstname' => 'Marta', 'phone' => '+14155550101'],
            $read['rows'][0]['values']
        );
        $this->assertSame(4, $read['rows'][1]['line']);
        $this->assertSame('ana.ruiz', $read['rows'][1]['values']['username']);
    }

    /**
     * What spreadsheets do: another separator, a mark at the start, Latin 1, UTF-16, headings in Spanish, quotes.
     */
    public function test_what_spreadsheets_do(): void {
        $read = csv_file::read(
            "\xEF\xBB\xBFFamilia;Tipo;Nombre;Apellidos;Correo electrónico;Teléfono\n"
            . "F1;adult;\"Gil; Marta\";Ruiz;m@example.com;600\n"
        );
        $this->assertSame('', $read['error']);
        $this->assertSame('Gil; Marta', $read['rows'][0]['values']['firstname']);
        $this->assertSame('m@example.com', $read['rows'][0]['values']['email']);
        $this->assertSame('600', $read['rows'][0]['values']['phone']);

        $latin = mb_convert_encoding("family,type,firstname\nF1,adult,Lucía\n", 'Windows-1252', 'UTF-8');
        $this->assertSame('Lucía', csv_file::read($latin)['rows'][0]['values']['firstname']);

        $utf16 = "\xFF\xFE" . mb_convert_encoding("family\ttype\tfirstname\nF1\tadult\tÍñigo\n", 'UTF-16LE', 'UTF-8');
        $this->assertSame('Íñigo', csv_file::read($utf16)['rows'][0]['values']['firstname']);

        // A cell over several lines does not throw off the line numbers.
        $read = csv_file::read("family,type,firstname\nF1,adult,\"Marta\nGil\"\nF1,adult,Juan\n");
        $this->assertSame(2, $read['rows'][0]['line']);
        $this->assertSame(4, $read['rows'][1]['line']);
    }

    /**
     * What is wrong with a whole file, and with a row.
     */
    public function test_problems(): void {
        $this->assertSame('empty', csv_file::read("\n  \n")['error']);
        $this->assertSame('norows', csv_file::read("family,type\n")['error']);

        $read = csv_file::read("name,mobile\nMarta,600\n");
        $this->assertSame('missing', $read['error']);
        $this->assertSame(['family', 'type'], $read['missing']);

        $read = csv_file::read("family,type,shoe size,type\nF1,adult,42,child,extra\n");
        $this->assertSame(['shoe size', 'type'], $read['unknown']);
        $this->assertSame('adult', $read['rows'][0]['values']['type']);
        $this->assertTrue($read['rows'][0]['toomany']);
    }

    /**
     * The file to start from is one the import takes.
     */
    public function test_sample(): void {
        $read = csv_file::read(csv_file::sample());
        $this->assertSame('', $read['error']);
        $this->assertSame([], $read['unknown']);
        $this->assertCount(6, $read['rows']);
    }
}
