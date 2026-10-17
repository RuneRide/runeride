<?php
declare(strict_types=1);

namespace RuneRide;

final class Router
{
    /** @var array<string,array<string,callable>> */
    private array $routes = [];

    public function add(string $method, string $path, callable $handler): void
    {
        $this->routes[$method][$path] = $handler;
    }

    /** @return array{0:int,1:array<string,mixed>} statut et corps JSON */
    public function dispatch(string $method, string $path): array
    {
        $handler = $this->routes[$method][$path] ?? null;
        if ($handler === null) {
            $known = array_filter($this->routes, fn ($r) => isset($r[$path]));
            return $known ? [405, ['erreur' => 'méthode non permise']] : [404, ['erreur' => 'introuvable']];
        }
        return [200, $handler()];
    }
}
