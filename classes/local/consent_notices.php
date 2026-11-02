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
 * What a request for consent sends: to the adults with an account, a notification of Moodle (in the app, by e-mail or
 * on the site, as each one chose); to those without, an e-mail with their personal link; and, with Oksigenia Marandu, a
 * question with two buttons on WhatsApp or Telegram to whoever signed up there.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class consent_notices {
    /** @var string The notice of Marandu for a request (see local_oksigeniafamily_oksigeniamarandu_notices()). */
    public const MARANDU_NOTICE = 'consent';

    /**
     * Send a request, or its reminder, to the adults of its students.
     *
     * @param int $requestid The request.
     * @param bool $reminder Whether it is the reminder: then only to those whose answer is missing.
     * @param int[]|null $childuserids Only about these students (those just added); all if null.
     * @return array How many went: app (notifications of Moodle), email and marandu (questions queued).
     */
    public static function send(int $requestid, bool $reminder = false, ?array $childuserids = null): array {
        global $CFG;

        $sent = ['app' => 0, 'email' => 0, 'marandu' => 0];
        $request = consent::get($requestid);
        $now = time();
        if (!$request || !consent::is_open($request, $now)) {
            return $sent;
        }
        $site = format_string(get_site()->fullname, true, ['context' => \context_system::instance()]);

        foreach (consent::recipients($request, $reminder, $childuserids) as $adultid => $recipient) {
            $adult = $recipient['adult'];
            $children = [];
            foreach ($recipient['children'] as $childuserid) {
                $child = \core_user::get_user($childuserid, 'id, firstname, lastname, deleted');
                if ($child && empty($child->deleted)) {
                    $children[$childuserid] = $child;
                }
            }
            if (!$children) {
                continue;
            }

            $user = !empty($adult->userid) ? \core_user::get_user((int) $adult->userid) : null;
            $usable = $user && empty($user->deleted) && empty($user->suspended);
            $lang = $usable && !empty($user->lang) ? $user->lang : $CFG->lang;
            $previous = force_current_language($lang);
            try {
                if ($usable) {
                    $sent['app'] += self::to_user($request, $user, $children, $site, $reminder) ? 1 : 0;
                } else if (!empty($adult->email) && validate_email($adult->email)) {
                    $sent['email'] += self::to_email($request, $adult, $children, $site, $reminder) ? 1 : 0;
                }
                $sent['marandu'] += self::to_marandu($request, (int) $adultid, $children, $now);
            } finally {
                force_current_language($previous);
            }
        }

        return $sent;
    }

    /**
     * The texts of a request for one adult, in the language in use.
     *
     * @param \stdClass $request The request.
     * @param \stdClass[] $children The students they are family of.
     * @param string $site The name of the site.
     * @param bool $reminder Whether it is the reminder.
     * @param \moodle_url $url Where they answer.
     * @return array subject, text, html and small.
     */
    private static function compose(
        \stdClass $request,
        array $children,
        string $site,
        bool $reminder,
        \moodle_url $url
    ): array {
        $a = (object) [
            'site' => $site,
            'title' => format_string($request->title, true, ['context' => \context_system::instance()]),
            'children' => self::names($children),
            'deadline' => page::date((int) $request->deadline),
        ];
        $component = 'local_oksigeniafamily';
        $subject = get_string($reminder ? 'consent:mail:remindsubject' : 'consent:mail:subject', $component, $a);
        $lead = get_string($reminder ? 'consent:mail:remind' : 'consent:mail:body', $component, $a);
        $button = get_string('consent:mail:button', $component);
        $message = format_text($request->message, (int) $request->messageformat, ['context' => \context_system::instance()]);
        $link = \html_writer::link($url, s($button), [
            'style' => 'display:inline-block;padding:10px 18px;border-radius:6px;background:#0f6cbf;color:#fff;'
                . 'text-decoration:none;font-weight:600',
        ]);
        $html = \html_writer::tag('p', s($lead)) . \html_writer::div($message) . \html_writer::tag('p', $link);
        $text = $lead . "\n\n" . html_to_text($message, 0, false) . "\n\n" . $button . ': ' . $url->out(false);

        return ['subject' => $subject, 'text' => $text, 'html' => $html, 'small' => $lead];
    }

    /**
     * Names joined the way people say them: "Ana, Leo and Eva".
     *
     * @param \stdClass[] $children Users with their first name.
     * @return string
     */
    public static function names(array $children): string {
        $names = array_values(array_map(fn($child) => format_string($child->firstname), $children));
        if (count($names) < 2) {
            return $names[0] ?? '';
        }
        $last = array_pop($names);

        return get_string('consent:and', 'local_oksigeniafamily', (object) ['list' => implode(', ', $names), 'last' => $last]);
    }

    /**
     * A notification of Moodle to an adult with an account: it goes where they chose (the app, e-mail, the site).
     *
     * @param \stdClass $request The request.
     * @param \stdClass $user The adult's account.
     * @param \stdClass[] $children The students.
     * @param string $site The name of the site.
     * @param bool $reminder Whether it is the reminder.
     * @return bool Whether it went.
     */
    private static function to_user(\stdClass $request, \stdClass $user, array $children, string $site, bool $reminder): bool {
        $url = new \moodle_url('/local/oksigeniafamily/consent.php', ['id' => $request->id]);
        $texts = self::compose($request, $children, $site, $reminder, $url);

        $message = new \core\message\message();
        $message->component = 'local_oksigeniafamily';
        $message->name = 'consent';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $user;
        $message->subject = $texts['subject'];
        $message->fullmessage = $texts['text'];
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = $texts['html'];
        $message->smallmessage = $texts['small'];
        $message->notification = 1;
        $message->contexturl = $url->out(false);
        $message->contexturlname = get_string('consent:mail:button', 'local_oksigeniafamily');
        $message->courseid = SITEID;

        return (bool) message_send($message);
    }

    /**
     * An e-mail with the personal link, to an adult with no account.
     *
     * @param \stdClass $request The request.
     * @param \stdClass $adult The adult.
     * @param \stdClass[] $children The students.
     * @param string $site The name of the site.
     * @param bool $reminder Whether it is the reminder.
     * @return bool Whether it went.
     */
    private static function to_email(\stdClass $request, \stdClass $adult, array $children, string $site, bool $reminder): bool {
        global $CFG;

        $url = consent_link::url((int) $request->id, (int) $adult->id, 'email');
        $texts = self::compose($request, $children, $site, $reminder, $url);
        $to = new \stdClass();
        foreach (\core_user\fields::get_name_fields() as $field) {
            $to->$field = '';
        }
        $to->id = -1;
        $to->username = 'outsider';
        $to->confirmed = 1;
        $to->picture = 0;
        $to->maildisplay = 0;
        $to->email = (string) $adult->email;
        $to->firstname = (string) ($adult->firstname ?? '');
        $to->lastname = (string) ($adult->lastname ?? '');
        $to->lang = current_language();
        $to->timezone = $CFG->timezone ?? '99';
        $to->mailformat = 1;
        $to->emailstop = 0;
        $to->auth = 'manual';
        $to->suspended = 0;
        $to->deleted = 0;

        return (bool) email_to_user($to, \core_user::get_noreply_user(), $texts['subject'], $texts['text'], $texts['html']);
    }

    /**
     * A question with two buttons on WhatsApp or Telegram, one for each student, through Oksigenia Marandu, if it is
     * there: it only goes to a number or a chat that signed up, and it waits for the quiet hours like any other notice.
     *
     * @param \stdClass $request The request.
     * @param int $adultid The adult.
     * @param \stdClass[] $children The students.
     * @param int $now The time.
     * @return int How many questions were queued.
     */
    private static function to_marandu(\stdClass $request, int $adultid, array $children, int $now): int {
        $api = '\local_oksigeniamarandu\api';
        if (!class_exists($api) || !method_exists($api, 'ask')) {
            return 0;
        }
        $queued = 0;
        $url = consent_link::url((int) $request->id, $adultid, 'link');
        $title = format_string($request->title, true, ['context' => \context_system::instance()]);
        foreach ($children as $childuserid => $child) {
            try {
                $queued += (int) $api::ask(
                    'local_oksigeniafamily',
                    self::MARANDU_NOTICE,
                    [format_string($child->firstname), $title, page::date((int) $request->deadline)],
                    $request->id . ':' . $childuserid,
                    $adultid,
                    null,
                    $url,
                    max(HOURSECS, (int) $request->deadline - $now)
                );
            } catch (\Throwable $e) {
                debugging('Consent through Marandu: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        return $queued;
    }

    /**
     * Two adults of a student said different things: whoever made the request is told, with the link to its answers.
     *
     * @param \stdClass $request The request.
     * @param int $childuserid The student.
     * @return void
     */
    public static function conflict(\stdClass $request, int $childuserid): void {
        $user = \core_user::get_user((int) $request->usercreated);
        $child = \core_user::get_user($childuserid);
        if (!$user || !empty($user->deleted) || !empty($user->suspended) || !$child) {
            return;
        }
        $previous = force_current_language(!empty($user->lang) ? $user->lang : '');
        try {
            $a = (object) [
                'child' => fullname($child),
                'title' => format_string($request->title, true, ['context' => \context_system::instance()]),
            ];
            $url = new \moodle_url('/local/oksigeniafamily/consent_report.php', ['id' => $request->id]);
            $text = get_string('consent:conflict', 'local_oksigeniafamily', $a);

            $message = new \core\message\message();
            $message->component = 'local_oksigeniafamily';
            $message->name = 'consentconflict';
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = $user;
            $message->subject = get_string('consent:conflictsubject', 'local_oksigeniafamily', $a);
            $message->fullmessage = $text . "\n\n" . $url->out(false);
            $message->fullmessageformat = FORMAT_PLAIN;
            $link = \html_writer::link($url, get_string('consent:seeanswers', 'local_oksigeniafamily'));
            $message->fullmessagehtml = \html_writer::tag('p', s($text)) . \html_writer::tag('p', $link);
            $message->smallmessage = $text;
            $message->notification = 1;
            $message->contexturl = $url->out(false);
            $message->contexturlname = get_string('consent:seeanswers', 'local_oksigeniafamily');
            $message->courseid = SITEID;
            message_send($message);
        } finally {
            force_current_language($previous);
        }
    }
}
