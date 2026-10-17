<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use RuneRide\{Config, Database, Router};
use RuneRide\Auth\{AccountService, Jwt, UserRepository};

$config = Config::load(__DIR__ . '/../.env');
$jwt = new Jwt(
    (string) file_get_contents($config->get('RUNERIDE_JWT_KEY_FILE')),
    $config->get('RUNERIDE_BASE_URL'),
    $config->get('RUNERIDE_JWT_AUDIENCE', 'runeride'),
);
$body = json_decode((string) file_get_contents('php://input'), true);
$body = is_array($body) ? $body : [];

$router = new Router();
$router->add('GET', '/api/sante', fn () => ['statut' => 'ok', 'env' => $config->get('RUNERIDE_ENV', 'production')]);
$router->add('GET', '/.well-known/jwks.json', fn () => $jwt->jwks());

$accounts = static fn () => (function () use ($config, $jwt) { $pdo = Database::connect($config); return new AccountService(new UserRepository($pdo), $jwt, new RuneRide\Auth\LoginThrottle($pdo)); })();
$router->add('POST', '/api/inscription', fn () => $accounts()->register((string) ($body['email'] ?? ''), (string) ($body['mot_de_passe'] ?? '')));
$router->add('POST', '/api/connexion', fn () => $accounts()->login((string) ($body['email'] ?? ''), (string) ($body['mot_de_passe'] ?? ''), $_SERVER['REMOTE_ADDR'] ?? ''));
$router->add('GET', '/api/moi', function () use ($jwt, $config) {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $claims = str_starts_with($auth, 'Bearer ') ? $jwt->verify(substr($auth, 7)) : null;
    if ($claims === null) {
        return [401, ['erreur' => 'jeton absent ou invalide']];
    }
    $user = (new RuneRide\Auth\UserRepository(Database::connect($config)))->findById((int) $claims['sub']);
    return $user === null ? [404, ['erreur' => 'compte introuvable']] : [200, $user];
});

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
[$status, $out] = $router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);

http_response_code($status);
header('Content-Type: application/json; charset=utf-8');
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
