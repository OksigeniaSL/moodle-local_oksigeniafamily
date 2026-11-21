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
 * «Not of my family»: an adult of a family takes out, without the school, someone who joined it with a code.
 *
 * Whoever joins with a family's invitation or a child's code is told to the family's other adults (notices::new_adult());
 * one of them who does not know the newcomer takes them out from the notice, and the code they came with stops working
 * (api::remove_adult(), manual). Only someone who came with a code, in the last month: an adult the school's data brought
 * is the school's, so that one parent cannot take the other out.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class notyours {
    /** @var int How long after joining another adult of the family can take someone out. */
    public const WINDOW = 30 * DAYSECS;

    /** @var string The page asks. */
    public const ASK = 'ask';

    /** @var string The adult is no longer in the family. */
    public const GONE = 'gone';

    /** @var string The person looking cannot take them out: not of that family, the same adult, or the school's. */
    public const CANNOT = 'cannot';

    /** @var string Taken out just now. */
    public const DONE = 'done';

    /**
     * What the page can do for this person and this adult.
     *
     * @param int $adultid The adult who joined.
     * @param int $userid The person looking.
     * @return string ASK, GONE or CANNOT.
     */
    public static function state(int $adultid, int $userid): string {
        global $DB;
        $adult = $DB->get_record('local_oksigeniafamily_adult', ['id' => $adultid]);
        if (!$adult || $adult->status !== api::ACTIVE) {
            return self::GONE;
        }
        $viewer = $DB->get_record_select(
            'local_oksigeniafamily_adult',
            'familyid = :familyid AND userid = :userid AND status = :active AND id <> :adultid',
            ['familyid' => $adult->familyid, 'userid' => $userid, 'active' => api::ACTIVE, 'adultid' => $adult->id],
            'id',
            IGNORE_MULTIPLE
        );
        if (!$viewer || (int) $adult->userid === $userid) {
            return self::CANNOT;
        }
        if (trim((string) $adult->sourcekey) !== '' || (int) $adult->timecreated < time() - self::WINDOW) {
            return self::CANNOT;
        }
        return self::ASK;
    }

    /**
     * Take the adult out, when the person may.
     *
     * @param int $adultid The adult who joined.
     * @param int $userid The person taking them out.
     * @return string DONE, or what state() says when they may not.
     */
    public static function remove(int $adultid, int $userid): string {
        $state = self::state($adultid, $userid);
        if ($state !== self::ASK) {
            return $state;
        }
        api::remove_adult($adultid, 'manual');
        return self::DONE;
    }

    /**
     * What the page shows.
     *
     * @param int $adultid The adult who joined.
     * @param string $state ASK, GONE, CANNOT or DONE.
     * @return array For the template local_oksigeniafamily/notyours.
     */
    public static function context(int $adultid, string $state): array {
        global $DB;
        $adult = $DB->get_record('local_oksigeniafamily_adult', ['id' => $adultid]);
        $name = $adult ? trim($adult->firstname . ' ' . $adult->lastname) : '';
        if ($name === '') {
            $name = get_string('notice:someone', 'local_oksigeniafamily');
        }
        $component = 'local_oksigeniafamily';
        return [
            'ask' => $state === self::ASK,
            'question' => get_string('notyours:question', $component, $name),
            'explain' => get_string('notyours:explain', $component, $name),
            'remove' => get_string('notyours:remove', $component),
            'keep' => get_string('notyours:keep', $component),
            'keepurl' => (new \moodle_url('/'))->out(false),
            'formurl' => (new \moodle_url('/local/oksigeniafamily/notyours.php'))->out(false),
            'adultid' => $adultid,
            'sesskey' => sesskey(),
            'message' => $state === self::ASK ? '' : get_string('notyours:' . $state, $component, $name),
        ];
    }
}
