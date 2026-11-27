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
     * them get a link to the record; the adults, «Not of my family», to take the newcomer out themselves.
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
        $record = new \moodle_url('/local/oksigeniafamily/family.php', ['id' => $adult->familyid]);
        $site = commonmail::plain(format_string(get_site()->fullname, true, ['context' => \context_system::instance()]));
        foreach ($recipients as $userid => $manages) {
            $user = \core_user::get_user($userid);
            if (!$user || !empty($user->deleted) || !empty($user->suspended)) {
                continue;
            }
            // In the language of whoever gets it, not of whoever joined.
            $previous = force_current_language(!empty($user->lang) ? $user->lang : '');
            try {
                self::tell_new_adult($user, $manages, $adult, $name, $child, $record, $site);
            } finally {
                force_current_language($previous);
            }
        }
    }

    /**
     * One notice of a new adult, in the language in use.
     *
     * @param \stdClass $user Who gets it.
     * @param bool $manages Whether they manage the families of the school.
     * @param \stdClass $adult The adult who joined.
     * @param string $name The adult's name, if any.
     * @param \stdClass $child The student.
     * @param \moodle_url $record The family's record.
     * @param string $site The name of the site.
     * @return void
     */
    private static function tell_new_adult(
        \stdClass $user,
        bool $manages,
        \stdClass $adult,
        string $name,
        \stdClass $child,
        \moodle_url $record,
        string $site
    ): void {
        global $OUTPUT;

        $a = (object) [
            'adult' => $name !== '' ? $name : ($adult->phone ?? get_string('notice:someone', 'local_oksigeniafamily')),
            'child' => fullname($child),
        ];
        $message = new \core\message\message();
        $message->component = 'local_oksigeniafamily';
        $message->name = 'newadult';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $user;
        $message->subject = get_string('notice:newadult:subject', 'local_oksigeniafamily', $a);
        // Whoever manages the families gets the record; an adult of the family, «Not of my family», which takes the
        // newcomer out without the school (local\notyours).
        $message->fullmessage = get_string($manages ? 'notice:newadult' : 'notice:newadult:adult', 'local_oksigeniafamily', $a);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = '';
        $message->smallmessage = $message->subject;
        $message->notification = 1;
        if ($manages) {
            $message->contexturl = $record->out(false);
            $message->contexturlname = get_string('notice:newadult:record', 'local_oksigeniafamily');
        } else {
            $message->contexturl = (new \moodle_url('/local/oksigeniafamily/notyours.php', ['a' => $adult->id]))->out(false);
            $message->contexturlname = get_string('notice:newadult:notyours', 'local_oksigeniafamily');
        }
        // The app draws its own icon only beside notices of activities; anyone else's, it takes from the notice.
        $message->customdata = ['notificationiconurl' => $OUTPUT->image_url('icon', 'local_oksigeniafamily')->out(false)];
        commonmail::notice($message, [
            'subject' => $message->subject,
            'heading' => $message->subject,
            'preheader' => $message->fullmessage,
            'hello' => get_string('mail:hello', 'local_oksigeniafamily', $user->firstname),
            'blocks' => [
                ['paragraph' => $message->fullmessage],
                ['action' => ['actionurl' => $message->contexturl, 'actiontext' => $message->contexturlname]],
            ],
            'reason' => $manages ? get_string('notice:newadult:reason:manage', 'local_oksigeniafamily', $site)
                : get_string('notice:newadult:reason', 'local_oksigeniafamily', $a),
        ]);
        message_send($message);
    }
}
