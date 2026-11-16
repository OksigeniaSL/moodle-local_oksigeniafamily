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
 * Short links through a link shortener that speaks the API of YOURLS (YOURLS itself, or another service compatible with
 * it): a long address in, a short one out, and its destination changed when the long one is renewed. Always by POST, so
 * the key never travels in an address.
 *
 * Shared by the plugins of Oksigenia: the original lives in moodle-tools/comun and each plugin carries a copy in its own
 * namespace (comun/copia.sh). The administrator sets the shortener once: a plugin without its own address and key uses
 * those of the first plugin of Oksigenia that has them (settings «shortenerapi» and «shortenerkey»).
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class shortener {
    /** @var int Seconds to wait for the shortener: a page never waits long for a nicety. */
    const TIMEOUT = 4;

    /** @var int Seconds to wait before trying again when it says it is going too fast (none in the tests). */
    public static int $wait = 15;

    /** @var int How many times to try again then: a minute in all, what a limit per minute needs. */
    const RETRIES = 5;

    /** @var string[] What the shortener answers when asking again would get the same: a destination it never takes (out
     *     of its domains, or not an address), its limit of links reached, an address that is not this program's or does
     *     not exist (an update answered 404, said «error:notfound» here). A
     *     plugin that sees one of these does not ask again for that destination for a long while. */
    const FINAL = ['error:domain', 'error:nourl', 'error:max', 'error:notyours', 'error:notfound'];

    /** @var string The code of the last answer: '' when it went well or there was no answer; «inactive» when an update
     *     was kept but left the address paused or expired. */
    protected string $code = '';

    /** @var int Seconds the last answer asked to wait (its Retry-After), or 0. */
    protected int $retryafter = 0;

    /** @var string Address of its API (yourls-api.php). */
    protected string $api;

    /** @var string The key of this program (the «signature» of YOURLS). */
    protected string $key;

    /**
     * A shortener with its address and key.
     *
     * @param string $api Address of its API.
     * @param string $key The key of this program.
     */
    public function __construct(string $api, string $key) {
        $this->api = trim($api);
        $this->key = trim($key);
    }

    /**
     * The shortener of this site, or null when it has none: the one of this plugin, or else the one of the first plugin of
     * Oksigenia that has one, so the administrator sets it once for all of them. Only by https.
     *
     * @return self|null
     */
    public static function of_site(): ?self {
        global $DB;
        // The plugin of this copy: its namespace starts with its name («local_oksigeniameals\local»).
        $own = explode('\\', __NAMESPACE__)[0];
        $usable = fn(string $api, string $key) => trim($api) !== '' && trim($key) !== '' && preg_match('#^https://#i', trim($api));
        $api = (string) get_config($own, 'shortenerapi');
        $key = (string) get_config($own, 'shortenerkey');
        if ($usable($api, $key)) {
            return new self($api, $key);
        }
        $others = $DB->get_records_select(
            'config_plugins',
            "name IN ('shortenerapi', 'shortenerkey') AND " . $DB->sql_like('plugin', ':oks'),
            ['oks' => '%oksigenia%'],
            'plugin, name'
        );
        $by = [];
        foreach ($others as $setting) {
            $by[$setting->plugin][$setting->name] = (string) $setting->value;
        }
        foreach ($by as $plugin => $settings) {
            if ($usable($settings['shortenerapi'] ?? '', $settings['shortenerkey'] ?? '')) {
                return new self($settings['shortenerapi'], $settings['shortenerkey']);
            }
        }
        return null;
    }

    /**
     * Whether an address is already one of this shortener's (the host of its API): it is not shortened again.
     *
     * @param string $url
     * @return bool
     */
    public function owns(string $url): bool {
        $host = strtolower((string) parse_url($this->api, PHP_URL_HOST));
        return $host !== '' && strtolower((string) parse_url($url, PHP_URL_HOST)) === $host;
    }

    /**
     * The short address of a long one: a new one, or the one it already had.
     *
     * @param string $url The long address.
     * @param string $title What it is, for whoever looks at the links of the shortener.
     * @param bool $patient Whether to wait and try again when it says it is going too fast (many at once).
     * @param string $keyword A word asked for («tele», to type it with a remote); when it is taken, the one it gives.
     * @return string|null The short address, or null when the shortener did not give one.
     */
    public function shorten(string $url, string $title = '', bool $patient = false, string $keyword = ''): ?string {
        $link = $this->link($url, $title, $patient, $keyword);
        return $link ? $link['short'] : null;
    }

    /**
     * The short link of a long address, with what the shortener says of it: the short address, its keyword and, when the
     * shortener draws its QR codes (as nur.click does), the address of the one of this link. A YOURLS that does not draw
     * them gives no «qrsvg»: the plugin draws its own.
     *
     * @param string $url The long address.
     * @param string $title What it is, for whoever looks at the links of the shortener.
     * @param bool $patient Whether to wait and try again when it says it is going too fast (many at once).
     * @param string $keyword A word asked for; when it is taken, the one it gives.
     * @return array|null ['short' => string, 'keyword' => string, 'qrsvg' => string], '' for what it did not say; null when
     *     the shortener gave no short address.
     */
    public function link(string $url, string $title = '', bool $patient = false, string $keyword = ''): ?array {
        $fields = ['action' => 'shorturl', 'url' => $url, 'title' => \core_text::substr($title, 0, 200)];
        if (trim($keyword) !== '') {
            $answer = $this->call($fields + ['keyword' => trim($keyword)]);
            if (($answer['code'] ?? '') !== 'error:keyword') {
                return self::link_of($answer);
            }
        }
        $answer = $this->call($fields);
        for ($try = 0; $patient && ($answer['code'] ?? '') === 'error:limit' && $try < self::RETRIES; $try++) {
            // As long as it asked (its Retry-After), a minute at most; none in the tests.
            sleep(self::$wait ? max(1, min(60, $this->retryafter ?: self::$wait)) : 0);
            $answer = $this->call($fields);
        }
        return self::link_of($answer);
    }

    /**
     * The link in an answer: its short address, its keyword and the address of its QR, if the shortener draws them.
     *
     * @param array $answer What the shortener answered.
     * @return array|null
     */
    protected static function link_of(array $answer): ?array {
        $short = self::short_of($answer);
        if ($short === null) {
            return null;
        }
        $keyword = is_array($answer['url'] ?? null) ? (string) ($answer['url']['keyword'] ?? '') : '';
        $qr = (string) ($answer['qrsvg'] ?? '');
        return ['short' => $short, 'keyword' => $keyword, 'qrsvg' => preg_match('#^https://\S+$#', $qr) ? $qr : ''];
    }

    /**
     * The short address in an answer: created, or already there with the same long address.
     *
     * @param array $answer What the shortener answered.
     * @return string|null
     */
    protected static function short_of(array $answer): ?string {
        $short = (string) ($answer['shorturl'] ?? '');
        $ok = ($answer['status'] ?? '') === 'success' || ($answer['code'] ?? '') === 'error:url';
        return $ok && preg_match('#^https?://\S+$#', $short) ? $short : null;
    }

    /**
     * Send a short address to a new long one, when the long one was renewed.
     *
     * @param string $short The short address (or its name).
     * @param string $url The new long address.
     * @param string $title What it is.
     * @return bool Whether it changed and opens: false when the shortener refused (not this program's any more, 403
     *     «error:notyours»; gone, 404) or kept it paused or expired («active»: false), when the short address no longer
     *     takes anyone to the new long one.
     */
    public function update(string $short, string $url, string $title = ''): bool {
        $answer = $this->call(['action' => 'update', 'shorturl' => $short, 'url' => $url,
            'title' => \core_text::substr($title, 0, 200)]);
        if (!str_starts_with((string) ($answer['message'] ?? ''), 'success')) {
            // An address that does not exist is answered with a message and a 404, not with a code: said as one.
            $notfound = (int) ($answer['statusCode'] ?? 0) === 404
                || str_contains(strtolower((string) ($answer['message'] ?? '')), 'not found');
            if ($this->code === '' && $notfound) {
                $this->code = 'error:notfound';
            }
            return false;
        }
        if (($answer['active'] ?? true) === false) {
            $this->code = 'inactive';
            return false;
        }
        return true;
    }

    /**
     * Why the last call did not give what was asked: the code the shortener answered («error:limit», «error:domain»,
     * «error:notyours»…), «inactive» for an update left paused, or '' when it went well or there was no answer (the
     * shortener was down or too slow: worth asking again later).
     *
     * @return string
     */
    public function last_code(): string {
        return $this->code;
    }

    /**
     * Whether the last refusal is for good: asking again for the same destination would get the same (see FINAL), so a
     * plugin keeps from asking for it for a long while instead of spending the limit that all of them share.
     *
     * @return bool
     */
    public function is_final(): bool {
        return in_array($this->code, self::FINAL, true);
    }

    /**
     * One call to the API: by POST, as a form, with the key and asking for JSON.
     *
     * @param array $fields What to send.
     * @return array What it answered, or an empty array when it did not answer.
     */
    protected function call(array $fields): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl(['ignoresecurity' => false]);
        $curl->setopt(['CURLOPT_TIMEOUT' => self::TIMEOUT, 'CURLOPT_CONNECTTIMEOUT' => self::TIMEOUT]);
        self::cap($curl, 64 * 1024);
        $curl->setHeader(['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json']);
        $this->code = '';
        $this->retryafter = 0;
        try {
            $body = $curl->post($this->api, http_build_query($fields + ['signature' => $this->key, 'format' => 'json'], '', '&'));
        } catch (\Throwable $error) {
            debugging('Shortener: ' . $error->getMessage(), DEBUG_DEVELOPER);
            return [];
        }
        $answer = json_decode((string) $body, true);
        $answer = is_array($answer) ? $answer : [];
        // An address already there for the same destination («error:url» with its short address) went well too.
        $fine = ($answer['status'] ?? '') === 'success' || (($answer['code'] ?? '') === 'error:url' && !empty($answer['shorturl']));
        $this->code = $fine ? '' : (string) ($answer['code'] ?? '');
        foreach ((array) $curl->getResponse() as $name => $value) {
            if (strtolower((string) $name) === 'retry-after' && is_numeric(trim((string) $value))) {
                $this->retryafter = (int) trim((string) $value);
            }
        }
        return $answer;
    }

    /**
     * What a service outside may send back, at most: the download stops there, whether it says its size or not, and it
     * is not taken round more than two redirections.
     *
     * @param \curl $curl The request.
     * @param int $bytes The most it may send.
     */
    public static function cap(\curl $curl, int $bytes): void {
        $curl->setopt([
            'CURLOPT_MAXFILESIZE' => $bytes,
            'CURLOPT_MAXREDIRS' => 2,
            'CURLOPT_NOPROGRESS' => false,
            'CURLOPT_PROGRESSFUNCTION' => fn($handle, $total, $now) => $now > $bytes ? 1 : 0,
        ]);
    }
}
