<?php

declare(strict_types=1);

namespace Plugin\UtilsPlugin\Commons;

use App\Entity\Station;
use App\Entity\Repository\StationRepository;
use App\Container\LoggerAwareTrait;

class Utils

{

    public static function getStationByShortname(string $shortname, StationRepository $stationRepo): ?Station
    {
        // AzuraCast 0.23.x: StationRepository exposes findByIdentifier(), which resolves
        // either a numeric id or a short_name. The old getRepository(Station::class)
        // passthrough no longer exists.
        return $stationRepo->findByIdentifier($shortname);
    }
}
