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

use local_oksigeniafamily\api;

/**
 * One photo per student, kept in one place for every plugin of the site that shows a child (screens, the dining room,
 * the library), also for children with no account, such as the youngest ones.
 *
 * A photo is held under a key: the student's key in the school's data (cifra:123) or their account (user:45). A student
 * with an account shows the picture of their account when it has one, and the uploaded photo otherwise. Photos are
 * stored small (512 px), as JPEG and without their EXIF data (place, date and camera), and a photo whose student is no
 * longer here is erased after the grace period.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class photos {
    /** @var string The file area. */
    const AREA = 'photo';

    /** @var string The name of every stored photo. */
    const FILENAME = 'photo.jpg';

    /** @var int The longest side of a stored photo, in pixels. */
    const SIZE = 512;

    /** @var string[] The images a photo can come as. */
    const EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /**
     * Whether the site keeps students' photos (a switch in the settings, on by default).
     *
     * @return bool
     */
    public static function enabled(): bool {
        return get_config('local_oksigeniafamily', 'photos') !== '0';
    }

    /**
     * The key of the photo uploaded for an account.
     *
     * @param int $userid The student's account.
     * @return string
     */
    public static function user_key(int $userid): string {
        return 'user:' . $userid;
    }

    /**
     * Store a photo under a key, replacing the one before.
     *
     * @param string $key The student's key in the school's data, or a user key.
     * @param string $contents The image as it came.
     * @return bool False if the key is empty or the contents are not an image.
     */
    public static function save(string $key, string $contents): bool {
        global $DB;

        $key = trim($key);
        $jpeg = self::normalise($contents);
        if ($key === '' || $jpeg === null) {
            return false;
        }
        $now = time();
        $record = $DB->get_record('local_oksigeniafamily_photo', ['photokey' => $key]);
        if ($record) {
            $DB->update_record('local_oksigeniafamily_photo', (object) [
                'id' => $record->id, 'timemodified' => $now, 'timeseen' => $now,
            ]);
            $id = (int) $record->id;
        } else {
            $id = (int) $DB->insert_record('local_oksigeniafamily_photo', (object) [
                'photokey' => $key, 'timecreated' => $now, 'timemodified' => $now, 'timeseen' => $now,
            ]);
        }
        $fs = get_file_storage();
        $context = \context_system::instance();
        $fs->delete_area_files($context->id, 'local_oksigeniafamily', self::AREA, $id);
        $fs->create_file_from_string([
            'contextid' => $context->id, 'component' => 'local_oksigeniafamily', 'filearea' => self::AREA,
            'itemid' => $id, 'filepath' => '/', 'filename' => self::FILENAME,
        ], $jpeg);

        return true;
    }

    /**
     * The photo stored under a key, as it was uploaded (never the picture of an account).
     *
     * @param string $key The key.
     * @return \stored_file|null
     */
    public static function stored(string $key): ?\stored_file {
        global $DB;

        $id = $DB->get_field('local_oksigeniafamily_photo', 'id', ['photokey' => trim($key)]);
        if (!$id) {
            return null;
        }
        $file = get_file_storage()->get_file(
            \context_system::instance()->id,
            'local_oksigeniafamily',
            self::AREA,
            (int) $id,
            '/',
            self::FILENAME
        );

        return $file ?: null;
    }

    /**
     * A student's photo by their account, if their image may be shown: the picture of the account, or else the one
     * uploaded for it.
     *
     * @param int $userid The student's account.
     * @return \stored_file|null
     */
    public static function for_user(int $userid): ?\stored_file {
        if (!self::enabled() || !standing::may_show(standing::keys_for_user($userid))) {
            return null;
        }

        return self::account_or_stored($userid);
    }

    /**
     * The picture of an account, or else the photo uploaded for the student, without asking whether it may be shown.
     *
     * @param int $userid The student's account.
     * @return \stored_file|null
     */
    private static function account_or_stored(int $userid): ?\stored_file {
        global $DB;

        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], 'id, picture');
        if (!$user) {
            return null;
        }
        if ($user->picture) {
            $files = get_file_storage()->get_area_files(
                \context_user::instance($userid)->id,
                'user',
                'icon',
                0,
                'filename',
                false
            );
            $byname = [];
            foreach ($files as $file) {
                $byname[pathinfo($file->get_filename(), PATHINFO_FILENAME)] = $file;
            }
            // The largest copy Moodle keeps of a user's picture is f3, then f1.
            if (isset($byname['f3']) || isset($byname['f1'])) {
                return $byname['f3'] ?? $byname['f1'];
            }
        }

        // The photo uploaded for the account, or under any key the student has in the school's data.
        foreach (standing::keys_for_user($userid) as $key) {
            $file = self::stored($key);
            if ($file) {
                return $file;
            }
        }

        return null;
    }

    /**
     * A student's photo by their key in the school's data, if their image may be shown: through their account if a
     * child with that key has one.
     *
     * @param string $sourcekey The key, such as cifra:123.
     * @return \stored_file|null
     */
    public static function for_key(string $sourcekey): ?\stored_file {
        global $DB;

        if (!self::enabled() || trim($sourcekey) === '' || !standing::may_show(standing::keys_for_key($sourcekey))) {
            return null;
        }
        $userids = $DB->get_fieldset_select(
            'local_oksigeniafamily_child',
            'userid',
            'sourcekey = :key AND userid IS NOT NULL AND userid > 0',
            ['key' => trim($sourcekey)]
        );
        foreach (array_unique(array_map('intval', $userids)) as $userid) {
            $file = self::account_or_stored($userid);
            if ($file) {
                return $file;
            }
        }

        return self::stored($sourcekey);
    }

    /**
     * A child's photo, if their image may be shown: by their account, then by their key in the school's data.
     *
     * @param int $childid The child in Family.
     * @return \stored_file|null
     */
    public static function for_child(int $childid): ?\stored_file {
        global $DB;

        if (!self::enabled() || !standing::may_show(standing::keys_for_child($childid))) {
            return null;
        }
        $child = $DB->get_record('local_oksigeniafamily_child', ['id' => $childid], 'id, userid, sourcekey');
        if (!$child) {
            return null;
        }
        $file = $child->userid ? self::account_or_stored((int) $child->userid) : null;
        if (!$file && (string) $child->sourcekey !== '') {
            $file = self::stored((string) $child->sourcekey);
        }

        return $file;
    }

    /**
     * Erase the photo stored under a key.
     *
     * @param string $key The key.
     * @return void
     */
    public static function delete(string $key): void {
        global $DB;

        $record = $DB->get_record('local_oksigeniafamily_photo', ['photokey' => trim($key)]);
        if (!$record) {
            return;
        }
        get_file_storage()->delete_area_files(
            \context_system::instance()->id,
            'local_oksigeniafamily',
            self::AREA,
            (int) $record->id
        );
        $DB->delete_records('local_oksigeniafamily_photo', ['id' => $record->id]);
    }

    /**
     * Whether the student behind a key is still here: an account that is not deleted, or an active child in a family,
     * or a child whose account is not deleted. A suspended account is still here: the student may come back after the
     * summer, and must find the family's «no» to the use of their image as it was.
     *
     * @param string $key The key.
     * @return bool
     */
    public static function is_current(string $key): bool {
        global $DB;

        if (preg_match('/^user:(\d+)$/', $key, $match)) {
            return $DB->record_exists('user', ['id' => (int) $match[1], 'deleted' => 0]);
        }

        return $DB->record_exists_sql(
            "SELECT 1
               FROM {local_oksigeniafamily_child} c
          LEFT JOIN {user} u ON u.id = c.userid AND u.deleted = 0
              WHERE c.sourcekey = :sourcekey AND (c.status = :active OR u.id IS NOT NULL)",
            ['sourcekey' => $key, 'active' => api::ACTIVE]
        );
    }

    /**
     * The daily round: a photo whose student is here is marked as seen; one not seen for the grace period is erased, so
     * that a student who leaves takes their photo with them, and a late import does not lose one that is still wanted.
     *
     * @param int $now The time to work from.
     * @param int $gracedays The grace period, in days.
     * @return int How many photos were erased.
     */
    public static function purge(int $now, int $gracedays): int {
        global $DB;

        $erased = 0;
        $limit = $now - $gracedays * DAYSECS;
        $records = $DB->get_recordset('local_oksigeniafamily_photo', null, 'id', 'id, photokey, timeseen');
        foreach ($records as $record) {
            if (self::is_current($record->photokey)) {
                $DB->set_field('local_oksigeniafamily_photo', 'timeseen', $now, ['id' => $record->id]);
            } else if ((int) $record->timeseen <= $limit) {
                self::delete($record->photokey);
                $erased++;
            }
        }
        $records->close();

        return $erased;
    }

    /**
     * The kinds of number a batch of photos can be named after: the ID number of the account, and the keys of each
     * source the site's children come from (cifra, oneroster…).
     *
     * @return string[] Kind => source name ('' for the ID number).
     */
    public static function kinds(): array {
        global $DB;

        $kinds = ['idnumber' => ''];
        $keys = $DB->get_fieldset_select('local_oksigeniafamily_child', 'DISTINCT sourcekey', 'sourcekey IS NOT NULL');
        foreach ($keys as $key) {
            if (preg_match('/^([a-z0-9_-]+):/i', (string) $key, $match)) {
                $kinds['source:' . strtolower($match[1])] = strtolower($match[1]);
            }
        }
        ksort($kinds);

        return $kinds;
    }

    /**
     * The key of the student a number stands for: the account with that ID number (and only one), or the children of
     * Family with that key in a source.
     *
     * @param string $number The number, as written in a file name or a spreadsheet.
     * @param string $kind 'idnumber', or 'source:' and the source (source:cifra).
     * @return string|null The key, or null if it matches no student.
     */
    public static function key_for_number(string $number, string $kind): ?string {
        global $DB;

        $number = trim($number);
        if ($number === '') {
            return null;
        }
        if ($kind === 'idnumber') {
            $users = $DB->get_records('user', ['idnumber' => $number, 'deleted' => 0], '', 'id', 0, 2);
            return count($users) === 1 ? self::user_key((int) reset($users)->id) : null;
        }
        if (!str_starts_with($kind, 'source:') || strlen($kind) === strlen('source:')) {
            return null;
        }
        $key = substr($kind, strlen('source:')) . ':' . $number;

        return $DB->record_exists('local_oksigeniafamily_child', ['sourcekey' => $key]) ? $key : null;
    }

    /**
     * Store a folder of photos, each named after a student's number (1234.jpg), with what the number is.
     *
     * The ID number goes to the account with it (and only one); a source key goes to the children with it in Family.
     * Nothing is stored for a name that matches no student.
     *
     * @param string $dir The folder, searched with its subfolders.
     * @param string $kind 'idnumber', or 'source:' and the source (source:cifra).
     * @param bool $save False to only see what would happen.
     * @return array Counts saved, unmatched and notimage, and the names that matched no student.
     */
    public static function import_dir(string $dir, string $kind, bool $save = true): array {
        $result = ['saved' => 0, 'unmatched' => 0, 'notimage' => 0, 'names' => []];
        if ($kind !== 'idnumber' && !str_starts_with($kind, 'source:')) {
            return $result;
        }
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = $file->getPathname();
            $name = $file->getFilename();
            if (!$file->isFile() || str_starts_with($name, '.') || str_contains($path, '__MACOSX')) {
                continue;
            }
            if (!in_array(strtolower($file->getExtension()), self::EXTENSIONS, true)) {
                $result['notimage']++;
                continue;
            }
            $number = trim(pathinfo($name, PATHINFO_FILENAME));
            $key = self::key_for_number($number, $kind);
            if ($key === null) {
                $result['unmatched']++;
                $result['names'][] = $number;
                continue;
            }
            if (!$save) {
                $result['saved']++;
            } else if (self::save($key, (string) file_get_contents($path))) {
                $result['saved']++;
            } else {
                $result['notimage']++;
            }
        }

        return $result;
    }

    /**
     * Store a zip of photos (see import_dir).
     *
     * @param string $zippath The zip file.
     * @param string $kind What the number in the names is.
     * @return array|null What import_dir says, or null if the file is not a zip.
     */
    public static function import_zip(string $zippath, string $kind): ?array {
        $dir = make_request_directory();
        $extracted = get_file_packer('application/zip')->extract_to_pathname($zippath, $dir);
        if (!$extracted) {
            return null;
        }

        return self::import_dir($dir, $kind);
    }

    /**
     * How many photos the site keeps.
     *
     * @return int
     */
    public static function count(): int {
        global $DB;

        return $DB->count_records('local_oksigeniafamily_photo');
    }

    /**
     * An image as it is stored: turned upright from its EXIF orientation, no longer than SIZE on its longest side, on a
     * white background if it had transparency, and as JPEG, which also leaves the EXIF data behind.
     *
     * @param string $contents The image as it came.
     * @return string|null The JPEG, or null if it is not an image.
     */
    public static function normalise(string $contents): ?string {
        $info = @getimagesizefromstring($contents);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
            return null;
        }
        $image = @imagecreatefromstring($contents);
        if (!$image) {
            return null;
        }
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $stream = fopen('php://memory', 'r+');
            fwrite($stream, $contents);
            rewind($stream);
            $exif = @exif_read_data($stream);
            fclose($stream);
            $angle = [3 => 180, 6 => -90, 8 => 90][(int) ($exif['Orientation'] ?? 1)] ?? 0;
            if ($angle) {
                $image = imagerotate($image, $angle, 0) ?: $image;
            }
        }
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, self::SIZE / max($width, $height));
        $newwidth = max(1, (int) round($width * $scale));
        $newheight = max(1, (int) round($height * $scale));
        $canvas = imagecreatetruecolor($newwidth, $newheight);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $newwidth, $newheight, $width, $height);
        ob_start();
        imagejpeg($canvas, null, 85);

        return (string) ob_get_clean();
    }
}
