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

use local_oksigeniafamily\api;

/**
 * The letters with the claim codes. A code is kept only as its hash, so it cannot be read back: a letter exists only in
 * the page that issues it, which is not stored anywhere and cannot be asked for twice. There is nothing to reprint from;
 * a lost letter is replaced by a new code, and the old one stops working.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class letters {
    /** Letters for those with no family and no code in use. */
    public const PENDING = 'pending';

    /** Letters for those with no code in use, even if they have a family (a second home, a new adult). */
    public const NOCODE = 'nocode';

    /** Letters for everyone: the codes in use stop working. */
    public const REPLACE = 'replace';

    /**
     * How many letters each choice would give.
     *
     * @param array $state How the students stand, from students::state().
     * @return int[] By choice.
     */
    public static function counts(array $state): array {
        $counts = [self::PENDING => 0, self::NOCODE => 0, self::REPLACE => count($state)];
        foreach ($state as $one) {
            if (!$one['expires']) {
                $counts[self::NOCODE]++;
                if (!$one['adults']) {
                    $counts[self::PENDING]++;
                }
            }
        }

        return $counts;
    }

    /**
     * New codes for the students a choice takes, all of them or none.
     *
     * @param \stdClass[] $users The students, keyed by id.
     * @param string $mode The choice.
     * @param int $now The time to work from.
     * @return array Each with user, code and expires. It is the only place where the codes exist: show them, keep them
     *     nowhere.
     */
    public static function issue(array $users, string $mode, int $now): array {
        global $DB;

        \core_php_time_limit::raise();
        $state = students::state(array_keys($users), $now);
        $days = max(1, (int) get_config('local_oksigeniafamily', 'claimdays') ?: 60);
        $issued = [];
        $transaction = $DB->start_delegated_transaction();
        foreach ($users as $userid => $user) {
            $one = $state[(int) $userid];
            $wanted = $mode === self::REPLACE
                || ($mode === self::NOCODE && !$one['expires'])
                || ($mode === self::PENDING && !$one['expires'] && !$one['adults']);
            if (!$wanted) {
                continue;
            }
            api::revoke_claim_codes((int) $userid);
            $issued[] = [
                'user' => $user,
                'code' => api::issue_claim_code((int) $userid, $days),
                'expires' => $now + $days * DAYSECS,
            ];
        }
        $transaction->allow_commit();

        return $issued;
    }

    /**
     * What the template of the letters needs.
     *
     * @param array $issued From issue().
     * @param string $group The group or cohort, to hand them out; '' for none.
     * @return array[] One per letter.
     */
    public static function context(array $issued, string $group): array {
        $claim = new \moodle_url('/local/oksigeniafamily/claim.php');
        $site = format_string(get_site()->fullname);
        $label = get_string('letter:qr', 'local_oksigeniafamily');
        $letters = [];
        foreach ($issued as $letter) {
            $code = self::show_code($letter['code']);
            $a = (object) ['student' => fullname($letter['user']), 'site' => $site];
            $letters[] = [
                'title' => get_string('letter:title', 'local_oksigeniafamily', $a),
                'intro' => get_string('letter:intro', 'local_oksigeniafamily', $a),
                'group' => $group,
                'qr' => self::qr_svg($claim->out(false) . '#code=' . $code, $label),
                'code' => $code,
                'expires' => get_string('letter:expires', 'local_oksigeniafamily', userdate(
                    $letter['expires'],
                    get_string('strftimedate', 'langconfig')
                )),
                'step1' => get_string('letter:step1', 'local_oksigeniafamily', $claim->out(false)),
            ];
        }

        return $letters;
    }

    /**
     * A code as people read it: two groups of four.
     *
     * @param string $code The code.
     * @return string
     */
    public static function show_code(string $code): string {
        return substr($code, 0, 4) . '-' . substr($code, 4);
    }

    /**
     * A QR as one small SVG path that prints sharp, so a whole cohort of letters stays light. It is always black, because
     * it is for paper and for a camera, whatever the mode of the page.
     *
     * @param string $text What it says.
     * @param string $label What it is, for screen readers.
     * @return string The SVG.
     */
    public static function qr_svg(string $text, string $label): string {
        $array = (new \core_qrcode($text))->getBarcodeArray();
        $path = '';
        foreach ($array['bcode'] as $y => $row) {
            $x = 0;
            $width = count($row);
            while ($x < $width) {
                if (empty($row[$x])) {
                    $x++;
                    continue;
                }
                $start = $x;
                while ($x < $width && !empty($row[$x])) {
                    $x++;
                }
                $run = $x - $start;
                $path .= "M{$start} {$y}h{$run}v1h-{$run}z";
            }
        }
        // Four empty modules all around, which a QR needs to be read.
        $size = (int) $array['num_cols'] + 8;

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="-4 -4 ' . $size . ' ' . $size . '" role="img" aria-label="'
            . s($label) . '" shape-rendering="crispEdges"><path fill="#000" d="' . $path . '"/></svg>';
    }
}
