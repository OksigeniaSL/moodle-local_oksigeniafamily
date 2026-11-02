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
 * The personal link to a request for consent, for an adult with no account: it opens the request and lets them answer
 * without signing in. It is signed with a secret of the site, so it cannot be made up or changed to another adult or
 * another request, and it only stands for what it says: whoever had the message answered.
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class consent_link {
    /** @var string[] Where an adult's link was sent: by e-mail, or as the button of a message (WhatsApp, Telegram). */
    public const SOURCES = ['email', 'link'];

    /**
     * The token of the link.
     *
     * @param int $requestid The request.
     * @param int $adultid The adult.
     * @param string $source Where it is sent (one of self::SOURCES).
     * @return string
     */
    public static function token(int $requestid, int $adultid, string $source): string {
        if (!in_array($source, self::SOURCES, true)) {
            throw new \coding_exception('Unknown source of a link');
        }

        return $requestid . '-' . $adultid . '-' . $source . '-' . self::sign($requestid, $adultid, $source);
    }

    /**
     * The link.
     *
     * @param int $requestid The request.
     * @param int $adultid The adult.
     * @param string $source Where it is sent (one of self::SOURCES).
     * @return \moodle_url
     */
    public static function url(int $requestid, int $adultid, string $source): \moodle_url {
        return new \moodle_url('/local/oksigeniafamily/answer.php', ['t' => self::token($requestid, $adultid, $source)]);
    }

    /**
     * The link of a student's family behind another plugin's key of that student, such as the canteen's page of a family
     * with only its key and PIN: the key is the student's, not one adult's, so the page asks who answers.
     *
     * @param int $requestid The request.
     * @param int $childuserid The student.
     * @param int $keyid The id of the key in the plugin that shows the link, kept with the answer as what it was answered
     *                   with (never the key itself, which is secret).
     * @return \moodle_url
     */
    public static function key_url(int $requestid, int $childuserid, int $keyid): \moodle_url {
        $source = 'key' . $keyid;

        return new \moodle_url('/local/oksigeniafamily/answer.php', [
            't' => $requestid . '-' . $childuserid . '-' . $source . '-' . self::sign($requestid, $childuserid, $source),
        ]);
    }

    /**
     * What a token stands for, if it is one of ours.
     *
     * @param string $token The token.
     * @return array|null requestid, source (email, link or key), and adultid, or childuserid and keyid for the link of a
     *                    student's key; null if it is not valid.
     */
    public static function read(string $token): ?array {
        if (!preg_match('/^(\d{1,10})-(\d{1,10})-(email|link|key\d{1,10})-([0-9a-f]{32})$/', $token, $parts)) {
            return null;
        }
        [, $requestid, $subject, $source, $signature] = $parts;
        if (!hash_equals(self::sign((int) $requestid, (int) $subject, $source), $signature)) {
            return null;
        }
        if (str_starts_with($source, 'key')) {
            return [
                'requestid' => (int) $requestid,
                'source' => 'key',
                'childuserid' => (int) $subject,
                'keyid' => (int) substr($source, 3),
            ];
        }

        return ['requestid' => (int) $requestid, 'adultid' => (int) $subject, 'source' => $source];
    }

    /**
     * The signature of a link.
     *
     * @param int $requestid The request.
     * @param int $subject The adult, or the student for the link of a student's key.
     * @param string $source Where it is sent.
     * @return string
     */
    private static function sign(int $requestid, int $subject, string $source): string {
        return substr(hash_hmac('sha256', "consent|$requestid|$subject|$source", self::secret()), 0, 32);
    }

    /**
     * The secret of the site for these links, made the first time it is needed.
     *
     * @return string
     */
    private static function secret(): string {
        $secret = (string) get_config('local_oksigeniafamily', 'consentsecret');
        if (strlen($secret) < 64) {
            $secret = bin2hex(random_bytes(32));
            set_config('consentsecret', $secret, 'local_oksigeniafamily');
        }

        return $secret;
    }
}
