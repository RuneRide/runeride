<?php
declare(strict_types=1);

namespace RuneRide\Fuel;

final class Station
{
    /** @param array<string,float> $prices prix au litre par carburant (sp95, sp98, e10, gazole) */
    public function __construct(
        public readonly string $country,
        public readonly string $id,
        public readonly ?string $brand,
        public readonly ?string $name,
        public readonly string $address,
        public readonly string $city,
        public readonly string $postCode,
        public readonly float $latitude,
        public readonly float $longitude,
        public readonly string $currency,
        public readonly array $prices,
        public readonly ?string $updatedAt,
    ) {}
}
