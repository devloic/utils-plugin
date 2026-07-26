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

    /*
     * Serve Amiga modules through uade123 -> MP3 for the admin media preview.
     *
     * This does NOT patch or shadow upstream's route. Slim's RouteCollector resolves
     * a name by scanning routes in REGISTRATION order and returning the first match
     * (Routing/RouteCollector.php::getNamedRoute), and AzuraCast builds the preview
     * URL with urlFor('api:stations:files:play') in ListAction/FilesController.
     *
     * So: register a DIFFERENT path under the SAME name, earlier than AzuraCast's
     * routes (priority > 0). FastRoute sees no duplicate pattern, and every
     * links.play in the API points here instead. Non-module media falls through to
     * the same streamFilesystemFile() upstream would have used.
     *
     * The middleware chain reproduces upstream's exactly — getting it wrong would
     * expose station media unauthenticated:
     *   routes.php            InjectSession, Auth\ApiAuth, Module\Api
     *   api_station.php:1020  GetStation, RequireStation
     *   api_station.php:432   StationSupportsFeature(Media)
     *   api_station.php:428   Permissions(StationPermissions::Media, true)
     */
    $dispatcher->addListener(
        Event\BuildRoutes::class,
        function (Event\BuildRoutes $event) {
            $event->getApp()
                ->get(
                    '/api/station/{station_id}/file/{id}/play-amiga',
                    Plugin\UtilsPlugin\Http\PlayUadeAction::class
                )
                ->setName('api:stations:files:play')
                ->add(new App\Middleware\Permissions(App\Enums\StationPermissions::Media, true))
                ->add(new App\Middleware\StationSupportsFeature(App\Enums\StationFeatures::Media))
                ->add(App\Middleware\RequireStation::class)
                ->add(App\Middleware\GetStation::class)
                ->add(App\Middleware\Module\Api::class)
                ->add(App\Middleware\Auth\ApiAuth::class)
                ->add(App\Middleware\InjectSession::class);
        },
        priority: 10
    );

};
