<?php
declare(strict_types=1);

namespace RuneRide\Trips;

use RuneRide\Http\{HttpClient, HttpException, HttpResponse};

/** Client de l'API GeoRide (login, motos, trajets, positions). */
final class GeorideClient
{
    public function __construct(private readonly HttpClient $http, private readonly string $baseUrl = 'https://api.georide.com') {}

    /** @throws GeorideException identifiants refusés ou réponse invalide */
    public function login(string $email, string $password): string
    {
        $res = $this->call('POST', '/user/login', null, json_encode(['email' => $email, 'password' => $password], JSON_THROW_ON_ERROR));
        if ($res->status === 401 || $res->status === 403) {
            throw new GeorideException('Identifiants GeoRide refusés', GeorideException::AUTH);
        }
        $this->mustBeOk($res);
        $data = $res->json();
        $token = is_array($data) ? ($data['authToken'] ?? $data['token'] ?? null) : null;
        return is_string($token) && $token !== '' ? $token : throw new GeorideException('Jeton GeoRide introuvable dans la réponse');
    }

    /**
     * Nouveau jeton à partir d'un jeton encore valide (les jetons GeoRide expirent après 30 jours), sans mot de passe.
     * @throws GeorideException jeton expiré ou refusé (AUTH) ou réponse invalide
     */
    public function renewToken(string $token): string
    {
        $res = $this->call('GET', '/user/new-token', $token);
        if ($res->status === 401 || $res->status === 403) {
            throw new GeorideException('Jeton GeoRide expiré', GeorideException::AUTH);
        }
        $this->mustBeOk($res);
        $data = $res->json();
        $new = is_array($data) ? ($data['authToken'] ?? $data['token'] ?? null) : null;
        return is_string($new) && $new !== '' ? $new : throw new GeorideException('Jeton GeoRide introuvable dans la réponse');
    }

    /** @return array<string,string> identifiant => nom de la moto */
    public function trackers(string $token): array
    {
        $res = $this->call('GET', '/user/trackers', $token);
        $this->mustBeOk($res);
        $list = $res->json();
        if (!is_array($list)) {
            throw new GeorideException('Liste des motos invalide');
        }
        $out = [];
        foreach ($list as $t) {
            $id = $t['trackerId'] ?? $t['id'] ?? null;
            if ($id === null) {
                continue;
            }
            $out[(string) $id] = (string) ($t['trackerName'] ?? $t['name'] ?? $t['vehicle']['name'] ?? "Moto #$id");
        }
        return $out;
    }

    /** @return list<array<string,mixed>> trajets bruts (id, startTime, endTime, distance en m, duration en ms…) */
    public function trips(string $token, string $trackerId, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $res = $this->call('GET', '/tracker/' . rawurlencode($trackerId) . '/trips?' . self::range($from->format('c'), $to->format('c')), $token);
        $this->mustBeOk($res);
        $list = $res->json();
        return is_array($list) ? array_values($list) : throw new GeorideException('Liste des trajets invalide');
    }

    /**
     * Tracé GPS d'un trajet. Une limitation de débit (429) est retentée une fois après une pause.
     * @return list<array{0:float,1:float}>
     */
    public function positions(string $token, string $trackerId, string $fromIso, string $toIso, ?callable $sleep = null): array
    {
        $path = '/tracker/' . rawurlencode($trackerId) . '/trips/positions?' . self::range($fromIso, $toIso);
        $res = $this->call('GET', $path, $token);
        if ($res->status === 429) {
            ($sleep ?? usleep(...))(2_000_000);
            $res = $this->call('GET', $path, $token);
        }
        $this->mustBeOk($res);
        $points = $res->json();
        $out = [];
        foreach (is_array($points) ? $points : [] as $p) {
            if (isset($p['latitude'], $p['longitude']) && is_numeric($p['latitude']) && is_numeric($p['longitude'])) {
                $out[] = [(float) $p['latitude'], (float) $p['longitude']];
            }
        }
        return $out;
    }

    private static function range(string $from, string $to): string
    {
        return 'from=' . rawurlencode($from) . '&to=' . rawurlencode($to);
    }

    private function call(string $method, string $path, ?string $token, ?string $body = null): HttpResponse
    {
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        if ($token !== null) {
            $headers['Authorization'] = "Bearer $token";
        }
        try {
            return $this->http->request($method, rtrim($this->baseUrl, '/') . $path, $headers, $body);
        } catch (HttpException $e) {
            throw new GeorideException('GeoRide injoignable', GeorideException::NETWORK, $e);
        }
    }

    private function mustBeOk(HttpResponse $res): void
    {
        if (!$res->ok()) {
            throw new GeorideException("GeoRide a répondu HTTP {$res->status}", $res->status === 429 ? GeorideException::RATE_LIMIT : GeorideException::OTHER);
        }
    }
}
