<?php

declare(strict_types=1);

namespace Plugin\ExamplePlugin\Commons;

use App\Entity\Station;
use App\Entity\Repository\StationRepository;
use App\Container\LoggerAwareTrait;

class Utils

{

    public static function getStationByShortname(String $shortname,StationRepository $stationRepo): ?Station
    {
       $station = $stationRepo->getRepository(Station::class)->findOneBy(['short_name' => $shortname]);
       
      
        return $station;
    }
}
