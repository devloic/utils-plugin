<?php

declare(strict_types=1);

use App\CallableEventDispatcherInterface;
use App\Event;

return static function (CallableEventDispatcherInterface $dispatcher) {
    $dispatcher->addListener(
        Event\BuildConsoleCommands::class,
        function (Event\BuildConsoleCommands $event) use ($dispatcher) {
            $event->addAliases([
                'utils:create-station' => Plugin\ExamplePlugin\Command\CreateStation::class,
                'utils:import-media' => Plugin\ExamplePlugin\Command\ImportMedia::class,
            ]);
        }
    );

   
};
