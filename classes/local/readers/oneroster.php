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

// Copied from local_oksigeniaayvurenda (classes/core/readers/oneroster.php) by kit/lectores/lectores.py in hq.
// Do not edit it here: change it there and run the script again.

/**
 * The people of a OneRoster export, as readers.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_oksigeniafamily\local\readers;

/**
 * OneRoster (1EdTech), the CSV files that school management programs export to share their rosters: users, their
 * roles, schools (orgs), classes and who is in each (enrollments), in a zip with a manifest. Version 1.2 keeps each
 * user's role in roles.csv; 1.1 kept it in users.csv: both are read, by the names of the columns. The same rows may
 * come from the REST API instead of the files (oneroster_rest turns its answers into them). The group of a
 * student is their homeroom class; the teacher of a homeroom is its tutor. This turns the files into readers as the
 * lists of the library take them. Plain PHP, without Moodle.
 */
final class oneroster {
    /** @var array<string, string> OneRoster roles, as kinds of reader. */
    private const KINDS = ['student' => 'student', 'teacher' => 'teacher', 'aide' => 'teacher', 'proctor' => 'teacher',
        'administrator' => 'staff', 'principal' => 'staff', 'siteAdministrator' => 'staff', 'counselor' => 'staff',
        'districtAdministrator' => 'staff', 'systemAdministrator' => 'staff', 'parent' => 'family',
        'guardian' => 'family', 'relative' => 'family'];

    /**
     * The readers of an export.
     *
     * @param array $files File name (users.csv…) => its text, or its rows already read (from the REST API).
     * @param string $school The sourcedId of the school to take, or empty for every one.
     * @param bool $families Whether parents, guardians and relatives come as readers.
     * @param int $now
     * @return array readers (as the lists take them, with externalid), gone (keys of who is to leave), tutors (group =>
     *     externalid of its teacher), schools (sourcedId => name), mode (bulk or delta), version.
     * @throws \InvalidArgumentException When users.csv is not there.
     */
    public static function readers(array $files, string $school, bool $families, int $now): array {
        if (!isset($files['users.csv'])) {
            throw new \InvalidArgumentException('users.csv');
        }
        $manifest = [];
        foreach (self::rows($files, 'manifest.csv') as $row) {
            $manifest[$row['propertyName'] ?? ''] = $row['value'] ?? '';
        }
        $schools = [];
        foreach (self::rows($files, 'orgs.csv') as $row) {
            if (in_array($row['type'] ?? '', ['school', ''], true) && ($row['sourcedId'] ?? '') !== '') {
                $schools[$row['sourcedId']] = trim($row['name'] ?? '') ?: $row['sourcedId'];
            }
        }
        $users = self::by_id(self::rows($files, 'users.csv'));
        $roles = self::roles($files, $users, $now);
        // The classes, and who is in each.
        $classes = self::by_id(self::rows($files, 'classes.csv'));
        $homerooms = [];
        $taught = [];
        foreach (self::rows($files, 'enrollments.csv') as $row) {
            $class = $classes[$row['classSourcedId'] ?? ''] ?? null;
            if (!$class || !self::alive($row, $now) || ($school !== '' && ($class['schoolSourcedId'] ?? '') !== $school)) {
                continue;
            }
            $title = trim($class['title'] ?? '');
            $user = $row['userSourcedId'] ?? '';
            if (($class['classType'] ?? '') === 'homeroom' && $title !== '') {
                if (($row['role'] ?? '') === 'student') {
                    $homerooms[$user] = $title;
                } else if (($row['role'] ?? '') === 'teacher' && (!isset($taught[$title]) || ($row['primary'] ?? '') === 'true')) {
                    $taught[$title] = $user;
                }
            }
            $roles[$user] = $roles[$user] ?? [['role' => $row['role'] ?? '', 'org' => $class['schoolSourcedId'] ?? '',
                'primary' => true]];
        }
        $readers = [];
        $gone = [];
        foreach ($users as $id => $user) {
            $theirs = array_values(array_filter($roles[$id] ?? [], fn($r) => $school === '' || $r['org'] === $school
                || $r['org'] === '' || ($user['primaryOrgSourcedId'] ?? '') === $school));
            usort($theirs, fn($a, $b) => (int) $b['primary'] - (int) $a['primary']);
            $kind = self::KINDS[$theirs[0]['role'] ?? ''] ?? null;
            if ($kind === null || ($kind === 'family' && !$families)) {
                continue;
            }
            if (($user['status'] ?? '') === 'tobedeleted' || strtolower($user['enabledUser'] ?? 'true') === 'false') {
                $gone[] = 'id:oneroster:' . $id;
                continue;
            }
            $group = $kind === 'student' ? ($homerooms[$id] ?? '') : '';
            $readers[] = array_filter([
                'externalid' => 'oneroster:' . $id,
                'firstname' => trim(($user['preferredGivenName'] ?? '') !== '' ? $user['preferredGivenName']
                    : ($user['givenName'] ?? '')),
                'lastname' => trim(($user['preferredFamilyName'] ?? '') !== '' ? $user['preferredFamilyName']
                    : ($user['familyName'] ?? '')),
                'email' => trim($user['email'] ?? ''),
                'idnumber' => trim($user['identifier'] ?? ''),
                'username' => trim($user['username'] ?? ''),
                'group' => $group,
                'stage' => $group !== '' ? stage_guesser::guess($group) : '',
                'type' => $kind,
            ], fn($v) => $v !== '');
        }
        $tutors = [];
        foreach ($taught as $title => $teacher) {
            if (isset($users[$teacher])) {
                $tutors[$title] = 'oneroster:' . $teacher;
            }
        }
        return ['readers' => $readers, 'gone' => $gone, 'tutors' => $tutors, 'schools' => $schools,
            'mode' => $manifest['file.users'] ?? 'delta', 'version' => $manifest['oneroster.version'] ?? ''];
    }

