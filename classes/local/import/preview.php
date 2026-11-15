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

use local_oksigeniafamily\local\families;

/**
 * What an import would change, as the preview page shows it: worked out from the plan in the page for a file of a few
 * thousand people, and in the background for a bigger one (see job::make_preview), so that it can wait as plain data.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preview {
    /** @var int Families with changes listed one by one; the rest are only counted. */
    public const LISTED = 60;

    /**
     * The preview of a plan, for the template local_oksigeniafamily/import_preview, without what belongs to the page
     * (navigation, form, key): plain data only.
     *
     * @param array $plan From importer::plan().
     * @param string[] $unknown The columns of the file that are not used.
     * @return array
     */
    public static function view(array $plan, array $unknown): array {
        global $DB;

        $component = 'local_oksigeniafamily';
        $userids = [];
        foreach ($plan['families'] as $family) {
            foreach ($family['children'] as $child) {
                $userids[] = $child['userid'];
            }
        }
        $userids = array_values(array_unique(array_filter($userids)));
        $fields = 'id, ' . implode(', ', \core_user\fields::get_name_fields());
        $users = $userids ? $DB->get_records_list('user', 'id', $userids, '', $fields) : [];

        // In a new family everybody is new: chips only say something in a family that was already there.
        $chip = function (array $person, array $family) use ($component) {
            if (!$family['id']) {
                return null;
            }
            if (empty($person['id'])) {
                return ['label' => get_string('import:chip:new', $component), 'kind' => 'new'];
            }
            if ($person['changes']) {
                $fields = array_unique($person['changes']);
                $fields = array_map(fn($field) => get_string('import:field:' . $field, $component), $fields);
                return ['label' => get_string('import:chip:changes', $component, implode(', ', $fields)), 'kind' => 'warn'];
            }
            return ['label' => get_string('import:chip:same', $component), 'kind' => 'muted'];
        };

        $changing = [];
        $quiet = 0;
        foreach ($plan['families'] as $family) {
            $adults = [];
            $children = [];
            $changes = !$family['id'];
            foreach ($family['adults'] as $adult) {
                if (empty($adult['seen'])) {
                    continue;
                }
                $changes = $changes || empty($adult['id']) || $adult['changes'];
                $data = $adult['data'];
                $adults[] = [
                    'name' => families::adult_name((object) $data),
                    'phone' => $data['phone'] ?? '',
                    'email' => $data['email'] ?? '',
                    'chip' => $chip($adult, $family),
                ];
            }
            foreach ($family['children'] as $child) {
                if (empty($child['seen'])) {
                    continue;
                }
                $changes = $changes || empty($child['id']) || $child['changes'];
                $children[] = [
                    'name' => isset($users[$child['userid']]) ? fullname($users[$child['userid']]) : $child['sourcekey'],
                    'noaccount' => !$child['userid'],
                    'chip' => $chip($child, $family),
                ];
            }
            if (!$changes) {
                $quiet++;
            } else if (count($changing) < self::LISTED) {
                $changing[] = [
                    'key' => $family['key'],
                    'isnew' => !$family['id'],
                    'adults' => $adults,
                    'children' => $children,
                ];
            }
        }

        $counts = $plan['counts'];
        $stats = [];
        foreach (['familiesnew', 'adultsnew', 'adultschanged', 'childrennew', 'childrenleaving', 'errors'] as $name) {
            if ($counts[$name] > 0 || in_array($name, ['familiesnew', 'adultsnew', 'childrennew'], true)) {
                $stats[] = [
                    'value' => $counts[$name],
                    'label' => get_string('import:stat:' . $name, $component),
                    'bad' => $name === 'errors',
                ];
            }
        }
        $work = $counts['familiesnew'] + $counts['adultsnew'] + $counts['adultschanged'] + $counts['childrennew']
            + $counts['childrenchanged'] + $counts['childrenleaving'];
        $leaving = array_map(fn($child) => ['key' => $child->sourcekey, 'family' => $child->familykey], $plan['leaving']);

        return [
            'stats' => $stats,
            'unknown' => $unknown ? get_string('import:file:unknown', $component, implode(', ', $unknown)) : '',
            'errors' => array_values($plan['errors']),
            'haserrors' => !empty($plan['errors']),
            'families' => $changing,
            'hasfamilies' => !empty($changing),
            'quiet' => $quiet ? get_string('import:quiet', $component, $quiet) : '',
            'leaving' => $leaving,
            'hasleaving' => !empty($leaving),
            'guarded' => (bool) $plan['guarded'],
            'leavingcount' => count($leaving),
            'haswork' => $work > 0,
        ];
    }
}
