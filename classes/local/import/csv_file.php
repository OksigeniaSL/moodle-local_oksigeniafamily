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
 * The families of a spreadsheet saved as CSV: one row per person, the first row says what each column is. The separator
 * and the encoding are found on their own, and the headings are understood in English or in Spanish, because a school
 * makes this file by hand or from whatever program it uses.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class csv_file {
    /** The columns without which a row means nothing. */
    public const REQUIRED = ['family', 'type'];

    /** Words of a heading for each column, without accents, case, spaces or signs. */
    private const WORDS = [
        'family' => ['family', 'familia', 'household', 'hogar', 'familyid', 'familykey', 'codigofamilia'],
        'type' => ['type', 'tipo', 'role', 'rol'],
        'firstname' => ['firstname', 'givenname', 'name', 'nombre'],
        'lastname' => ['lastname', 'surname', 'familyname', 'apellidos', 'apellido'],
        'email' => ['email', 'mail', 'correo', 'correoelectronico'],
        'phone' => ['phone', 'mobile', 'telephone', 'telefono', 'movil'],
        'username' => ['username', 'user', 'usuario', 'nombredeusuario'],
        'idnumber' => ['idnumber', 'studentid', 'numerodeid', 'expediente', 'matricula'],
        'key' => ['key', 'sourcekey', 'sourcedid', 'externalid', 'clave'],
    ];

    /**
     * The rows of a CSV text.
     *
     * @param string $text The file, as uploaded.
     * @return array error ('' or the name of what is wrong with the whole file), missing (required columns not there),
     *     unknown (headings not understood, as written) and rows (each with line, its number in the file, values by
     *     column, without empty ones, and toomany, whether it has more cells than headings).
     */
    public static function read(string $text): array {
        $result = ['error' => '', 'missing' => [], 'unknown' => [], 'rows' => []];
        $text = str_replace(["\r\n", "\r"], "\n", self::to_utf8($text));
        if (trim($text) === '') {
            $result['error'] = 'empty';
            return $result;
        }

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $text);
        rewind($stream);
        $separator = self::separator($text);
        $columns = null;
        $line = 1;
        $position = 0;
        while (($cells = fgetcsv($stream, 0, $separator, '"', '')) !== false) {
            $start = $line;
            $end = ftell($stream);
            $line += substr_count(substr($text, $position, $end - $position), "\n");
            $position = $end;
            $cells = array_map(fn($cell) => trim((string) $cell), $cells);
            if (implode('', $cells) === '') {
                continue;
            }
            if ($columns === null) {
                $columns = self::columns($cells, $result['unknown']);
                continue;
            }
            $values = [];
            foreach ($columns as $index => $column) {
                if ($column !== '' && ($cells[$index] ?? '') !== '') {
                    $values[$column] = $cells[$index];
                }
            }
            $extra = array_slice($cells, count($columns));
            $result['rows'][] = ['line' => $start, 'values' => $values, 'toomany' => implode('', $extra) !== ''];
        }
        fclose($stream);

        $result['missing'] = array_values(array_diff(self::REQUIRED, $columns ?? []));
        if ($result['missing']) {
            $result['error'] = 'missing';
        } else if (!$result['rows']) {
            $result['error'] = 'norows';
        }

        return $result;
    }

    /**
     * A file to start from, with example data.
     *
     * @return string
     */
    public static function sample(): string {
        return implode("\n", [
            'family,type,firstname,lastname,email,phone,username,idnumber,key',
            'F-1001,adult,Marta,Gil,marta.gil@example.com,+14155550101,,,',
            'F-1001,adult,Juan,Ruiz,,+14155550102,,,',
            'F-1001,child,,,,,ana.ruiz,,',
            'F-1001,child,,,,,,20417,',
            'F-1002,adult,Carlos,Vega,carlos.vega@example.com,,,,',
            'F-1002,child,,,,,,,S-3082',
        ]) . "\n";
    }

    /**
     * The text in UTF-8, without its byte order mark: spreadsheets on Windows save in Latin 1 or, as Unicode, in UTF-16.
     *
     * @param string $text The file, as uploaded.
     * @return string
     */
    private static function to_utf8(string $text): string {
        if (strpos($text, "\xFF\xFE") === 0) {
            return mb_convert_encoding(substr($text, 2), 'UTF-8', 'UTF-16LE');
        }
        if (strpos($text, "\xFE\xFF") === 0) {
            return mb_convert_encoding(substr($text, 2), 'UTF-8', 'UTF-16BE');
        }
        if (strpos($text, "\xEF\xBB\xBF") === 0) {
            $text = substr($text, 3);
        }

        return mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    }

    /**
     * The separator: the sign that shows up most in the first row.
     *
     * @param string $text The text.
     * @return string
     */
    private static function separator(string $text): string {
        $first = strtok(ltrim($text), "\n");
        $best = ',';
        $most = 0;
        foreach ([',', ';', "\t", '|'] as $candidate) {
            $count = substr_count($first, $candidate);
            if ($count > $most) {
                $most = $count;
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * What each heading is.
     *
     * @param string[] $headings The first row.
     * @param string[] $unknown Filled with the headings not understood.
     * @return string[] The name of each column, or '' for those not understood or repeated.
     */
    private static function columns(array $headings, array &$unknown): array {
        $columns = [];
        foreach ($headings as $heading) {
            $plain = preg_replace('/[^a-z0-9]/', '', \core_text::strtolower(\core_text::specialtoascii($heading)));
            $column = '';
            foreach (self::WORDS as $name => $words) {
                if (in_array($plain, $words, true)) {
                    $column = $name;
                    break;
                }
            }
            if ($column === '' || in_array($column, $columns, true)) {
                if ($heading !== '') {
                    $unknown[] = $heading;
                }
                $columns[] = '';
            } else {
                $columns[] = $column;
            }
        }

        return $columns;
    }
}
