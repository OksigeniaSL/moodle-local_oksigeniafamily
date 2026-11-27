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
 * The mails of the plugin in the common look of the organization (its name, its colour and its logo, the pictures inside
 * the mail and a text version), by the way of Oksigenia Marandu when it is there (0.70 and later): to an address of
 * home, by the mail of the site; to the rest, by Mailjet if the site chose it. Without Marandu the plugin sends them as
 * it always did, so it works in any Moodle.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class commonmail {
    /** @var string The API of Oksigenia Marandu. */
    private const API = '\local_oksigeniamarandu\api';

    /** @var string This plugin. */
    private const COMPONENT = 'local_oksigeniafamily';

    /**
     * Whether Oksigenia Marandu sends the mails of the tools.
     *
     * @return bool
     */
    public static function available(): bool {
        return class_exists(self::API) && method_exists(self::API, 'mail_letter') && method_exists(self::API, 'mail_notice');
    }

    /**
     * A mail to one person, sent now. It is never routine: it goes even when the month is near its quota.
     *
     * @param \stdClass|string $to An account, or an address without one.
     * @param array $pieces As Marandu's api::mail_html() takes them, in the language of the reader.
     * @param string $lang The language of the reader, for an address without an account.
     * @return bool|null Whether it went; null without Marandu, or if Marandu could not take it, for the plugin to send it
     *     its own way.
     */
    public static function send($to, array $pieces, string $lang = ''): ?bool {
        if (!self::available()) {
            return null;
        }
        $api = self::API;
        $options = ['component' => self::COMPONENT];
        if ($lang !== '') {
            $options['lang'] = $lang;
        }
        try {
            $went = $api::mail_letter($to, $pieces, $options);
        } catch (\Throwable $e) {
            debugging('The common mail: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }

        return in_array($went, ['site', 'mailjet', 'queued'], true);
    }

    /**
     * Gives a notice of Moodle its mail in the common look, just before message_send(). What the notice shows in the
     * bell and in the app does not change; without Marandu, nothing does.
     *
     * @param \core\message\message $message The notice.
     * @param array $pieces As Marandu's api::mail_html() takes them, in the language of the reader.
     * @return void
     */
    public static function notice(\core\message\message $message, array $pieces): void {
        if (!self::available()) {
            return;
        }
        $api = self::API;
        try {
            $api::mail_notice($message, $pieces);
        } catch (\Throwable $e) {
            debugging('The common mail of a notice: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * A text of the plugin's pages as a piece of the mail takes it: the mail escapes what it shows, so what
     * format_string() already escaped («&amp;») would show twice.
     *
     * @param string $text The text, as the pages show it.
     * @return string
     */
    public static function plain(string $text): string {
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * A text as pieces of the mail: a paragraph for each block of lines.
     *
     * @param string $text The text.
     * @return array[]
     */
    public static function paragraphs(string $text): array {
        $blocks = [];
        foreach (preg_split('/\R\s*\R/', trim($text)) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $blocks[] = ['paragraph' => $part];
            }
        }

        return $blocks;
    }
}
