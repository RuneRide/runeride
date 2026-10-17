<?php
declare(strict_types=1);

namespace RuneRide\Tests\Support;

use RuneRide\Http\{HttpClient, HttpException, HttpResponse};

/** Faux serveur : réponses scriptées par "METHODE chemin" (sans requête) ; enregistre les appels. */
final class FakeHttpClient implements HttpClient
{
    /** @var list<array{method:string,url:string,headers:array<string,string>,body:?string}> */
    public array $calls = [];
    /** @var array<string,list<HttpResponse|\Throwable>> */
    private array $script = [];

    /** @param HttpResponse|\Throwable ...$responses consommées dans l'ordre, la dernière est répétée */
    public function on(string $key, HttpResponse|\Throwable ...$responses): self
    {
        $this->script[$key] = array_values($responses);
        return $this;
    }

    public static function json(mixed $data, int $status = 200): HttpResponse
    {
        return new HttpResponse($status, json_encode($data));
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $this->calls[] = compact('method', 'url', 'headers', 'body');
        $key = $method . ' ' . parse_url($url, PHP_URL_PATH);
        $queue = $this->script[$key] ?? throw new \LogicException("Appel non prévu : $key");
        $r = count($queue) > 1 ? array_shift($this->script[$key]) : $queue[0];
        if ($r instanceof \Throwable) {
            throw $r;
        }
        return $r;
    }

    public function count(string $key): int
    {
        return count(array_filter($this->calls, fn ($c) => $c['method'] . ' ' . parse_url($c['url'], PHP_URL_PATH) === $key));
    }
}