    /**
     * The families of an export: each parent, guardian or relative with the students they are family of, from the
     * links of users.csv (agentSourcedIds) read both ways, the student's and the adult's. Only students of the school
     * given, and only who is enabled and not to be deleted.
     *
     * @param array $files File name (users.csv…) => its text, or its rows already read.
     * @param string $school The sourcedId of the school to take, or empty for every one.
     * @param int $now
     * @return array[] key, firstname, lastname, email, phone (the mobile first), relation (parent, guardian or relative)
     *     and children (their keys); keys as oneroster:<sourcedId>.
     * @throws \InvalidArgumentException When users.csv is not there.
     */
    public static function families(array $files, string $school, int $now): array {
        if (!isset($files['users.csv'])) {
            throw new \InvalidArgumentException('users.csv');
        }
        $users = self::by_id(self::rows($files, 'users.csv'));
        $roles = self::roles($files, $users, $now);
        $alive = fn(string $id) => isset($users[$id]) && ($users[$id]['status'] ?? '') !== 'tobedeleted'
            && strtolower($users[$id]['enabledUser'] ?? 'true') !== 'false';
        $is = function (string $id, array $kinds) use ($roles, $users, $school): ?string {
            foreach ($roles[$id] ?? [] as $role) {
                $here = $school === '' || $role['org'] === $school || $role['org'] === ''
                    || ($users[$id]['primaryOrgSourcedId'] ?? '') === $school;
                if ($here && in_array($role['role'], $kinds, true)) {
                    return $role['role'];
                }
            }
            return null;
        };
        $agents = fn(string $id) => array_values(array_filter(array_map(
            'trim',
            explode(',', $users[$id]['agentSourcedIds'] ?? '')
        )));
        $links = [];
        foreach (array_keys($users) as $id) {
            $id = (string) $id;
            foreach ($agents($id) as $other) {
                // Either way round: a student names their family, or an adult names their children.
                if ($is($id, ['student']) && $is($other, ['parent', 'guardian', 'relative'])) {
                    $links[$other][$id] = true;
                } else if ($is($other, ['student']) && $is($id, ['parent', 'guardian', 'relative'])) {
                    $links[$id][$other] = true;
                }
            }
        }
        $adults = [];
        foreach ($links as $adult => $children) {
            $children = array_values(array_filter(array_keys($children), fn($c) => $alive((string) $c)));
            if (!$alive((string) $adult) || !$children) {
                continue;
            }
            $user = $users[$adult];
            $adults[] = array_filter([
                'key' => 'oneroster:' . $adult,
                'firstname' => trim(($user['preferredGivenName'] ?? '') ?: ($user['givenName'] ?? '')),
                'lastname' => trim(($user['preferredFamilyName'] ?? '') ?: ($user['familyName'] ?? '')),
                'email' => trim($user['email'] ?? ''),
                'phone' => trim(($user['sms'] ?? '') ?: ($user['phone'] ?? '')),
                'relation' => (string) $is((string) $adult, ['parent', 'guardian', 'relative']),
                'children' => array_map(fn($c) => 'oneroster:' . $c, $children),
            ], fn($v) => $v !== '' && $v !== []);
        }
        return $adults;
    }

