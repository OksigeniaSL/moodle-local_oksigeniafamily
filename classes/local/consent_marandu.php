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
 * The answers that come back from WhatsApp and Telegram through Oksigenia Marandu: the family pressed «Yes, I consent»
 * or «No» on the question that consent_notices sent.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class consent_marandu {
    /**
     * Marandu's hook for an answer to a question. It comes for the questions of every plugin, so first it looks whose
     * it is. The text it sets is what Marandu answers to the person.
     *
     * @param \local_oksigeniamarandu\hook\answer_received $hook The hook, which only exists with Marandu.
     * @return void
     */
    public static function answer_received($hook): void {
        if ($hook->get_component() !== 'local_oksigeniafamily' || $hook->get_notice() !== consent_notices::MARANDU_NOTICE) {
            return;
        }
        $reply = self::answer(
            (string) $hook->get_ref(),
            (int) $hook->get_itemid(),
            (string) $hook->get_option(),
            (string) $hook->get_channel(),
            (string) $hook->get_address(),
            (int) $hook->get_time()
        );
        $hook->set_reply($reply);
    }

    /**
     * Record an answer from WhatsApp or Telegram.
     *
     * @param string $ref What the question was about: "<request>:<student>".
     * @param int $adultid The adult (Marandu's item).
     * @param string $option yes or no.
     * @param string $channel whatsapp or telegram.
     * @param string $address The number, or the chat, that answered.
     * @param int $time When they pressed the button.
     * @return string What to answer the person: what was kept and until when they can change it, or why it was not.
     */
    public static function answer(
        string $ref,
        int $adultid,
        string $option,
        string $channel,
        string $address,
        int $time
    ): string {
        $component = 'local_oksigeniafamily';
        if (!preg_match('/^(\d+):(\d+)$/', $ref, $parts) || !in_array($option, [consent::YES, consent::NO], true)) {
            return get_string('consent:reply:failed', $component);
        }
        $request = consent::get((int) $parts[1]);
        $child = \core_user::get_user((int) $parts[2], 'id, firstname, deleted');
        if (!$request || !$child) {
            return get_string('consent:reply:failed', $component);
        }
        $a = (object) [
            'child' => format_string($child->firstname),
            'title' => format_string($request->title, true, ['context' => \context_system::instance()]),
            'deadline' => page::date((int) $request->deadline),
        ];
        $channel = in_array($channel, ['whatsapp', 'telegram'], true) ? $channel : 'link';
        try {
            consent::answer(
                (int) $request->id,
                (int) $child->id,
                $adultid,
                $option,
                $channel,
                ['address' => $address],
                $time > 0 ? $time : time()
            );
        } catch (\moodle_exception $e) {
            if ($e->errorcode === 'consentclosed') {
                return get_string('consent:reply:closed', $component, $a);
            }
            return get_string('consent:reply:failed', $component);
        }

        return get_string($option === consent::YES ? 'consent:reply:yes' : 'consent:reply:no', $component, $a);
    }
}
