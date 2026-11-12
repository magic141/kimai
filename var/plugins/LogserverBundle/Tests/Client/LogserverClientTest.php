<?php

declare(strict_types=1);

namespace KimaiPlugin\LogserverBundle\Tests\Client;

use KimaiPlugin\LogserverBundle\Client\LogserverClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class LogserverClientTest extends TestCase
{
    private string $stateFile;
    private int $now = 1_800_000_000;
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: array<string, mixed>}> */
    private array $requests = [];
    /** @var list<MockResponse|\Closure> */
    private array $responses = [];

    protected function setUp(): void
    {
        $this->stateFile = sys_get_temp_dir() . '/logserver-plugin-test-' . bin2hex(random_bytes(4)) . '.json';
        $this->requests = [];
        $this->responses = [];
        $this->now = 1_800_000_000;
    }

    protected function tearDown(): void
    {
        @unlink($this->stateFile);
    }

    private function client(string $url = 'https://lsvc.example.org', string $project = 'kimai', string $level = 'info'): LogserverClient
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $headers = [];
            foreach ($options['headers'] ?? [] as $line) {
                [$name, $value] = explode(': ', (string) $line, 2);
                $headers[strtolower($name)] = $value;
            }
            $body = isset($options['body']) && \is_string($options['body']) ? json_decode($options['body'], true) : [];
            $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => \is_array($body) ? $body : []];

            $next = array_shift($this->responses);
            if ($next instanceof \Closure) {
                return $next();
            }

            return $next ?? new MockResponse('{}', ['http_code' => 200]);
        });

        return new LogserverClient($http, $url, $project, $level, $this->stateFile, fn (): int => $this->now);
    }

    private static function json(array $body, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($body), ['http_code' => $status, 'response_headers' => ['content-type: application/json']]);
    }

    private function writeState(array $state): void
    {
        file_put_contents($this->stateFile, json_encode($state));
    }

    /** @return array<string, mixed> */
    private function readState(): array
    {
        return json_decode((string) file_get_contents($this->stateFile), true) ?? [];
    }

    public function testDisabledWithoutUrl(): void
    {
        $client = $this->client('');
        $client->log('info', 'x');
        $client->flush();

        self::assertFalse($client->isEnabled());
        self::assertSame([], $this->requests);
    }

    public function testHttpIsRejectedExceptForLocalhost(): void
    {
        self::assertFalse($this->client('http://collector.example.org')->isEnabled());
        self::assertFalse($this->client('https://user:pass@collector.example.org')->isEnabled());
        self::assertFalse($this->client('ftp://collector.example.org')->isEnabled());
        self::assertTrue($this->client('http://localhost:9000')->isEnabled());
        self::assertTrue($this->client('http://127.0.0.1:9000')->isEnabled());
        self::assertTrue($this->client('https://lsvc.example.org/')->isEnabled());
    }

    public function testSendsEntriesWithKnownKey(): void
    {
        $this->writeState(['api_key' => 'KEY123']);
        $client = $this->client();
        $client->log('warn', 'Anmeldung fehlgeschlagen: mallory', ['user' => 'mallory', 'ip' => '203.0.113.9'], 'auth');
        $client->flush();

        self::assertCount(1, $this->requests);
        $request = $this->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('https://lsvc.example.org/api/logs', $request['url']);
        self::assertSame('KEY123', $request['headers']['x-api-key']);
        $entry = $request['body']['entries'][0];
        self::assertSame('warn', $entry['level']);
        self::assertSame('Anmeldung fehlgeschlagen: mallory', $entry['message']);
        self::assertSame('auth', $entry['type']);
        self::assertSame(['user' => 'mallory', 'ip' => '203.0.113.9'], $entry['meta']);
        self::assertSame('2027-01-15T08:00:00Z', $entry['timestamp']);
        self::assertArrayHasKey('host', $entry);
    }

    public function testFlushWithEmptyQueueDoesNothing(): void
    {
        $this->writeState(['api_key' => 'KEY123']);
        $this->client()->flush();

        self::assertSame([], $this->requests);
    }

    public function testQueueIsSentOnceAndEmptied(): void
    {
        $this->writeState(['api_key' => 'KEY123']);
        $client = $this->client();
        $client->log('info', 'a');
        $client->log('info', 'b');
        $client->flush();
        $client->flush();

        self::assertCount(1, $this->requests);
        self::assertCount(2, $this->requests[0]['body']['entries']);
    }

    public function testRegistrationPollingAndSending(): void
    {
        $client = $this->client();

        // 1. Anfrage: Registrierung, Einträge gehen verloren (noch nicht freigegeben)
        $this->responses[] = self::json(['request_token' => 'TOKEN']);
        $client->log('info', 'verloren');
        $client->flush();
        self::assertCount(1, $this->requests);
        self::assertSame('https://lsvc.example.org/api/register', $this->requests[0]['url']);
        self::assertSame(['project' => 'kimai'], $this->requests[0]['body']);
        self::assertSame('TOKEN', $this->readState()['request_token']);

        // innerhalb von 30 s wird nicht erneut gepollt
        $this->now += 10;
        $client->log('info', 'verloren 2');
        $client->flush();
        self::assertCount(1, $this->requests);

        // nach 30 s: Key-Abfrage, noch ausstehend
        $this->now += 25;
        $this->responses[] = self::json(['pending' => true], 202);
        $client->log('info', 'verloren 3');
        $client->flush();
        self::assertCount(2, $this->requests);
        self::assertSame('GET', $this->requests[1]['method']);
        self::assertSame('https://lsvc.example.org/api/register/key?token=TOKEN', $this->requests[1]['url']);

        // später: freigegeben, Einträge dieser Anfrage werden gesendet
        $this->now += 31;
        $this->responses[] = self::json(['api_key' => 'KEY-FROM-POLL']);
        $client->log('info', 'ankommen');
        $client->flush();
        self::assertCount(4, $this->requests);
        self::assertSame('https://lsvc.example.org/api/logs', $this->requests[3]['url']);
        self::assertSame('KEY-FROM-POLL', $this->requests[3]['headers']['x-api-key']);
        self::assertSame('ankommen', $this->requests[3]['body']['entries'][0]['message']);
        self::assertSame('KEY-FROM-POLL', $this->readState()['api_key']);
    }

    public function testAlreadyApprovedRegistrationSendsImmediately(): void
    {
        $client = $this->client();
        $this->responses[] = self::json(['request_token' => 'TOKEN', 'api_key' => 'KEY-DIRECT']);
        $client->log('info', 'sofort');
        $client->flush();

        self::assertCount(2, $this->requests);
        self::assertSame('KEY-DIRECT', $this->requests[1]['headers']['x-api-key']);
    }

    public function testRegistrationUsesConfiguredProject(): void
    {
        $client = $this->client(project: 'kimai-tt');
        $this->responses[] = self::json(['request_token' => 'T']);
        $client->log('info', 'x');
        $client->flush();

        self::assertSame(['project' => 'kimai-tt'], $this->requests[0]['body']);
    }

    public function testInvalidProjectFallsBackToDefault(): void
    {
        $client = $this->client(project: 'böse projekt!');
        $this->responses[] = self::json(['request_token' => 'T']);
        $client->log('info', 'x');
        $client->flush();

        self::assertSame(['project' => 'kimai'], $this->requests[0]['body']);
    }

    public function testRegistrationIsRateLimited(): void
    {
        $client = $this->client();
        $this->responses[] = self::json(['error' => 'x'], 500);
        $client->log('info', 'a');
        $client->flush();
        self::assertCount(1, $this->requests);

        $this->now += 300;
        $client->log('info', 'b');
        $client->flush();
        self::assertCount(1, $this->requests, 'innerhalb von 10 Minuten keine neue Registrierung');

        $this->now += 301;
        $this->responses[] = self::json(['request_token' => 'T']);
        $client->log('info', 'c');
        $client->flush();
        self::assertCount(2, $this->requests);
    }

    public function testDeniedRegistrationStopsEverything(): void
    {
        $client = $this->client();
        $this->responses[] = self::json(['error' => 'abgelehnt'], 403);
        $client->log('info', 'a');
        $client->flush();
        self::assertSame('denied', $this->readState()['status']);

        $this->now += 100000;
        $client->log('info', 'b');
        $client->flush();
        self::assertCount(1, $this->requests);
    }

    public function testDeniedDuringPollingIsStored(): void
    {
        $this->writeState(['request_token' => 'TOKEN', 'last_poll' => 0]);
        $client = $this->client();
        $this->responses[] = self::json(['error' => 'abgelehnt'], 403);
        $client->log('info', 'a');
        $client->flush();

        self::assertSame('denied', $this->readState()['status']);
    }

    public function testUnknownTokenTriggersNewRegistration(): void
    {
        $this->writeState(['request_token' => 'OLD', 'last_poll' => 0]);
        $client = $this->client();
        $this->responses[] = self::json(['error' => 'unbekannt'], 404);
        $client->log('info', 'a');
        $client->flush();
        self::assertNull($this->readState()['request_token']);

        $this->responses[] = self::json(['request_token' => 'NEW']);
        $client->log('info', 'b');
        $client->flush();
        self::assertSame('https://lsvc.example.org/api/register', $this->requests[1]['url']);
        self::assertSame('NEW', $this->readState()['request_token']);
    }

    public function testInvalidKeyIsDroppedAndRegisteredAgain(): void
    {
        $this->writeState(['api_key' => 'OLDKEY', 'request_token' => 'OLDTOKEN']);
        $client = $this->client();
        $this->responses[] = self::json(['error' => 'Ungueltiger Key'], 401);
        $client->log('info', 'a');
        $client->flush();

        $state = $this->readState();
        self::assertNull($state['api_key']);
        self::assertNull($state['request_token']);

        $this->responses[] = self::json(['request_token' => 'NEW']);
        $client->log('info', 'b');
        $client->flush();
        self::assertSame('https://lsvc.example.org/api/register', $this->requests[1]['url']);
    }

    public function testNetworkErrorsNeverThrow(): void
    {
        $this->writeState(['api_key' => 'KEY']);
        $client = $this->client();
        $this->responses[] = static fn () => throw new \RuntimeException('Netzwerk weg');
        $client->log('info', 'a');
        $client->flush();

        self::assertCount(1, $this->requests);
    }

    public function testStateFileIsPrivate(): void
    {
        $client = $this->client();
        $this->responses[] = self::json(['request_token' => 'T']);
        $client->log('info', 'a');
        $client->flush();

        self::assertSame('0600', substr(sprintf('%o', fileperms($this->stateFile)), -4));
    }

    public function testLevelThreshold(): void
    {
        $this->writeState(['api_key' => 'KEY']);
        $client = $this->client(level: 'warn');
        $client->log('debug', 'nein');
        $client->log('info', 'nein');
        $client->log('warn', 'ja');
        $client->log('error', 'ja');
        $client->flush();

        self::assertCount(2, $this->requests[0]['body']['entries']);
    }

    public function testUnknownLevelFallsBackToInfo(): void
    {
        $this->writeState(['api_key' => 'KEY']);
        $client = $this->client();
        $client->log('banane', 'x');
        $client->flush();

        self::assertSame('info', $this->requests[0]['body']['entries'][0]['level']);
    }

    public function testMetaIsFlattenedAndSanitized(): void
    {
        $this->writeState(['api_key' => 'KEY']);
        $client = $this->client();
        $client->log('info', "Zeile1\x00\x07", [
            'roles' => ['ROLE_USER', 'ROLE_ADMIN'],
            'nothing' => null,
            'flag' => true,
            'count' => 3,
            'bad key!' => 'x',
            'long' => str_repeat('ä', 800),
            'ctrl' => "a\x1Bb",
        ], 'Ungültiger Typ!');
        $client->flush();

        $entry = $this->requests[0]['body']['entries'][0];
        self::assertSame('Zeile1', $entry['message']);
        self::assertSame('log', $entry['type']);
        self::assertSame('["ROLE_USER","ROLE_ADMIN"]', $entry['meta']['roles']);
        self::assertArrayNotHasKey('nothing', $entry['meta']);
        self::assertTrue($entry['meta']['flag']);
        self::assertSame(3, $entry['meta']['count']);
        self::assertSame('x', $entry['meta']['bad_key_']);
        self::assertSame(500, mb_strlen($entry['meta']['long']));
        self::assertSame('ab', $entry['meta']['ctrl']);
    }

    public function testQueueIsLimited(): void
    {
        $this->writeState(['api_key' => 'KEY']);
        $client = $this->client();
        for ($i = 0; $i < 600; ++$i) {
            $client->log('info', 'm' . $i);
        }
        $client->flush();

        self::assertCount(500, $this->requests[0]['body']['entries']);
    }

    public function testApiKeyNeverAppearsInEntries(): void
    {
        $this->writeState(['api_key' => 'GEHEIMER-KEY']);
        $client = $this->client();
        $client->log('info', 'a', ['user' => 'alice']);
        $client->flush();

        self::assertStringNotContainsString('GEHEIMER-KEY', json_encode($this->requests[0]['body']));
    }
}