    /**
     * What each user is, and where: roles.csv (1.2) or the columns of users.csv (1.1).
     *
     * @param array $files
     * @param array $users By sourcedId.
     * @param int $now
     * @return array<string, array[]> By user: role, org, primary.
     */
    private static function roles(array $files, array $users, int $now): array {
        $roles = [];
        foreach (self::rows($files, 'roles.csv') as $row) {
            if (self::alive($row, $now) && ($row['userSourcedId'] ?? '') !== '') {
                $roles[$row['userSourcedId']][] = ['role' => $row['role'] ?? '', 'org' => $row['orgSourcedId'] ?? '',
                    'primary' => ($row['roleType'] ?? '') === 'primary'];
            }
        }
        foreach ($users as $id => $user) {
            if (!isset($roles[$id]) && ($user['role'] ?? '') !== '') {
                foreach (array_filter(array_map('trim', explode(',', $user['orgSourcedIds'] ?? ''))) ?: [''] as $org) {
                    $roles[$id][] = ['role' => $user['role'], 'org' => $org, 'primary' => true];
                }
            }
        }
        return $roles;
    }

    /**
     * Whether a row counts now: not to be deleted and not ended.
     *
     * @param array $row
     * @param int $now
     * @return bool
     */
    private static function alive(array $row, int $now): bool {
        if (($row['status'] ?? '') === 'tobedeleted') {
            return false;
        }
        $end = trim($row['endDate'] ?? '');
        return $end === '' || strtotime($end . ' 23:59:59') === false || strtotime($end . ' 23:59:59') >= $now;
    }

    /**
     * The rows of a table by their sourcedId.
     *
     * @param array[] $rows
     * @return array<string, array>
     */
    private static function by_id(array $rows): array {
        $byid = [];
        foreach ($rows as $row) {
            if (($row['sourcedId'] ?? '') !== '') {
                $byid[$row['sourcedId']] = $row;
            }
        }
        return $byid;
    }

    /**
     * The rows of one of the files: its text read as CSV, or rows given as they are (what the REST API gave, in the
     * columns of the CSV files).
     *
     * @param array $files
     * @param string $name
     * @return array[]
     */
    private static function rows(array $files, string $name): array {
        $file = $files[$name] ?? '';
        return is_array($file) ? array_values(array_filter($file, 'is_array')) : self::table((string) $file);
    }

    /**
     * The rows of a CSV file of OneRoster (UTF-8, commas, a header row), each by the names of the columns.
     *
     * @param string $text
     * @return array[]
     */
    public static function table(string $text): array {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        if (trim($text) === '') {
            return [];
        }
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $text);
        rewind($stream);
        $header = array_map('trim', fgetcsv($stream, null, ',', '"', '') ?: []);
        $rows = [];
        while (($cells = fgetcsv($stream, null, ',', '"', '')) !== false) {
            if ($cells === [null]) {
                continue;
            }
            $rows[] = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), ''));
        }
        fclose($stream);
        return $rows;
    }
}
