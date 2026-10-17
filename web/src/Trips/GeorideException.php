<?php
declare(strict_types=1);

namespace RuneRide\Trips;

final class GeorideException extends \RuntimeException
{
    public const AUTH = 1;
    public const NETWORK = 2;
    public const RATE_LIMIT = 3;
    public const OTHER = 0;

    public function __construct(string $message, int $code = self::OTHER, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
