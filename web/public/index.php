<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use RuneRide\{Config, Router};

$config = Config::load(__DIR__ . '/../.env');
$router = new Router();
$router->add('GET', '/api/sante', fn () => ['statut' => 'ok', 'env' => $config->get('RUNERIDE_ENV', 'production')]);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
[$status, $body] = $router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);

http_response_code($status);
header('Content-Type: application/json; charset=utf-8');
echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
