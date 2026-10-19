<?php
declare(strict_types=1);

use Doctrine\DBAL\Connection;
use RuneRide\{Config, Database, Router};
use RuneRide\Auth\Jwt;
use RuneRide\Balade\{BaladeApi, QuotaRepository};
use RuneRide\Trips\SubscriptionRepository;

/**
 * Routes du calculateur de balade (façade de Raido). Usage : (require 'src/Balade/routes.php')($router, $config, $jwt);
 * Toutes exigent un jeton Bearer de session ; $db (connexion DBAL) sert aux tests.
 */
return static function (Router $router, Config $config, Jwt $jwt, ?Connection $db = null): void {
    $api = static function () use ($config, $jwt, $db): BaladeApi {
        $db ??= Database::connect($config);
        return new BaladeApi(
            $jwt,
            new SubscriptionRepository($db),
            new QuotaRepository($db),
            $config->get('RUNERIDE_RAIDO_AUDIENCE', 'raido'),
            max(60, (int) $config->get('RUNERIDE_RAIDO_JETON_TTL', '900')),
            max(1, (int) $config->get('RUNERIDE_BALADE_LIMITE_KM', '25')),
            max(0, (int) $config->get('RUNERIDE_BALADE_GRANDS_PAR_MOIS', '3')),
            $config->get('RUNERIDE_RAIDO_URL', '') ?: null,
        );
    };

    $guard = static function (callable $action) use ($jwt, $api): callable {
        return static function () use ($action, $jwt, $api) {
            $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
            $claims = str_starts_with($auth, 'Bearer ') ? $jwt->verify(substr($auth, 7)) : null;
            if ($claims === null || !isset($claims['sub'])) {
                return [401, ['erreur' => 'jeton absent ou invalide']];
            }
            $raw = json_decode((string) file_get_contents('php://input'), true);
            try {
                return $action($api(), (int) $claims['sub'], is_array($raw) ? $raw : []);
            } catch (\Throwable $e) {
                error_log('[runeride/balade] ' . $e::class . ': ' . $e->getMessage());
                return [500, ['erreur' => 'erreur interne']];
            }
        };
    };

    $router->add('POST', '/api/balade/jeton', $guard(fn (BaladeApi $a, int $u, array $b) => $a->token($u, $b)));
    $router->add('GET', '/api/balade/quota', $guard(fn (BaladeApi $a, int $u) => $a->quota($u)));
};
