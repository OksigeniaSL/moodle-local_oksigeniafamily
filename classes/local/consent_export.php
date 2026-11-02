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
 * The answers of a request for consent, to download: every answer ever given, in order, and a row for each student
 * nobody has answered for. It is the record the school keeps.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class consent_export {
    /**
     * The rows.
     *
     * @param \stdClass $request The request.
     * @return array[] Each with the values of self::columns(), in order.
     */
    public static function rows(\stdClass $request): array {
        global $DB;

        $component = 'local_oksigeniafamily';
        $state = consent::state($request);
        $users = $state ? $DB->get_records_list('user', 'id', array_keys($state)) : [];
        $answers = $DB->get_records('local_oksigeniafamily_answer', ['requestid' => $request->id], 'id ASC');
        $byuser = [];
        foreach ($answers as $answer) {
            $byuser[(int) $answer->childuserid][] = $answer;
        }

        $rows = [];
        foreach ($state as $userid => $one) {
            $user = $users[$userid] ?? null;
            if (!$user) {
                continue;
            }
            $student = fullname($user);
            $status = consent_view::student_status($one)['label'];
            if (empty($byuser[$userid])) {
                $rows[] = [$student, $status, '', '', '', '', ''];
                continue;
            }
            foreach ($byuser[$userid] as $answer) {
                $rows[] = [
                    $student,
                    $status,
                    $answer->adultname,
                    get_string($answer->answer === consent::YES ? 'consent:yes' : 'consent:no', $component),
                    userdate((int) $answer->timecreated, get_string('strftimedatetime', 'langconfig')),
                    consent_view::channel($answer->channel) . ($answer->address ? ' (' . $answer->address . ')' : ''),
                    (string) ($answer->comment ?? ''),
                ];
            }
        }
        usort($rows, fn($a, $b) => strcmp(\core_text::strtolower($a[0]), \core_text::strtolower($b[0])));

        return $rows;
    }

    /**
     * The names of the columns.
     *
     * @return string[]
     */
    public static function columns(): array {
        $component = 'local_oksigeniafamily';

        return [
            get_string('consent:col:student', $component),
            get_string('consent:col:status', $component),
            get_string('consent:col:adult', $component),
            get_string('consent:col:answer', $component),
            get_string('consent:col:when', $component),
            get_string('consent:col:channel', $component),
            get_string('consent:col:comment', $component),
        ];
    }

    /**
     * Send the file and stop.
     *
     * @param \stdClass $request The request.
     * @param string $format csv or pdf.
     * @return void
     */
    public static function download(\stdClass $request, string $format): void {
        $name = clean_filename(get_string('consent:download:name', 'local_oksigeniafamily', format_string($request->title)));
        \core\dataformat::download_data($name, $format, self::columns(), new \ArrayIterator(self::rows($request)));
        die();
    }
}
