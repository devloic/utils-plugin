<?php

declare(strict_types=1);

use App\CallableEventDispatcherInterface;
use App\Event;

return static function (CallableEventDispatcherInterface $dispatcher) {

    $dispatcher->addListener(
        Event\BuildConsoleCommands::class,
        function (Event\BuildConsoleCommands $event) use ($dispatcher) {
            $event->addAliases([
                'utils:create-station' => Plugin\UtilsPlugin\Command\CreateStation::class,
                'utils:import-media' => Plugin\UtilsPlugin\Command\ImportMedia::class,
            ]);
        }
    );

    $dispatcher->addCallableListener(
        Event\Media\ReadMetadata::class,
        Plugin\UtilsPlugin\EventHandler\Songdb\SongdbReader::class,
        // Must outrank PhpReader (priority 0). getID3 *recognises* Protracker/
        // Noisetracker .mod files but reports no playtime, then calls
        // stopPropagation() — which silently locked this reader out and left those
        // modules at duration 0. FfprobeReader is at -10. songdb is authoritative
        // for Amiga formats, so it goes first and stops propagation on a hit.
        priority: 10
    );
  

  
   

};
