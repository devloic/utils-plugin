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
        priority: -1
    );
  

  
   

};
