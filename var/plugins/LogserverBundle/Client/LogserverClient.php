<?php

declare(strict_types=1);

namespace KimaiPlugin\LogserverBundle\Client;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fire-and-forget-Client für den logserver-Collector (POST /api/logs).
 *
 * - Aktiv nur mit gültiger LOGSERVER_URL (HTTPS, außer localhost/Loopback).
 * - Der API-Key kommt über die Selbstregistrierung (POST /api/register, dann Abfrage
 *   von GET /api/register/key) und wird in einer Statusdatei abgelegt, weil PHP keinen
 *   dauerhaften Prozess hat. Ohne Freigabe im Logserver werden Einträge verworfen.
 * - Alle Methoden werfen nie: Logging darf Kimai nicht beeinträchtigen.
 */
final class LogserverClient implements LogSinkInterface
{
    private const LEVELS = ['debug' => 10, 'info' => 20, 'warn' => 30, 'error' => 40, 'fatal' => 50];
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1', '[::1]'];
    private const MAX_QUEUE = 500;
    private const POLL_INTERVAL = 30;       // Sekunden zwischen Key-Abfragen
    private const REGISTER_INTERVAL = 600;  // Sekunden zwischen Registrierungsanfragen
    private const TIMEOUT = 2.0;            // Sekunden je Anfrage

