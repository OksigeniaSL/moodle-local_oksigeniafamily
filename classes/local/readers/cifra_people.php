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

// Copied from local_oksigeniaayvurenda (classes/core/readers/cifra_people.php) by kit/lectores/lectores.py in hq.
// Do not edit it here: change it there and run the script again.

/**
 * The people of a Cifra list, as readers.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_oksigeniafamily\local\readers;

/**
 * Cifra, a school management program, gives each person with their names, email, main academic group (its label, such
 * as «ESO 1º A»), what they are (student, teacher, employee, parent) and the day they left, if they did. This turns its
 * list into readers as the lists of the library take them. Plain PHP, without Moodle.
 */
final class cifra_people {
    /**
     * The readers of a list.
     *
     * @param array[] $people As Cifra gives them.
     * @param int $now
     * @return array{readers: array[], gone: string[]} The readers there now, and the keys of those who left.
     */
    public static function readers(array $people, int $now): array {
        $readers = [];
        $gone = [];
        $seen = [];
        foreach ($people as $person) {
            $id = (string) ($person['id'] ?? '');
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $left = (string) ($person['fecha_baja'] ?? '');
            if ($left !== '' && strtotime($left) !== false && strtotime($left) <= $now) {
                $gone[] = 'id:cifra:' . $id;
                continue;
            }
            $traits = (array) ($person['caracteristicas'] ?? []);
            $type = !empty($traits['alumno']) ? 'student' : (!empty($traits['profesor']) ? 'teacher'
                : (!empty($traits['empleado']) ? 'staff' : (!empty($traits['padre']) ? 'family' : 'student')));
            $group = trim((string) ($person['grupo_academico_principal']['etiqueta'] ?? ''));
            $readers[] = array_filter([
                'externalid' => 'cifra:' . $id,
                'firstname' => trim((string) ($person['nombre'] ?? '')),
                'lastname' => trim(($person['apellido1'] ?? '') . ' ' . ($person['apellido2'] ?? '')),
                'email' => trim((string) ($person['email'] ?? '')),
                'group' => $type === 'student' ? $group : '',
                'stage' => $type === 'student' && $group !== '' ? stage_guesser::guess($group) : '',
                'type' => $type,
            ], fn($v) => $v !== '');
        }
        return ['readers' => $readers, 'gone' => $gone];
    }

    /**
     * The families of the students, from the record of each one (Cifra gives `familiares` only there, not in the list):
     * each adult once, with the students they are family of, their kinship and whether they are a legal guardian. An
     * adult is known by their id in Cifra, which every user has, the same as a person's (Enrique, 31 October 2026); a
     * relative without one, by their email or mobile. So the same parent of two brothers is one. Students who left
     * bring no family.
     *
     * @param array[] $records The records of the students, as Cifra gives them.
     * @param int $now
     * @return array[] key, firstname, lastname, email, phone (the mobile first), relation (as Cifra says it), guardian
     *     and children (their keys); keys as cifra:<id>.
     */
    public static function families(array $records, int $now): array {
        $adults = [];
        foreach ($records as $record) {
            $id = (string) ($record['id'] ?? '');
            $left = (string) ($record['fecha_baja'] ?? '');
            if ($id === '' || ($left !== '' && strtotime($left) !== false && strtotime($left) <= $now)) {
                continue;
            }
            foreach ((array) ($record['familiares'] ?? []) as $relative) {
                $person = (array) ($relative['usuario'] ?? $relative);
                $email = mb_strtolower(trim((string) ($person['email'] ?? '')), 'UTF-8');
                $phone = preg_replace(
                    '/[^\d+]/',
                    '',
                    (string) (($person['telefono_movil'] ?? '') ?: ($person['telefono_fijo'] ?? ''))
                );
                $own = (string) ($person['id'] ?? '');
                $key = $own !== '' ? 'cifra:' . $own
                    : ($email !== '' ? 'cifra:mail:' . $email : ($phone !== '' ? 'cifra:tel:' . $phone : ''));
                if ($key === '') {
                    continue;
                }
                $guardian = ($relative['tutor'] ?? null) === true || in_array(
                    mb_strtolower(trim((string) ($relative['tutor'] ?? '')), 'UTF-8'),
                    ['1', 'true', 'si', 'sí', 's', 'yes'],
                    true
                );
                $adults[$key] = $adults[$key] ?? array_filter([
                    'key' => $key,
                    'firstname' => trim((string) ($person['nombre'] ?? '')),
                    'lastname' => trim((string) ($person['apellidos']
                        ?? (($person['apellido1'] ?? '') . ' ' . ($person['apellido2'] ?? '')))),
                    'email' => $email,
                    'phone' => $phone,
                    'relation' => trim((string) ($relative['parentesco'] ?? '')),
                ], fn($v) => $v !== '') + ['guardian' => false, 'children' => []];
                $adults[$key]['guardian'] = $adults[$key]['guardian'] || $guardian;
                $adults[$key]['children'][] = 'cifra:' . $id;
            }
        }
        foreach ($adults as $key => $adult) {
            $adults[$key]['children'] = array_values(array_unique($adult['children']));
        }
        return array_values($adults);
    }
}
