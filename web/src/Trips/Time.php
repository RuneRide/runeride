<?php
declare(strict_types=1);

namespace RuneRide\Trips;

/** Horodatages : stockés en UTC 'Y-m-d H:i:s' (commun MariaDB/PostgreSQL), exposés en ISO 8601. */
final class Time
{
    public static function db(\DateTimeInterface $d): string
    {
        return \DateTimeImmutable::createFromInterface($d)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public static function now(): string
    {
        return self::db(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }

    /** @return string|null null si la date n'est pas analysable */
    public static function parse(?string $iso): ?string
    {
        if ($iso === null || $iso === '') {
            return null;
        }
        try {
            return self::db(new \DateTimeImmutable($iso, new \DateTimeZone('UTC')));
        } catch (\Exception) {
            return null;
        }
    }

    public static function iso(?string $db): ?string
    {
        return $db === null ? null : (new \DateTimeImmutable($db, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
}
