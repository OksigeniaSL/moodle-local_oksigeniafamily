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
 * A reader of the public API of Cifra, a school management program: an optional connector, next to OneRoster or a sheet,
 * never the only way in. It only reads (GET), and keeps nothing: keeping is up to each plugin.
 *
 * Two ways in. Straight to Cifra with the centre's own client and secret (OAuth2, client credentials), each call asking
 * for exactly the area it reads; or through a bridge that holds those and gives a key (header X-Puente-Llave), which
 * may live on the server's own network. Cifra does not page in a stable order (between pages it repeats some and skips
 * others), so straight to it a list is read again, joined by id, until two rounds in a row bring nobody new; the bridge
 * does that itself.
 *
 * Shared by the plugins of Oksigenia: the original lives in moodle-tools/comun and each plugin carries a copy in its own
 * namespace (comun/copia.sh). The administrator sets the connection once: a plugin without its own uses the one of the
 * first plugin of Oksigenia that has one (settings «cifraurl», «ciframode», «cifraclientid», «cifrasecret», «cifrakey»
 * and «cifrainternalhosts»).
 *
 * @package    local_oksigeniafamily
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cifra {
    /** @var string Cifra's public API, when the address is left empty. */
    const DEFAULTURL = 'https://app.cifraeducacion.com/api_publica';

    /** @var string The header that carries the key of a bridge. */
    const KEYHEADER = 'X-Puente-Llave';

    /** @var string[] The settings of the connection, in any plugin of Oksigenia. */
    const SETTINGS = ['cifraurl', 'ciframode', 'cifraclientid', 'cifrasecret', 'cifrakey', 'cifrainternalhosts'];

    /** @var int[] The most Cifra gives in a page, by area; 500 in any other. */
    const PAGESIZES = ['alumnos' => 100];

    /** @var int The most Cifra gives in a page of any other area. */
    const PAGESIZE = 500;

    /** @var int Rounds over a list at most, straight to Cifra, before giving up on it settling. */
    const ROUNDS = 10;

    /** @var int Pages in a round at most: a list that never ends is not a list. */
    const PAGES = 400;

    /** @var int Tries of a request when there is no answer or Cifra is busy (429, 5xx). */
    const TRIES = 3;

    /** @var int Seconds a whole list may take in the direct way, all its rounds together: a task of cron does not hang. */
    const BUDGET = 600;

    /** @var int Seconds to wait for an answer: the bridge may be joining rounds of Cifra first. */
    const TIMEOUT = 120;

    /** @var int Seconds to wait for the connection. */
    const CONNECTTIMEOUT = 10;

    /** @var int Seconds a token is kept less than Cifra says, so it never expires on the way. */
    const MARGIN = 120;

    /** @var string[] What each failure says when the plugin has no string «cifraerror<code>» of its own. */
    const FAILURES = [
        'settings' => 'Cifra: the connection is not complete (its address, and the client and secret or the bridge key).',
        'blocked' => 'Cifra: Moodle\'s HTTP security blocks the address. If it is a bridge on this server\'s own network, '
            . 'add its host to the internal hosts of the connection.',
        'unreachable' => 'Cifra: there is no answer from the address (no connection, or it took too long).',
        'credentials' => 'Cifra did not accept the client and secret of the centre.',
        'unknownarea' => 'Cifra does not know the area «{$a}».',
        'notgranted' => 'Cifra has not granted the area «{$a}» to this client: whoever manages Cifra at the centre has to '
            . 'grant it.',
        'notintoken' => 'The permission asked of Cifra does not cover «{$a}».',
        'bridgekey' => 'The bridge to Cifra does not accept the key.',
        'bridgeexample' => 'The key of the bridge to Cifra does not open «{$a}»: it may be a key for its example data only '
            . '(their address ends in /ejemplo).',
        'status' => 'Cifra answered with an error (HTTP {$a}).',
        'answer' => 'Cifra answered something that is not what was expected.',
        'incomplete' => 'Cifra did not give the whole list «{$a}» the same way twice in a row: nothing was taken from it.',
    ];

    /** @var int Seconds to wait between tries, times the try (none in the tests). */
    public static int $wait = 2;

    /** @var string oauth (straight to Cifra) or key (through a bridge). */
    protected string $mode;

    /** @var string Base address, without a slash at the end. */
    protected string $url;

    /** @var string The centre's client at Cifra. */
    protected string $clientid;

    /** @var string Its secret. */
    protected string $secret;

    /** @var string The key of the bridge. */
    protected string $key;

    /** @var string[] Hosts of the server's own network the connection may reach, in lower case. */
    protected array $internalhosts;

    /**
     * A connection to Cifra. Nothing is checked here: ready() says whether it can be used.
     *
     * @param string $mode oauth (straight to Cifra) or key (through a bridge).
     * @param string $url Base address: Cifra's public API (empty: DEFAULTURL), or the bridge's.
     * @param string $clientid The centre's client at Cifra (oauth).
     * @param string $secret Its secret (oauth).
     * @param string $key The key of the bridge (key).
     * @param string[] $internalhosts Hosts of the server's own network that may be reached (a bridge inside it).
     */
    public function __construct(
        string $mode,
        string $url = '',
        string $clientid = '',
        string $secret = '',
        string $key = '',
        array $internalhosts = []
    ) {
        $this->mode = trim($mode);
        $this->url = rtrim(trim($url), '/');
        if ($this->url === '' && $this->mode === 'oauth') {
            $this->url = self::DEFAULTURL;
        }
        $this->clientid = trim($clientid);
        $this->secret = trim($secret);
        $this->key = trim($key);
        $this->internalhosts = array_values(array_filter(array_map(fn($host) => strtolower(trim((string) $host)), $internalhosts)));
    }

    /**
     * The connection of this site, or null when there is none ready: the one of this plugin, or else the one of the
     * first plugin of Oksigenia that has one, so the administrator sets it once for all of them. All its settings come
     * from the same plugin.
     *
     * @return self|null
     */
    public static function of_site(): ?self {
        global $DB;
        $own = self::own();
        $sets = [$own => []];
        foreach (self::SETTINGS as $name) {
            $sets[$own][$name] = (string) get_config($own, $name);
        }
        [$in, $params] = $DB->get_in_or_equal(self::SETTINGS, SQL_PARAMS_NAMED);
        $others = $DB->get_records_select(
            'config_plugins',
            "name $in AND " . $DB->sql_like('plugin', ':oks'),
            $params + ['oks' => '%oksigenia%'],
            'plugin, name',
            'id, plugin, name, value'
        );
        foreach ($others as $setting) {
            if ($setting->plugin !== $own) {
                $sets[$setting->plugin][$setting->name] = (string) $setting->value;
            }
        }
        foreach ($sets as $set) {
            $key = self::reveal($set['cifrakey'] ?? '');
            $clientid = trim($set['cifraclientid'] ?? '');
            $mode = trim($set['ciframode'] ?? '') ?: ($key !== '' && $clientid === '' ? 'key' : 'oauth');
            $hosts = preg_split('/[\s,]+/', (string) ($set['cifrainternalhosts'] ?? ''));
            $client = new self($mode, $set['cifraurl'] ?? '', $clientid, self::reveal($set['cifrasecret'] ?? ''), $key, $hosts);
            if ($client->ready()) {
                return $client;
            }
        }
        return null;
    }

    /**
     * Whether it can be used: a known way in, its credentials, and an address by https, or on a host of the server's own
     * network that the administrator allowed.
     *
     * @return bool
     */
    public function ready(): bool {
        $parts = parse_url($this->url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $address = $host !== '' && !isset($parts['user']) && !isset($parts['query']) && !isset($parts['fragment'])
            && ($scheme === 'https' || ($scheme === 'http' && in_array($host, $this->internalhosts, true)));
        if ($this->mode === 'oauth') {
            return $address && $this->clientid !== '' && $this->secret !== '';
        }
        return $this->mode === 'key' && $address && $this->key !== '';
    }

    /**
     * How it reaches Cifra.
     *
     * @return string oauth (straight) or key (through a bridge).
     */
    public function mode(): string {
        return $this->mode;
    }

    /**
     * Everyone in Cifra's list of people (students, families, staff…), each once, by their id in Cifra and in its order.
     *
     * @param array $query Filters of the list, if any.
     * @return array[] As Cifra gives them, keyed by id.
     * @throws \moodle_exception When the list cannot be read whole.
     */
    public function people(array $query = []): array {
        return $this->all('alumnos', $query);
    }

    /**
     * Every item of a list of Cifra, each once, keyed by its id and in its order: the whole list or an exception, never
     * part of it, so that «what is no longer there» can be trusted.
     *
     * @param string $area What to read, as in its address after /v1/: alumnos, alumnos_asistencias,
     *     gestion_economica/suscripciones… Its permission is the same with «:» and «:read» (alumnos:read).
     * @param array $query Its filters (alumnos_asistencias needs fecha; gestion_economica/suscripciones, servicio_id,
     *     modalidad_id or alumno_id).
     * @return array[] Keyed by id.
     * @throws \moodle_exception When it cannot be read whole.
     */
    public function all(string $area, array $query = []): array {
        $this->check($area);
        if ($this->mode === 'key') {
            // The bridge joins Cifra's rounds itself and gives the whole list at once.
            [$list, $headers] = $this->list($area, $this->address($area, $query + ['todos' => 1]));
            $total = null;
            foreach ($headers as $name => $value) {
                if (strtolower(trim((string) $name)) === 'x-total-count') {
                    $total = (int) $value;
                }
            }
            if ($total !== null && $total !== count($list)) {
                throw $this->fail('incomplete', $area);
            }
            $all = self::by_id($list);
            ksort($all, SORT_NATURAL);
            return $all;
        }
        $size = self::PAGESIZES[$area] ?? self::PAGESIZE;
        $all = [];
        $quiet = 0;
        $until = time() + self::BUDGET;
        for ($round = 1; $round <= self::ROUNDS && $quiet < 2; $round++) {
            $before = count($all);
            for ($page = 1; $page <= self::PAGES; $page++) {
                if (time() > $until) {
                    // Too long for a list that should take a minute: better nothing than part of it.
                    throw $this->fail('incomplete', $area);
                }
                [$list] = $this->list($area, $this->address($area, $query + ['per_page' => $size, 'page' => $page]));
                $all = self::by_id($list) + $all;
                if (count($list) < $size) {
                    break;
                }
            }
            if ($page > self::PAGES) {
                throw $this->fail('incomplete', $area);
            }
            $quiet = $round > 1 && count($all) === $before ? $quiet + 1 : 0;
        }
        if ($quiet < 2) {
            // Still bringing new ones after every round: it may be missing someone, so it is not given as the list.
            throw $this->fail('incomplete', $area);
        }
        ksort($all, SORT_NATURAL);
        return $all;
    }

    /**
     * One answer of Cifra as it comes: an item (alumnos with its id, with the family), or a single page of a list.
     *
     * @param string $area As in all().
     * @param array $query Its filters.
     * @param string|null $id The id of one item.
     * @return array|null What it answered, or null when there is no such item.
     * @throws \moodle_exception When it does not answer well.
     */
    public function get(string $area, array $query = [], ?string $id = null): ?array {
        $this->check($area);
        if ($id !== null && !preg_match('/^\d+$/', $id)) {
            throw new \coding_exception('Cifra: an id is a number');
        }
        $path = $area . ($id !== null ? '/' . $id : '');
        [$answer] = $this->read($area, $this->address($path, $query), true);
        return $answer;
    }

    /**
     * Those who have not left by now: without a «fecha_baja», or with one still to come.
     *
     * @param array[] $people As Cifra gives them.
     * @param int|null $now
     * @return array[] The same keys.
     */
    public static function present(array $people, ?int $now = null): array {
        $now = $now ?? time();
        return array_filter($people, function (array $person) use ($now): bool {
            $left = self::timestamp(isset($person['fecha_baja']) ? (string) $person['fecha_baja'] : null);
            return $left === null || $left > $now;
        });
    }

    /**
     * What changed since last time, to bring all and compare (Cifra tells nobody of its changes). Each item is known by a
     * fingerprint of its fields: a plugin keeps those, not the data, and passes them back the next time.
     *
     * @param string[] $known Fingerprints of last time, by id (the «fingerprints» given then).
     * @param array[] $items Now, by id (as all() gives them; through present() if those who left count as gone).
     * @param string[] $fields The fields that matter (empty: all of them).
     * @return array new, changed and gone (ids, as strings), and fingerprints (by id) to keep for next time.
     */
    public static function changes(array $known, array $items, array $fields = []): array {
        $prints = [];
        foreach ($items as $id => $item) {
            $picked = $fields ? array_intersect_key((array) $item, array_flip($fields)) : (array) $item;
            $prints[(string) $id] = sha1(json_encode(self::sorted($picked), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $new = $changed = [];
        foreach ($prints as $id => $print) {
            if (!array_key_exists($id, $known)) {
                $new[] = (string) $id;
            } else if ((string) $known[$id] !== $print) {
                $changed[] = (string) $id;
            }
        }
        $gone = array_map('strval', array_keys(array_diff_key($known, $prints)));
        return ['new' => $new, 'changed' => $changed, 'gone' => $gone, 'fingerprints' => $prints];
    }

    /**
     * A date of Cifra in the centre's time zone. Cifra gives moments in mainland Spain's time (+01:00 or +02:00); a date
     * without a time, such as «fecha_nacimiento», stays that date.
     *
     * @param string|null $value As Cifra gives it.
     * @param \DateTimeZone|null $zone The centre's; by default, the server's (Moodle's). A site with centres apart, each in
     *     its own zone, passes the centre's.
     * @return string|null 2026-10-15T14:24:00+01:00, or 2010-05-03 for a date alone; null when empty or not a date.
     */
    public static function local(?string $value, ?\DateTimeZone $zone = null): ?string {
        $value = trim((string) $value);
        if (self::day($value)) {
            return $value;
        }
        $moment = self::moment($value);
        $zone = $zone ?? \core_date::get_server_timezone_object();
        return $moment ? $moment->setTimezone($zone)->format('Y-m-d\TH:i:sP') : null;
    }

    /**
     * A date of Cifra as a moment: the exact one, or the start of a date alone in the centre's time zone.
     *
     * @param string|null $value As Cifra gives it.
     * @param \DateTimeZone|null $zone The centre's, for a date alone; by default, the server's (Moodle's).
     * @return int|null Unix time, or null when empty or not a date.
     */
    public static function timestamp(?string $value, ?\DateTimeZone $zone = null): ?int {
        $value = trim((string) $value);
        if (self::day($value)) {
            $zone = $zone ?? \core_date::get_server_timezone_object();
            return (new \DateTimeImmutable($value . ' 00:00:00', $zone))->getTimestamp();
        }
        $moment = self::moment($value);
        return $moment ? $moment->getTimestamp() : null;
    }

    /**
     * Whether a value is a date alone, and a real one.
     *
     * @param string $value
     * @return bool
     */
    protected static function day(string $value): bool {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * A moment with its offset, as Cifra gives it; one without an offset is in mainland Spain's time.
     *
     * @param string $value
     * @return \DateTimeImmutable|null
     */
    protected static function moment(string $value): ?\DateTimeImmutable {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?$/', $value)) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('Europe/Madrid'));
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Keys in order all the way down, so the same item always gives the same fingerprint.
     *
     * @param array $value
     * @return array
     */
    protected static function sorted(array $value): array {
        ksort($value);
        foreach ($value as $key => $inner) {
            if (is_array($inner)) {
                $value[$key] = self::sorted($inner);
            }
        }
        return $value;
    }

    /**
     * The items of a list by their id; one without an id is left out.
     *
     * @param array $list
     * @return array[]
     */
    protected static function by_id(array $list): array {
        $byid = [];
        foreach ($list as $item) {
            if (is_array($item) && isset($item['id']) && is_scalar($item['id']) && (string) $item['id'] !== '') {
                $byid[$item['id']] = $item;
            }
        }
        return $byid;
    }

    /**
     * That it can be used, and that the area is a name of an area.
     *
     * @param string $area
     */
    protected function check(string $area): void {
        if (!preg_match('#^[a-z_]+(/[a-z_]+)*$#', $area)) {
            throw new \coding_exception('Cifra: an area is a path such as alumnos or gestion_economica/suscripciones');
        }
        if (!$this->ready()) {
            throw $this->fail('settings');
        }
    }

    /**
     * The address of a path, with its filters joined by a plain «&» (Moodle sets «&amp;» for pages).
     *
     * @param string $path After /v1/.
     * @param array $query
     * @return string
     */
    protected function address(string $path, array $query): string {
        $query = array_filter($query, fn($value) => $value !== null && $value !== '');
        return $this->url . '/v1/' . $path . ($query ? '?' . http_build_query($query, '', '&') : '');
    }

    /**
     * One page of a list.
     *
     * @param string $area
     * @param string $url
     * @return array The items, and the headers of the answer.
     */
    protected function list(string $area, string $url): array {
        [$answer, $headers] = $this->read($area, $url, false);
        $list = isset($answer['data']) && is_array($answer['data']) ? $answer['data'] : $answer;
        if (!is_array($list) || !array_is_list($list)) {
            throw $this->fail('answer', null, $area);
        }
        return [$list, $headers];
    }

    /**
     * One GET to Cifra or its bridge, with its credentials: straight, with a token for exactly the area's permission,
     * and a new one once if Cifra no longer takes it.
     *
     * @param string $area
     * @param string $url
     * @param bool $missing Whether «there is no such item» (404) is an answer, null.
     * @return array What it answered (null when missing) and the headers.
     */
    protected function read(string $area, string $url, bool $missing): array {
        if ($this->mode === 'key') {
            [$code, $body, $headers] = $this->send('GET', $url, [self::KEYHEADER . ': ' . $this->key]);
            if ($code === 401) {
                throw $this->fail('bridgekey', null, 'HTTP 401');
            }
            if ($code === 403) {
                throw $this->fail('bridgeexample', $area, 'HTTP 403');
            }
        } else {
            $scope = str_replace('/', ':', $area) . ':read';
            for ($fresh = false;; $fresh = true) {
                [$code, $body, $headers] = $this->send('GET', $url, ['Authorization: Bearer ' . $this->token($scope, $fresh)]);
                if ($code !== 401 || $fresh) {
                    break;
                }
            }
            if ($code === 401) {
                throw $this->fail('notgranted', $area, 'HTTP 401');
            }
            if ($code === 403) {
                throw $this->fail('notintoken', $area, 'HTTP 403');
            }
        }
        if ($code === 404 && $missing) {
            return [null, $headers];
        }
        if ($code !== 200) {
            throw $this->fail('status', $code, 'HTTP ' . $code . ' ' . $area);
        }
        $answer = json_decode($body, true);
        if (!is_array($answer)) {
            throw $this->fail('answer', null, $area);
        }
        return [$answer, $headers];
    }

    /**
     * A token of Cifra for a permission: the one kept while it lasts, or a new one.
     *
     * @param string $scope Exactly the permission of the call (alumnos:read).
     * @param bool $fresh A new one, because Cifra no longer took the one kept.
     * @return string
     */
    protected function token(string $scope, bool $fresh): string {
        $cache = \cache::make_from_params(
            \cache_store::MODE_APPLICATION,
            self::own(),
            'cifratoken',
            [],
            ['simplekeys' => true, 'simpledata' => true]
        );
        $name = sha1($this->url . '|' . $this->clientid . '|' . $scope);
        $kept = $fresh ? false : $cache->get($name);
        if (is_string($kept) && $kept !== '') {
            try {
                $kept = json_decode(\core\encryption::decrypt($kept), true);
            } catch (\Throwable $e) {
                $kept = null;
            }
            if (is_array($kept) && ($kept['until'] ?? 0) > time() && ($kept['token'] ?? '') !== '') {
                return $kept['token'];
            }
        }
        $fields = ['grant_type' => 'client_credentials', 'client_id' => $this->clientid, 'client_secret' => $this->secret,
            'scope' => $scope];
        [$code, $body] = $this->send(
            'POST',
            $this->url . '/oauth/token',
            ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query($fields, '', '&')
        );
        $answer = json_decode($body, true);
        $answer = is_array($answer) ? $answer : [];
        $error = (string) ($answer['error'] ?? '');
        if ($error === 'invalid_scope') {
            throw $this->fail('unknownarea', $scope, 'HTTP ' . $code . ' invalid_scope');
        }
        // A bad client or secret is a 401 here, whatever its error says (Cifra, 7-11-2026); an area not granted is a 401
        // later, when reading it.
        $token = (string) ($answer['access_token'] ?? '');
        if ($code !== 200 || $token === '') {
            throw $this->fail('credentials', null, 'HTTP ' . $code . ' ' . $error);
        }
        // Two hours at most, as Cifra gives them, and a little less, so it never expires on the way.
        $lasts = min((int) ($answer['expires_in'] ?? 7200) ?: 7200, 7200) - self::MARGIN;
        $cache->set($name, \core\encryption::encrypt(json_encode(['token' => $token, 'until' => time() + $lasts])));
        return $token;
    }

    /**
     * One request, tried again when there is no answer or Cifra is busy. Moodle's HTTP security stands, except for a
     * host of the server's own network that the administrator allowed.
     *
     * @param string $method GET, or POST only for a token.
     * @param string $url
     * @param string[] $headers
     * @param string $body
     * @return array HTTP code, body and headers of the answer.
     */
    protected function send(string $method, string $url, array $headers, string $body = ''): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        for ($try = 1;; $try++) {
            $curl = $this->curl($host !== '' && in_array($host, $this->internalhosts, true));
            $curl->setHeader(array_merge(['Accept: application/json'], $headers));
            // Neither Cifra nor a bridge redirects: no redirection is followed, least of all from an allowed host of the
            // server's own network, where Moodle's HTTP security does not look.
            $curl->setopt(['CURLOPT_TIMEOUT' => self::TIMEOUT, 'CURLOPT_CONNECTTIMEOUT' => self::CONNECTTIMEOUT,
                'CURLOPT_FOLLOWLOCATION' => 0, 'CURLOPT_MAXREDIRS' => 0]);
            $answer = $method === 'POST' ? $curl->post($url, $body) : $curl->get($url);
            $code = (int) ($curl->get_info()['http_code'] ?? 0);
            $errno = (int) $curl->get_errno();
            if ($code === 0 && $errno === 0) {
                // No request went out: Moodle's HTTP security stopped it.
                throw $this->fail('blocked', null, $host);
            }
            if (($errno || in_array($code, [429, 500, 502, 503, 504], true)) && $try < self::TRIES) {
                sleep(self::$wait * $try);
                continue;
            }
            if ($errno) {
                throw $this->fail('unreachable', null, 'cURL ' . $errno);
            }
            return [$code, (string) $answer, (array) $curl->getResponse()];
        }
    }

    /**
     * A client of Moodle for one request.
     *
     * @param bool $internal Whether it goes to a host of the server's own network that the administrator allowed.
     * @return \curl
     */
    protected function curl(bool $internal): \curl {
        return new \curl(['ignoresecurity' => $internal]);
    }

    /**
     * The exception of a failure, with the plugin's own words for it when it has them («cifraerror<code>»), or else
     * these. It never carries an address with its filters, a key, a secret or what Cifra answered.
     *
     * @param string $code A key of FAILURES.
     * @param mixed $a What goes in its words (an area, an HTTP code).
     * @param string|null $debug For whoever debugs: codes only.
     * @return \moodle_exception
     */
    protected function fail(string $code, $a = null, ?string $debug = null): \moodle_exception {
        $own = self::own();
        if (get_string_manager()->string_exists('cifraerror' . $code, $own)) {
            return new \moodle_exception('cifraerror' . $code, $own, '', $a, $debug);
        }
        $words = str_replace('{$a}', (string) $a, self::FAILURES[$code]);
        return new \moodle_exception('generalexceptionmessage', 'error', '', $words, $debug);
    }

    /**
     * A secret as set: in the clear, or encrypted by Moodle (admin_setting_encryptedpassword). One that cannot be opened
     * (another data folder) is empty, so the connection is not ready and is asked for again.
     *
     * @param string $value
     * @return string
     */
    protected static function reveal(string $value): string {
        $value = trim($value);
        if (!preg_match('/^(sodium|openssl):/', $value)) {
            return $value;
        }
        try {
            return trim(\core\encryption::decrypt($value));
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * The plugin of this copy: its namespace starts with its name («local_oksigeniahechauka\local»).
     *
     * @return string
     */
    protected static function own(): string {
        return explode('\\', __NAMESPACE__)[0];
    }
}
