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
 * The notices the family records send.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class notices {
    /**
     * A new adult joined a student with the student's code: the student's other adults (those with an account) and
     * whoever manages the families of the school are told, so a code that went astray is found at once. Those who manage
     * them get a link to the record, where they can remove the adult.
     *
     * @param \stdClass $adult The adult who joined.
     * @param int $childuserid The student.
     * @param \stdClass[] $others The adults the student already had.
     * @return void
     */
    public static function new_adult(\stdClass $adult, int $childuserid, array $others): void {
        $child = \core_user::get_user($childuserid);
        if (!$child) {
            return;
        }

        $recipients = [];
        foreach ($others as $other) {
            if (!empty($other->userid)) {
                $recipients[(int) $other->userid] = false;
            }
        }
        foreach (get_users_by_capability(\context_system::instance(), 'local/oksigeniafamily:manage', 'u.id') as $manager) {
            $recipients[(int) $manager->id] = true;
        }
        unset($recipients[(int) ($adult->userid ?? 0)]);

        $name = trim(($adult->firstname ?? '') . ' ' . ($adult->lastname ?? ''));
        $a = (object) [
            'adult' => $name !== '' ? $name : ($adult->phone ?? get_string('notice:someone', 'local_oksigeniafamily')),
            'child' => fullname($child),
        ];
        $record = new \moodle_url('/local/oksigeniafamily/family.php', ['id' => $adult->familyid]);
        foreach ($recipients as $userid => $manages) {
            $message = new \core\message\message();
            $message->component = 'local_oksigeniafamily';
            $message->name = 'newadult';
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = $userid;
            $message->subject = get_string('notice:newadult:subject', 'local_oksigeniafamily', $a);
            $message->fullmessage = get_string('notice:newadult', 'local_oksigeniafamily', $a);
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = '';
            $message->smallmessage = $message->subject;
            $message->notification = 1;
            if ($manages) {
                $message->contexturl = $record->out(false);
                $message->contexturlname = get_string('notice:newadult:record', 'local_oksigeniafamily');
            }
            message_send($message);
        }
    }
}
