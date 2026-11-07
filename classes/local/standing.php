<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_oksigeniafamily\local;

/**
 * Standing consents: what a family says once about a student and holds until someone changes it, such as the use of
 * the student's image. Unlike a request for consent, it has no deadline, and it is held for every student, with an
 * account or without one, under the same keys as their photo (user:45, cifra:123).
 *
 * Who decides: the last answer of each adult of the family, plus the school's (what it loaded or wrote down). Once an
 * adult answers after the school's, the school's no longer counts. Any no among those is a no. For the image, a student
 * is shown unless there is a no: with no answer at all, they are shown.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class standing {
    /** @var string The use of the student's image (their photo on a screen, in the dining room, in the library). */
    const IMAGE = 'image';

    /** @var string The answer yes. */
    const YES = 'yes';

    /** @var string The answer no. */
    const NO = 'no';

    /** @var string Who answered when it was the school (an import, or the office writing down a paper). */
    const SCHOOL = 'school';

    /**
     * Write down an answer.
     *
     * @param string $key The student's key (user:45, cifra:123).
     * @param string $topic What it is about (IMAGE).
     * @param bool $yes The answer.
     * @param int $adultid The adult of the family who answered, or 0 for the school.
     * @param string $channel How: school, web or app.
     * @param int|null $userid The account that wrote it down.
     * @return void
     */
    public static function record(
        string $key,
        string $topic,
        bool $yes,
        int $adultid = 0,
        string $channel = self::SCHOOL,
        ?int $userid = null
    ): void {
        global $DB;

        $now = time();
        $DB->insert_record('local_oksigeniafamily_standing', (object) [
            'childkey' => trim($key), 'topic' => $topic, 'answer' => $yes ? self::YES : self::NO,
            'adultid' => $adultid, 'channel' => $channel, 'userid' => $userid, 'timecreated' => $now, 'timeseen' => $now,
        ]);
    }

    /**
     * What counts for a student, after the rule above: yes, no, or '' when nobody has answered.
     *
     * @param string[] $keys Every key of the student (their account and their key in the school's data).
     * @param string $topic What it is about.
     * @return string
     */
    public static function state(array $keys, string $topic): string {
        global $DB;

        $keys = array_values(array_unique(array_filter(array_map('trim', $keys))));
        if (!$keys) {
            return '';
        }
        [$in, $params] = $DB->get_in_or_equal($keys, SQL_PARAMS_NAMED);
        $params['topic'] = $topic;
        $rows = $DB->get_records_select(
            'local_oksigeniafamily_standing',
            "topic = :topic AND childkey $in",
            $params,
            'id ASC',
            'id, adultid, answer'
        );
        $latest = [];
        foreach ($rows as $row) {
            $latest[(int) $row->adultid] = $row;
        }
        $school = $latest[0] ?? null;
        unset($latest[0]);
        if ($school && !array_filter($latest, fn($row) => (int) $row->id > (int) $school->id)) {
            $latest[0] = $school;
        }
        if (!$latest) {
            return '';
        }
        foreach ($latest as $row) {
            if ($row->answer === self::NO) {
                return self::NO;
            }
        }

        return self::YES;
    }

    /**
     * Whether a student's image may be shown: unless a no counts (with no answer, it may).
     *
     * @param string[] $keys Every key of the student.
     * @return bool
     */
    public static function may_show(array $keys): bool {
        return self::state($keys, self::IMAGE) !== self::NO;
    }

    /**
     * Every key a student may be held under, from their account.
     *
     * @param int $userid The student's account.
     * @return string[]
     */
    public static function keys_for_user(int $userid): array {
        global $DB;

        $keys = [photos::user_key($userid)];
        $sourcekeys = $DB->get_fieldset_select(
            'local_oksigeniafamily_child',
            'sourcekey',
            'userid = :userid AND sourcekey IS NOT NULL',
            ['userid' => $userid]
        );

        return array_values(array_unique(array_merge($keys, array_filter(array_map('strval', $sourcekeys)))));
    }

    /**
     * Every key a student may be held under, from their key in the school's data.
     *
     * @param string $sourcekey The key, such as cifra:123.
     * @return string[]
     */
    public static function keys_for_key(string $sourcekey): array {
        global $DB;

        $keys = [trim($sourcekey)];
        $userids = $DB->get_fieldset_select(
            'local_oksigeniafamily_child',
            'userid',
            'sourcekey = :key AND userid IS NOT NULL AND userid > 0',
            ['key' => trim($sourcekey)]
        );
        foreach (array_unique(array_map('intval', $userids)) as $userid) {
            $keys[] = photos::user_key($userid);
        }

        return array_values(array_unique($keys));
    }

    /**
     * Every key a child of Family may be held under.
     *
     * @param int $childid The child.
     * @return string[]
     */
    public static function keys_for_child(int $childid): array {
        global $DB;

        $child = $DB->get_record('local_oksigeniafamily_child', ['id' => $childid], 'id, userid, sourcekey');
        if (!$child) {
            return [];
        }
        $keys = $child->userid ? self::keys_for_user((int) $child->userid) : [];
        if ((string) $child->sourcekey !== '') {
            $keys = array_merge($keys, self::keys_for_key((string) $child->sourcekey));
        }

        return array_values(array_unique($keys));
    }

    /**
     * An answer as people write it in a spreadsheet: yes, sí, s, x, 1, autoriza…; no, n, 0, no autoriza…
     *
     * @param string $value The cell.
     * @return bool|null Null when it says neither.
     */
    public static function parse(string $value): ?bool {
        $value = \core_text::strtolower(trim($value));
        $value = strtr($value, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ã' => 'a', 'õ' => 'o',
            'â' => 'a', 'ê' => 'e', 'ô' => 'o', 'ç' => 'c']);
        $value = trim(preg_replace('/[\s.!]+/', ' ', $value));
        if ($value === '') {
            return null;
        }
        $no = ['no', 'n', '0', 'false', 'falso', 'nao', 'non', 'nein', 'deniega', 'denegada', 'denegado', 'rechaza',
            'rechazada', 'rechazado'];
        $yes = ['si', 's', 'yes', 'y', '1', 'true', 'verdadero', 'x', 'sim', 'oui', 'ja', 'ok', 'autoriza', 'autorizada',
            'autorizado', 'acepta', 'aceptada', 'aceptado', 'consiente', 'allow', 'allowed', 'agree', 'agreed'];
        if (in_array($value, $no, true) || str_starts_with($value, 'no ')) {
            return false;
        }
        if (in_array($value, $yes, true) || str_starts_with($value, 'si ')) {
            return true;
        }

        return null;
    }

    /**
     * Read a spreadsheet saved as CSV: the first row is the titles. Commas, semicolons or tabs, in UTF-8 or in the old
     * Windows encoding.
     *
     * @param string $text The file.
     * @return array The titles and the rows (each a list of cells).
     */
    public static function read_csv(string $text): array {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = \core_text::convert($text, 'windows-1252', 'utf-8');
        }
        $first = strtok($text, "\n") ?: '';
        $counts = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
        arsort($counts);
        $delimiter = (string) array_key_first($counts);
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $text);
        rewind($stream);
        $rows = [];
        while (($cells = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            if ($cells === [null] || implode('', array_map('trim', array_map('strval', $cells))) === '') {
                continue;
            }
            $rows[] = array_map(fn($cell) => trim((string) $cell), $cells);
        }
        fclose($stream);
        $headers = $rows ? array_shift($rows) : [];

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * Which columns are most likely the student's number and the answer, from their titles.
     *
     * @param string[] $headers The titles.
     * @return array The two column numbers, or -1.
     */
    public static function guess_columns(array $headers): array {
        $id = -1;
        $answer = -1;
        foreach ($headers as $index => $header) {
            $title = \core_text::strtolower($header);
            if ($answer < 0 && preg_match('/imagen|image|foto|photo|autoriz|consent/u', $title)) {
                $answer = $index;
            } else if ($id < 0 && preg_match('/expediente|idnumber|n\.?º|numero|número|matr[ií]cula|\bid\b|nia/u', $title)) {
                $id = $index;
            }
        }

        return ['id' => $id, 'answer' => $answer];
    }

    /**
     * Load the school's answers from a spreadsheet: one row per student, with their number and the answer.
     *
     * A row whose answer is the same as the one the school already has is left alone, so loading the same file twice
     * changes nothing.
     *
     * @param array $rows The rows (lists of cells).
     * @param int $idcolumn The column with the student's number.
     * @param int $answercolumn The column with the answer.
     * @param string $kind What the number is: idnumber, or source: and the source (as for photos).
     * @param string $topic What it is about.
     * @param bool $save False to only count.
     * @return array Counts yes, no, same, unmatched and unknown, and the numbers that matched no student.
     */
    public static function import_rows(
        array $rows,
        int $idcolumn,
        int $answercolumn,
        string $kind,
        string $topic = self::IMAGE,
        bool $save = true
    ): array {
        global $DB, $USER;

        $result = ['yes' => 0, 'no' => 0, 'same' => 0, 'unmatched' => 0, 'unknown' => 0, 'names' => []];
        foreach ($rows as $cells) {
            $number = trim((string) ($cells[$idcolumn] ?? ''));
            $yes = self::parse((string) ($cells[$answercolumn] ?? ''));
            if ($number === '') {
                continue;
            }
            if ($yes === null) {
                $result['unknown']++;
                continue;
            }
            $key = photos::key_for_number($number, $kind);
            if ($key === null) {
                $result['unmatched']++;
                $result['names'][] = $number;
                continue;
            }
            $last = $DB->get_records(
                'local_oksigeniafamily_standing',
                ['childkey' => $key, 'topic' => $topic, 'adultid' => 0],
                'id DESC',
                'id, answer',
                0,
                1
            );
            $last = reset($last);
            if ($last && $last->answer === ($yes ? self::YES : self::NO)) {
                $result['same']++;
                continue;
            }
            if ($save) {
                self::record($key, $topic, $yes, 0, self::SCHOOL, (int) $USER->id ?: null);
            }
            $result[$yes ? 'yes' : 'no']++;
        }

        return $result;
    }

    /**
     * How many students have each state, for a summary.
     *
     * @param string $topic What it is about.
     * @return array Counts yes and no.
     */
    public static function summary(string $topic = self::IMAGE): array {
        global $DB;

        $counts = ['yes' => 0, 'no' => 0];
        $keys = $DB->get_fieldset_select(
            'local_oksigeniafamily_standing',
            'DISTINCT childkey',
            'topic = :topic',
            ['topic' => $topic]
        );
        foreach ($keys as $key) {
            $state = self::state([(string) $key], $topic);
            if ($state !== '') {
                $counts[$state]++;
            }
        }

        return $counts;
    }

    /**
     * The daily round: the answers about a student who is still here are marked as seen; those about a student not seen
     * for the grace period are erased, like their photo.
     *
     * @param int $now The time to work from.
     * @param int $gracedays The grace period, in days.
     * @return int How many students' answers were erased.
     */
    public static function purge(int $now, int $gracedays): int {
        global $DB;

        $erased = 0;
        $limit = $now - $gracedays * DAYSECS;
        $keys = $DB->get_records_sql(
            'SELECT childkey, MAX(timeseen) AS seen FROM {local_oksigeniafamily_standing} GROUP BY childkey'
        );
        foreach ($keys as $row) {
            if (photos::is_current($row->childkey)) {
                $DB->set_field('local_oksigeniafamily_standing', 'timeseen', $now, ['childkey' => $row->childkey]);
            } else if ((int) $row->seen <= $limit) {
                $DB->delete_records('local_oksigeniafamily_standing', ['childkey' => $row->childkey]);
                $erased++;
            }
        }

        return $erased;
    }
}
