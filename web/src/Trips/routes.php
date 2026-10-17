<?php
declare(strict_types=1);

use Doctrine\DBAL\Connection;
use RuneRide\{Config, Database, Router};
use RuneRide\Auth\Jwt;
use RuneRide\Fuel\{FranceProvider, FuelSearch, StationRepository};
use RuneRide\Http\{CurlHttpClient, HttpClient};
use RuneRide\Trips\{GeorideAccountRepository, GeorideClient, GeorideSync, SubscriptionRepository, TokenVault, TripRepository, TripService, TripsApi};

/**
 * Routes trajets GeoRide, motos, ravitaillement. Usage : (require 'src/Trips/routes.php')($router, $config, $jwt);
 * Toutes exigent un jeton Bearer valide ; la base et les services ne sont créés qu'à la première requête.
 * $http et $db (connexion DBAL) servent aux tests.
 */
return static function (Router $router, Config $config, Jwt $jwt, ?HttpClient $http = null, ?Connection $db = null): void {
    $api = static function () use ($config, $http, $db): TripsApi {
        $db ??= Database::connect($config);
        $http ??= new CurlHttpClient(15, 5);
        $vault = new TokenVault($config->get('RUNERIDE_GEORIDE_KEY'));
        $accounts = new GeorideAccountRepository($db);
        $tripRepo = new TripRepository($db);
        $client = new GeorideClient($http, $config->get('RUNERIDE_GEORIDE_URL', 'https://api.georide.com'));
        $subs = new SubscriptionRepository($db);
        return new TripsApi(
            new TripService($tripRepo, $subs, max(1, (int) $config->get('RUNERIDE_FREE_HISTORY_DAYS', '30'))),
            $tripRepo,
            $accounts,
            $client,
            new GeorideSync($client, $accounts, $tripRepo, $vault),
            $vault,
            new FuelSearch(new FranceProvider($http, new StationRepository($db))),
        );
    };

    // Enveloppe commune : authentification par jeton, corps JSON, erreurs inattendues en 500 sans fuite de détail.
    $guard = static function (callable $action) use ($jwt, $api): callable {
        return static function () use ($action, $jwt, $api) {
            $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
            $claims = str_starts_with($auth, 'Bearer ') ? $jwt->verify(substr($auth, 7)) : null;
            if ($claims === null || !isset($claims['sub'])) {
                return [401, ['erreur' => 'jeton absent ou invalide']];
            }
            $raw = json_decode((string) file_get_contents('php://input'), true);
            try {
                return $action($api(), (int) $claims['sub'], $_GET, is_array($raw) ? $raw : []);
            } catch (\Throwable $e) {
                error_log('[runeride/trips] ' . $e::class . ': ' . $e->getMessage());
                return [500, ['erreur' => 'erreur interne']];
            }
        };
    };

    $router->add('GET', '/api/trajets', $guard(fn (TripsApi $a, int $u, array $q) => $a->listTrips($u, $q)));
    $router->add('GET', '/api/trajets/carte', $guard(fn (TripsApi $a, int $u, array $q) => $a->map($u, $q)));
    $router->add('GET', '/api/trajet', $guard(fn (TripsApi $a, int $u, array $q) => $a->detail($u, $q)));
    $router->add('DELETE', '/api/trajets', $guard(fn (TripsApi $a, int $u) => $a->resetTrips($u)));
    $router->add('GET', '/api/georide', $guard(fn (TripsApi $a, int $u) => $a->georideStatus($u)));
    $router->add('PUT', '/api/georide', $guard(fn (TripsApi $a, int $u, array $q, array $b) => $a->georideLink($u, $b)));
    $router->add('DELETE', '/api/georide', $guard(fn (TripsApi $a, int $u) => $a->georideUnlink($u)));
    $router->add('GET', '/api/georide/motos', $guard(fn (TripsApi $a, int $u) => $a->georideTrackers($u)));
    $router->add('PUT', '/api/georide/motos', $guard(fn (TripsApi $a, int $u, array $q, array $b) => $a->georideSelect($u, $b)));
    $router->add('POST', '/api/georide/synchronisation', $guard(fn (TripsApi $a, int $u) => $a->georideSync($u)));
    $router->add('GET', '/api/ravitaillement', $guard(fn (TripsApi $a, int $u, array $q) => $a->fuelStops($u, $q)));
};
