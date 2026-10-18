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
// Connexion par Google : active seulement si l'identifiant et le secret du client sont renseignés.
$googleOn = $config->get('RUNERIDE_GOOGLE_CLIENT_ID', '') !== '' && $config->get('RUNERIDE_GOOGLE_CLIENT_SECRET', '') !== '';
$google = static fn () => new RuneRide\Auth\GoogleOAuth(new RuneRide\Http\CurlHttpClient(15, 5), $config->get('RUNERIDE_GOOGLE_CLIENT_ID'), $config->get('RUNERIDE_GOOGLE_CLIENT_SECRET'), rtrim($config->get('RUNERIDE_BASE_URL'), '/') . '/api/google/retour');
$appUrl = rtrim($config->get('RUNERIDE_BASE_URL'), '/') . '/#';
$router->add('GET', '/api/google', function () use ($googleOn, $google) {
    if (!$googleOn) {
        return [404, ['erreur' => 'connexion Google non activée']];
    }
    $start = $google()->begin();
    return [302, ['redirection' => $start['url'], 'temoin' => $start['temoin']]];
});
$router->add('GET', '/api/google/retour', function () use ($googleOn, $google, $accounts, $appUrl) {
    if (!$googleOn) {
        return [404, ['erreur' => 'connexion Google non activée']];
    }
    $profile = $google()->finish($_COOKIE['runeride_google'] ?? null, $_GET['state'] ?? null, $_GET['code'] ?? null);
    [$code, $out] = $profile === null ? [401, []] : $accounts()->loginWithGoogle($profile['sub'], $profile['email']);
    // Le jeton passe dans le fragment de l'adresse : il n'atteint ni les journaux du serveur ni un en-tête Referer.
    $to = $code === 200 ? $appUrl . '/google?jeton=' . $out['jeton'] : $appUrl . '/connexion?erreur=google';
    return [302, ['redirection' => $to, 'temoin' => '']];
});
$router->add('GET', '/api/moi', function () use ($jwt, $config) {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $claims = str_starts_with($auth, 'Bearer ') ? $jwt->verify(substr($auth, 7)) : null;
    if ($claims === null) {
        return [401, ['erreur' => 'jeton absent ou invalide']];
    }
    $user = (new RuneRide\Auth\UserRepository(Database::connect($config)))->findById((int) $claims['sub']);
    return $user === null ? [404, ['erreur' => 'compte introuvable']] : [200, $user];
});

(require __DIR__ . '/../src/Trips/routes.php')($router, $config, $jwt); // trajets GeoRide, motos, ravitaillement

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
[$status, $out] = $router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);

if ($status === 302) {
    // Aller-retour Google : redirection, avec le témoin de l'état (vide : on l'efface).
    $temoin = (string) ($out['temoin'] ?? '');
    setcookie('runeride_google', $temoin, ['expires' => $temoin === '' ? 1 : time() + 600, 'path' => '/api/google', 'secure' => str_starts_with($config->get('RUNERIDE_BASE_URL'), 'https://'), 'httponly' => true, 'samesite' => 'Lax']);
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    header('Location: ' . $out['redirection']);
    http_response_code(302);
    exit;
}
http_response_code($status);
header('Content-Type: application/json; charset=utf-8');
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
