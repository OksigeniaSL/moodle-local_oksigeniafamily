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
 * Tests for the reader of Cifra's public API, without a network: answers from \curl::mock_response(), made-up people.
 * The last one runs against the example data of a bridge when it is given one (CIFRA_PUENTE_PRUEBA_URL, ending in
 * /ejemplo, and CIFRA_PUENTE_PRUEBA_LLAVE in the environment).
 *
 * @package    local_oksigeniafamily
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniafamily\local\cifra
 */
#[\PHPUnit\Framework\Attributes\CoversClass(cifra::class)]
final class cifra_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->libdir . '/filelib.php');
        $this->resetAfterTest();
        cifra::$wait = 0;
    }

    #[\Override]
    protected function tearDown(): void {
        cifra::$wait = 2;
        parent::tearDown();
    }

    /**
     * A connection whose answers come from \curl::mock_response(), each with its HTTP code in turn (a mocked answer is
     * always 200; 0 is no answer at all), and that tells what it asked.
     *
     * @param string $mode
     * @param string $url
     * @param string $clientid
     * @param string $secret
     * @param string $key
     * @param string[] $hosts
     * @return cifra
     */
    private function client(
        string $mode,
        string $url,
        string $clientid = '',
        string $secret = '',
        string $key = '',
        array $hosts = []
    ): cifra {
        $curl = new class extends \curl {
            /** @var int[] HTTP codes of the next answers, in order. */
            public array $codes = [];

            /** @var array[] What it was asked: address, headers, body, and whether by POST. */
            public array $asked = [];

            #[\Override]
            protected function request($url, $options = []) {
                $this->asked[] = ['url' => $url, 'headers' => $this->header, 'post' => !empty($options['CURLOPT_POST']),
                    'body' => (string) ($options['CURLOPT_POSTFIELDS'] ?? '')];
                $this->resetHeader();
                $answer = parent::request($url, $options);
                $code = array_shift($this->codes) ?? 200;
                $this->info = ['http_code' => $code];
                $this->errno = $code ? 0 : 28;
                return $answer;
            }
        };
        return new class ($curl, $mode, $url, $clientid, $secret, $key, $hosts) extends cifra {
            /** @var \curl The client of every request. */
            public \curl $fake;

            /**
             * With its client.
             *
             * @param \curl $fake
             * @param string $mode
             * @param string $url
             * @param string $clientid
             * @param string $secret
             * @param string $key
             * @param string[] $hosts
             */
            public function __construct(
                \curl $fake,
                string $mode,
                string $url,
                string $clientid,
                string $secret,
                string $key,
                array $hosts
            ) {
                parent::__construct($mode, $url, $clientid, $secret, $key, $hosts);
                $this->fake = $fake;
            }

            #[\Override]
            protected function curl(bool $internal): \curl {
                return $this->fake;
            }
        };
    }

    /**
     * The next answers, in the order they are asked for (mock_response is a pile: the last one comes out first).
     *
     * @param cifra $client
     * @param array[] $answers Each, its HTTP code and its body (an array goes as JSON).
     */
    private function answers(cifra $client, array $answers): void {
        foreach (array_reverse($answers) as [$code, $body]) {
            \curl::mock_response(is_string($body) ? $body : json_encode($body));
        }
        $client->fake->codes = array_merge($client->fake->codes, array_map(fn($answer) => $answer[0], $answers));
    }

    /**
     * What it asked since last time, and forgotten.
     *
     * @param cifra $client
     * @return array[]
     */
    private function asked(cifra $client): array {
        $asked = $client->fake->asked;
        $client->fake->asked = [];
        return $asked;
    }

    /**
     * Made-up people, as Cifra gives them.
     *
     * @param int[] $ids
     * @return array[]
     */
    private static function people(array $ids): array {
        return array_map(fn($id) => ['id' => $id, 'nombre' => 'Lía', 'apellido1' => 'Faro', 'apellido2' => (string) $id,
            'fecha_nacimiento' => '2014-03-02', 'fecha_baja' => null], array_values($ids));
    }

    /**
     * That something fails with words that say why, and nothing of what Cifra answered.
     *
     * @param callable $call
     * @param string $says
     */
    private function fails(callable $call, string $says): void {
        try {
            $call();
            $this->fail('It should have failed: ' . $says);
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString($says, $e->getMessage());
            $this->assertStringNotContainsString('Lía', $e->getMessage());
            $this->assertStringNotContainsString('made-up-', $e->getMessage());
        }
    }

    public function test_set_once_for_every_plugin(): void {
        $own = explode('\\', __NAMESPACE__)[0];
        $this->assertNull(cifra::of_site());

        // Straight to Cifra, set in another plugin of Oksigenia (its secret encrypted by Moodle): the address may be empty.
        set_config('cifraclientid', 'centre', 'local_oksigeniaother');
        set_config('cifrasecret', \core\encryption::encrypt('made-up-secret'), 'local_oksigeniaother');
        $this->assertSame('oauth', cifra::of_site()->mode());

        // Its own, a bridge on the server's own network: by http only when its host is allowed.
        set_config('cifraurl', 'http://cifra-puente:8080/ejemplo', $own);
        set_config('cifrakey', 'made-up-key', $own);
        $this->assertSame('oauth', cifra::of_site()->mode());
        set_config('cifrainternalhosts', 'nas, cifra-puente', $own);
        $this->assertSame('key', cifra::of_site()->mode());
        set_config('cifraurl', 'http://elsewhere.example', $own);
        $this->assertSame('oauth', cifra::of_site()->mode());

        // Nothing is mixed: a client without its secret does not take the secret of another plugin.
        set_config('cifraclientid', 'other-centre', $own);
        set_config('ciframode', 'oauth', $own);
        unset_config('cifrasecret', 'local_oksigeniaother');
        $this->assertNull(cifra::of_site());
        set_config('cifrasecret', 'made-up-secret', $own);
        unset_config('cifraurl', $own);
        $this->assertSame('oauth', cifra::of_site()->mode());

        // An address with a key in it, or an unknown way in, is not ready.
        $this->assertFalse((new cifra('key', 'https://bridge.example/?llave=x', '', '', 'k'))->ready());
        $this->assertFalse((new cifra('ftp', 'https://bridge.example', 'c', 's', 'k'))->ready());
        $this->fails(fn() => (new cifra('oauth'))->people(), 'not complete');
    }

    public function test_straight_to_cifra_joins_rounds_until_two_bring_nobody_new(): void {
        $client = $this->client('oauth', '', 'centre-a', 'made-up-secret');
        // Cifra repeats some and skips others between pages: 5 twice and 7 missing, then 120 missing.
        $first = self::people(range(1, 100));
        $first[6] = $first[4];
        $second = self::people(array_diff(range(101, 150), [120]));
        $whole = [self::people(range(1, 100)), self::people(range(101, 150))];
        $this->answers($client, [[200, ['access_token' => 'made-up-token', 'expires_in' => 7200]],
            [200, $first], [200, $second], [200, $whole[0]], [200, $whole[1]], [200, $whole[0]], [200, $whole[1]],
            [200, ['data' => $whole[0]]], [200, ['data' => $whole[1]]]]);
        $people = $client->people();
        $this->assertSame(range(1, 150), array_keys($people));
        $asked = $this->asked($client);
        $this->assertCount(9, $asked);
        // The token asks for exactly the area of the call, and the secret goes only in its body.
        $this->assertTrue($asked[0]['post']);
        $this->assertSame('https://app.cifraeducacion.com/api_publica/oauth/token', $asked[0]['url']);
        $this->assertStringContainsString('scope=alumnos%3Aread', $asked[0]['body']);
        $this->assertStringContainsString('client_secret=made-up-secret', $asked[0]['body']);
        foreach (array_slice($asked, 1) as $get) {
            $this->assertFalse($get['post']);
            $this->assertMatchesRegularExpression('#/api_publica/v1/alumnos\?per_page=100&page=[12]$#', $get['url']);
            $this->assertContains('Authorization: Bearer made-up-token', $get['headers']);
            $this->assertStringNotContainsString('made-up-secret', $get['url'] . implode(' ', $get['headers']));
        }

        // Another area, its own permission and pages of 500; an empty list is read three times, and is empty.
        $this->answers($client, [[200, ['access_token' => 'made-up-token-2']], [200, []], [200, []], [200, []]]);
        $this->assertSame([], $client->all('alumnos_asistencias', ['fecha' => '2026-11-04']));
        $asked = $this->asked($client);
        $this->assertStringContainsString('scope=alumnos_asistencias%3Aread', $asked[0]['body']);
        $this->assertStringEndsWith('/v1/alumnos_asistencias?fecha=2026-11-04&per_page=500&page=1', $asked[1]['url']);
        $this->assertContains('Authorization: Bearer made-up-token-2', $asked[1]['headers']);

        // The token is kept while it lasts, also for another connection with the same client.
        $again = $this->client('oauth', '', 'centre-a', 'made-up-secret');
        $this->answers($again, [[200, self::people([3, 1])], [200, self::people([1, 3])], [200, self::people([3])]]);
        $this->assertSame([1, 3], array_keys($again->people()));
        $this->assertCount(0, array_filter($this->asked($again), fn($request) => $request['post']));

        // A list that never settles is not given as the list.
        $restless = $this->client('oauth', '', 'centre-b', 'made-up-secret');
        $rounds = [[200, ['access_token' => 'made-up-token']]];
        for ($round = 1; $round <= cifra::ROUNDS; $round++) {
            $rounds[] = [200, self::people([$round])];
        }
        $this->answers($restless, $rounds);
        $this->fails(fn() => $restless->people(), 'did not give the whole list «alumnos»');
    }

    public function test_straight_to_cifra_says_what_failed(): void {
        // A token Cifra no longer takes is asked again once; an item that is not there is null.
        $client = $this->client('oauth', 'https://cifra.example/api_publica/', 'centre-c', 'made-up-secret');
        $this->answers($client, [[200, ['access_token' => 'old']], [401, ['message' => 'Unauthenticated.']],
            [200, ['access_token' => 'new']], [200, ['id' => 7, 'nombre' => 'Lía', 'familiares' => []]], [404, ['error' => 'x']]]);
        $this->assertSame(7, $client->get('alumnos', [], '7')['id']);
        $this->assertNull($client->get('alumnos', [], '8'));
        $asked = $this->asked($client);
        $this->assertSame('https://cifra.example/api_publica/v1/alumnos/7', $asked[1]['url']);
        $this->assertContains('Authorization: Bearer new', $asked[3]['headers']);

        // 401 again with a new token: the area is not granted to this client in Cifra.
        $this->answers($client, [[401, []], [200, ['access_token' => 'newer']], [401, []]]);
        $this->fails(fn() => $client->get('alumnos', [], '7'), 'has not granted the area «alumnos»');
        // 403: the area is not in the token.
        $this->answers($client, [[403, ['message' => 'Lía Faro']]]);
        $this->fails(fn() => $client->get('alumnos', [], '7'), 'does not cover «alumnos»');
        // An area Cifra does not know.
        $this->answers($client, [[400, ['error' => 'invalid_scope']]]);
        $this->fails(fn() => $client->all('alumnos_faltas'), 'does not know the area «alumnos_faltas:read»');
        // A 401 when asking for the token is the client and secret, whatever its error says.
        $this->answers($client, [[401, ['error' => 'access_denied']]]);
        $this->fails(fn() => $client->all('gestion_economica/suscripciones', ['alumno_id' => 7]), 'did not accept the client');
        // Busy, then an answer that is not JSON.
        $this->answers($client, [[429, ''], [503, ''], [200, '<html>Lía</html>']]);
        $this->fails(fn() => $client->get('alumnos', [], '7'), 'not what was expected');
        // No answer at all, three times.
        $this->answers($client, [[0, ''], [0, ''], [0, '']]);
        $this->fails(fn() => $client->get('alumnos', [], '7'), 'no answer');
        // A client and secret Cifra does not take.
        $wrong = $this->client('oauth', '', 'centre-d', 'made-up-wrong');
        $this->answers($wrong, [[401, ['error' => 'invalid_client']]]);
        $this->fails(fn() => $wrong->people(), 'did not accept the client and secret');
        $this->assertSame([], $client->fake->codes);
        $this->assertSame([], $wrong->fake->codes);
    }

    public function test_through_a_bridge(): void {
        $bridge = $this->client('key', 'http://cifra-puente:8080/ejemplo/', '', '', 'made-up-key', ['cifra-puente']);
        // The bridge joins the rounds itself: the whole list at once, by its key, and nothing by POST.
        $this->answers($bridge, [[200, self::people([12, 10, 11])]]);
        $this->assertSame([10, 11, 12], array_keys($bridge->people()));
        [$asked] = $this->asked($bridge);
        $this->assertSame('http://cifra-puente:8080/ejemplo/v1/alumnos?todos=1', $asked['url']);
        $this->assertContains('X-Puente-Llave: made-up-key', $asked['headers']);
        $this->assertFalse($asked['post']);

        $this->answers($bridge, [[401, ['error' => 'Falta la llave del puente o no es válida.']]]);
        $this->fails(fn() => $bridge->people(), 'does not accept the key');
        $this->answers($bridge, [[403, ['error' => 'Esta llave solo sirve para los datos de ejemplo.']]]);
        $this->fails(fn() => $bridge->all('gestion_economica/suscripciones', ['alumno_id' => 1]), 'example data only');
        $this->answers($bridge, [[502, ['error' => 'No se pudo consultar Cifra ahora mismo.']], [502, ''], [502, '']]);
        $this->fails(fn() => $bridge->people(), 'HTTP 502');
        $this->assertSame([], $bridge->fake->codes);
    }

    public function test_moodle_http_security_stands_except_for_allowed_hosts(): void {
        // Nothing is mocked here, and nothing leaves: a blocked address, and a port of this machine where nobody listens.
        $outside = new cifra('key', 'http://cifra-puente.invalid:8080', '', '', 'made-up-key');
        $this->assertFalse($outside->ready());
        $this->fails(fn() => $outside->people(), 'not complete');
        set_config('curlsecurityallowedport', "443\n80");
        $blocked = new cifra('key', 'https://cifra-puente.invalid:8443', '', '', 'made-up-key');
        $this->fails(fn() => $blocked->people(), 'HTTP security blocks');
        $this->assertDebuggingCalled();
        $inside = new cifra('key', 'http://127.0.0.1:9', '', '', 'made-up-key', ['127.0.0.1']);
        $this->assertTrue($inside->ready());
        $this->fails(fn() => $inside->people(), 'no answer');
    }

    public function test_dates_in_the_centres_time_and_who_is_there(): void {
        $this->setTimezone('Atlantic/Canary');
        // Mainland Spain's time to the centre's: summer and winter, with no offset; a date alone stays.
        $this->assertSame('2026-10-15T14:24:00+01:00', cifra::local('2026-10-15T15:24:00+02:00'));
        $this->assertSame('2026-11-15T09:00:00+00:00', cifra::local('2026-11-15T10:00:00+01:00'));
        $this->assertSame('2026-11-15T09:00:00+00:00', cifra::local('2026-11-15 10:00:00'));
        $this->assertSame('2014-03-02', cifra::local('2014-03-02'));
        foreach (['', null, '2014-02-30', 'tomorrow', '02/03/2014'] as $nodate) {
            $this->assertNull(cifra::local($nodate));
            $this->assertNull(cifra::timestamp($nodate));
        }
        $utc = new \DateTimeZone('UTC');
        $moment = (new \DateTimeImmutable('2026-10-15 13:24', $utc))->getTimestamp();
        $this->assertSame($moment, cifra::timestamp('2026-10-15T15:24:00+02:00'));
        $this->assertSame((new \DateTimeImmutable('2014-03-02 00:00', $utc))->getTimestamp(), cifra::timestamp('2014-03-02'));
        // A centre in another zone than the server: its own, passed in.
        $madrid = new \DateTimeZone('Europe/Madrid');
        $this->assertSame('2026-10-15T15:24:00+02:00', cifra::local('2026-10-15T15:24:00+02:00', $madrid));
        $this->assertSame(
            (new \DateTimeImmutable('2014-03-02 00:00', $madrid))->getTimestamp(),
            cifra::timestamp('2014-03-02', $madrid)
        );

        // Who has not left: no date, or one still to come.
        $people = self::people([1, 2, 3]);
        $people[1]['fecha_baja'] = '2026-10-15T15:24:00+02:00';
        $people[2]['fecha_baja'] = '2026-12-01T00:00:00+01:00';
        $people = array_combine([1, 2, 3], $people);
        $before = $people;
        $this->assertSame([1, 3], array_keys(cifra::present($people, strtotime('2026-11-07 12:00 UTC'))));

        // Bring all and compare: new, changed in what matters, and gone; the rest of the fields do not count.
        $first = cifra::changes([], $people, ['nombre', 'apellido1', 'fecha_nacimiento']);
        $this->assertSame(['1', '2', '3'], $first['new']);
        $people[1]['fecha_nacimiento'] = '2014-03-03';
        $people[3]['fecha_baja'] = '2026-11-20T00:00:00+01:00';
        unset($people[2]);
        $people[4] = self::people([4])[0];
        $next = cifra::changes($first['fingerprints'], $people, ['nombre', 'apellido1', 'fecha_nacimiento']);
        $this->assertSame(['new' => ['4'], 'changed' => ['1'], 'gone' => ['2']], array_diff_key($next, ['fingerprints' => 1]));
        // With every field, the date of leaving counts too.
        $this->assertSame(['1', '3'], cifra::changes(cifra::changes([], $before)['fingerprints'], $people)['changed']);
    }

    public function test_the_example_data_of_a_bridge(): void {
        $url = (string) getenv('CIFRA_PUENTE_PRUEBA_URL');
        $key = (string) getenv('CIFRA_PUENTE_PRUEBA_LLAVE');
        if ($url === '' || $key === '' || !str_ends_with(rtrim($url, '/'), '/ejemplo')) {
            $this->markTestSkipped('No bridge with example data (CIFRA_PUENTE_PRUEBA_URL, ending in /ejemplo, and its key).');
        }
        $bridge = new cifra('key', $url, '', '', $key, [(string) parse_url($url, PHP_URL_HOST)]);
        $people = $bridge->people();
        $this->assertGreaterThan(100, count($people));
        $ids = array_keys($people);
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids);
        foreach ($people as $id => $person) {
            $this->assertSame($id, $person['id']);
            $this->assertTrue(empty($person['fecha_baja']) || cifra::local($person['fecha_baja']) !== null);
        }
        $record = $bridge->get('alumnos', [], (string) $ids[0]);
        $this->assertSame($ids[0], $record['id']);
        $this->assertArrayHasKey('familiares', $record);
        $this->assertNull($bridge->get('alumnos', [], '1'));
        $this->assertIsArray($bridge->all('alumnos_asistencias', ['fecha' => '2026-11-04']));
        $this->assertIsArray($bridge->all('gestion_economica/suscripciones', ['alumno_id' => $ids[0]]));
        $wrong = new cifra('key', $url, '', '', 'made-up-key', [(string) parse_url($url, PHP_URL_HOST)]);
        $this->fails(fn() => $wrong->people(), 'does not accept the key');
    }
}