    private ?string $baseUrl;
    private string $project;
    private int $threshold;
    /** @var list<array<string, mixed>> */
    private array $queue = [];
    /** @var array<string, mixed>|null */
    private ?array $state = null;
    /** @var callable(): int */
    private $clock;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        string $url,
        string $project,
        string $level,
        private readonly string $stateFile,
        ?callable $clock = null,
    ) {
        $this->baseUrl = self::normalizeUrl($url);
        $this->project = preg_match('/^[a-z0-9_-]{1,100}$/i', $project) === 1 ? $project : 'kimai';
        $this->threshold = self::LEVELS[strtolower(trim($level))] ?? self::LEVELS['info'];
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function isEnabled(): bool
    {
        return $this->baseUrl !== null;
    }

    public function log(string $level, string $message, array $meta = [], string $type = 'log'): void
    {
        try {
            if ($this->baseUrl === null) {
                return;
            }
            $level = strtolower($level);
            if (!isset(self::LEVELS[$level])) {
                $level = 'info';
            }
            if (self::LEVELS[$level] < $this->threshold || \count($this->queue) >= self::MAX_QUEUE) {
                return;
            }
            $type = strtolower($type);
            if (preg_match('/^[a-z0-9_-]{1,50}$/', $type) !== 1) {
                $type = 'log';
            }

            $host = gethostname();
            $this->queue[] = [
                'level' => $level,
                'message' => self::cut($message, 2000),
                'meta' => self::flatten($meta),
                'type' => $type,
                'host' => $host === false ? null : self::cut($host, 150),
                'timestamp' => gmdate('Y-m-d\TH:i:s\Z', ($this->clock)()),
            ];
        } catch (\Throwable) {
            // nie werfen
        }
    }

    public function flush(): void
    {
        if ($this->queue === []) {
            return;
        }
        $entries = $this->queue;
        $this->queue = [];

        try {
            $key = $this->apiKey();
            if ($key === null) {
                return; // noch nicht freigegeben oder abgelehnt: Einträge verwerfen
            }
            [$status] = $this->request('POST', '/api/logs', ['entries' => $entries], ['X-API-Key' => $key]);
            if ($status === 401) {
                // Key ungültig (z. B. Collector neu aufgesetzt): neu registrieren
                $this->saveState(['api_key' => null, 'request_token' => null, 'last_register' => 0] + $this->loadState());
            }
        } catch (\Throwable) {
            // Collector nicht erreichbar o. Ä.: Einträge verfallen, Kimai läuft weiter
        }
    }

    private function apiKey(): ?string
    {
        $state = $this->loadState();
        if (($state['status'] ?? null) === 'denied') {
            return null;
        }
        if (!empty($state['api_key']) && \is_string($state['api_key'])) {
            return $state['api_key'];
        }

        $now = ($this->clock)();

        if (!empty($state['request_token']) && \is_string($state['request_token'])) {
            if ($now - (int) ($state['last_poll'] ?? 0) < self::POLL_INTERVAL) {
                return null;
            }
            $state['last_poll'] = $now;
            $this->saveState($state); // vor der Anfrage, damit ein Ausfall nicht jede Anfrage neu pollt
            [$status, $body] = $this->request('GET', '/api/register/key?token=' . rawurlencode($state['request_token']));

            if ($status === 200 && !empty($body['api_key']) && \is_string($body['api_key'])) {
                $state['api_key'] = $body['api_key'];
                $this->saveState($state);

                return $state['api_key'];
            }
            if ($status === 403) {
                $state['status'] = 'denied';
                $this->saveState($state);
            } elseif ($status === 404) {
                $state['request_token'] = null; // unbekannt: bei nächster Gelegenheit neu registrieren
                $state['last_register'] = 0;
                $this->saveState($state);
            }

            return null;
        }

        if ($now - (int) ($state['last_register'] ?? 0) < self::REGISTER_INTERVAL) {
            return null;
        }
        $state['last_register'] = $now;
        $this->saveState($state);
        [$status, $body] = $this->request('POST', '/api/register', ['project' => $this->project]);

        if ($status === 200 && !empty($body['request_token']) && \is_string($body['request_token'])) {
            $state['request_token'] = $body['request_token'];
            $state['last_poll'] = $now;
            if (!empty($body['api_key']) && \is_string($body['api_key'])) {
                $state['api_key'] = $body['api_key'];
            }
            $this->saveState($state);

            return $state['api_key'] ?? null;
        }
        if ($status === 403) {
            $state['status'] = 'denied';
            $this->saveState($state);
        }

        return null;
    }

    /**
     * @param array<string, mixed>  $json
     * @param array<string, string> $headers
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function request(string $method, string $path, array $json = [], array $headers = []): array
    {
        $options = ['timeout' => self::TIMEOUT, 'max_duration' => self::TIMEOUT * 2, 'headers' => $headers];
        if ($json !== []) {
            $options['json'] = $json;
        }
        $response = $this->httpClient->request($method, $this->baseUrl . $path, $options);
        $status = $response->getStatusCode();
        $decoded = json_decode($response->getContent(false), true);

        return [$status, \is_array($decoded) ? $decoded : []];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadState(): array
    {
        if ($this->state !== null) {
            return $this->state;
        }
        $state = [];
        if (is_file($this->stateFile)) {
            $decoded = json_decode((string) @file_get_contents($this->stateFile), true);
            if (\is_array($decoded)) {
                $state = $decoded;
            }
        }

        return $this->state = $state;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function saveState(array $state): void
    {
        $this->state = $state;
        try {
            $dir = \dirname($this->stateFile);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $tmp = $this->stateFile . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (@file_put_contents($tmp, json_encode($state, JSON_THROW_ON_ERROR)) === false) {
                return;
            }
            @chmod($tmp, 0600);
            @rename($tmp, $this->stateFile);
        } catch (\Throwable) {
            // Statusdatei nicht schreibbar: nur im Speicher weiterarbeiten
        }
    }

    private static function normalizeUrl(string $url): ?string
    {
        $url = rtrim(trim($url), '/');
        if ($url === '') {
            return null;
        }
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        if ($scheme === 'https' || ($scheme === 'http' && \in_array($host, self::LOCAL_HOSTS, true))) {
            return $url;
        }

        return null; // HTTPS ist Pflicht, außer für lokale Collector
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return array<string, scalar>
     */
    private static function flatten(array $meta): array
    {
        $flat = [];
        foreach ($meta as $key => $value) {
            $key = preg_replace('/[^a-zA-Z0-9_]/', '_', (string) $key) ?? '';
            if ($key === '' || $value === null) {
                continue;
            }
            if (\is_bool($value) || \is_int($value) || \is_float($value)) {
                $flat[$key] = $value;
            } elseif (\is_scalar($value) || $value instanceof \Stringable) {
                $flat[$key] = self::cut((string) $value, 500);
            } else {
                $encoded = json_encode($value);
                $flat[$key] = self::cut($encoded === false ? '' : $encoded, 500);
            }
        }

        return $flat;
    }

    private static function cut(string $value, int $max): string
    {
        // Steuerzeichen entfernen (Log-Injection über Benutzernamen u. Ä.)
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '';

        return mb_substr($value, 0, $max);
    }
}
