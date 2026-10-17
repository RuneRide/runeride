<?php
declare(strict_types=1);

namespace RuneRide\Http;

final class HttpResponse
{
    public function __construct(public readonly int $status, public readonly string $body) {}

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** @return mixed */
    public function json(): mixed
    {
        return json_decode($this->body, true);
    }
}
