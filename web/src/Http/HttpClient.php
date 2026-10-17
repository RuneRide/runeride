<?php
declare(strict_types=1);

namespace RuneRide\Http;

interface HttpClient
{
    /**
     * @param array<string,string> $headers
     * @throws HttpException si le réseau échoue (délai dépassé, hôte injoignable)
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse;
}
