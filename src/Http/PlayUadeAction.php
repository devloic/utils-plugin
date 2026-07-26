<?php

declare(strict_types=1);

namespace Plugin\UtilsPlugin\Http;

use App\Controller\SingleActionInterface;
use App\Entity\Repository\StationMediaRepository;
use App\Flysystem\StationFilesystems;
use App\Http\Response;
use App\Http\ServerRequest;
use Plugin\UtilsPlugin\EventHandler\Songdb\Exception\ExecutableNotFoundException;
use Plugin\UtilsPlugin\EventHandler\Songdb\Songdb;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Drop-in replacement for App\Controller\Api\Stations\Files\PlayAction.
 *
 * WHY THIS EXISTS
 * ---------------
 * The admin media manager previews a track by hitting
 * GET /api/station/{station_id}/file/{id}/play, and upstream's PlayAction ends with
 * `streamFilesystemFile()` — it sends the raw bytes. For an Amiga module that means
 * the browser receives something labelled audio/x-mod that it has no decoder for,
 * and the <audio> element rejects it:
 *
 *   DOMException: The media resource indicated by the src attribute or assigned
 *   media provider object was not suitable.
 *
 * The station stream is unaffected because Liquidsoap runs uade123 server-side; only
 * this preview path bypasses UADE entirely.
 *
 * This action decodes module formats through uade123 -> ffmpeg -> MP3 and streams
 * that instead. Anything songdb does not recognise falls through to upstream's exact
 * behaviour, so non-Amiga media is served byte-for-byte as before.
 *
 * No core patch and no route shadowing is involved. FastRoute refuses two routes with
 * the same pattern, but Slim resolves a route *name* by scanning in registration order
 * and returning the first match — and AzuraCast builds the preview URL with
 * urlFor('api:stations:files:play'). Registering a different path under that name,
 * earlier than AzuraCast's own routes, is enough to redirect every links.play here
 * while leaving upstream's /play route in place and working. See events.php.
 */
final readonly class PlayUadeAction implements SingleActionInterface
{
    private const int DECODE_TIMEOUT = 600;
    private const int SILENCE_TIMEOUT = 5;
    private const string BITRATE = '128k';

    public function __construct(
        private StationMediaRepository $mediaRepo,
        private StationFilesystems $stationFilesystems,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        set_time_limit(self::DECODE_TIMEOUT);

        /** @var string $id */
        $id = $params['id'];

        $station = $request->getStation();
        $media = $this->mediaRepo->requireForStation($id, $station);
        $fsMedia = $this->stationFilesystems->getMediaFilesystem($station);

        // Decide using songdb, the same authority the metadata reader uses, rather
        // than a second hand-maintained extension list that could drift from it.
        $isModule = $fsMedia->withLocalFile(
            $media->path,
            function (string $localPath): bool {
                try {
                    return null !== Songdb::create([], $this->logger)->probe($localPath);
                } catch (ExecutableNotFoundException) {
                    return false;
                }
            }
        );

        if (true !== $isModule) {
            return $response->streamFilesystemFile($fsMedia, $media->path);
        }

        // Remote storage adapters hand back a temp file that withLocalFile() deletes
        // on return, so the decode has to happen inside the callback.
        return $fsMedia->withLocalFile(
            $media->path,
            function (string $localPath) use ($response, $media): ResponseInterface {
                $cmd = sprintf(
                    'uade123 --stderr -e wav -t %d -y %d -f - %s 2>/dev/null '
                    . '| ffmpeg -hide_banner -loglevel error -i - -f mp3 -b:a %s - 2>/dev/null',
                    self::DECODE_TIMEOUT,
                    self::SILENCE_TIMEOUT,
                    escapeshellarg($localPath),
                    self::BITRATE
                );

                $handle = popen($cmd, 'rb');
                if (false === $handle) {
                    $this->logger->error(
                        'Could not start uade123/ffmpeg for preview.',
                        ['path' => $media->path]
                    );
                    return $response->withStatus(500);
                }

                // withFile() is the same helper streamFilesystemFile() uses and takes
                // a resource directly. Do NOT reach for Slim\Psr7\Stream: AzuraCast
                // ships slim/http over guzzlehttp/psr7, so that class is absent.
                //
                // No Content-Length: the length is unknown until the decode finishes,
                // so this is a progressive stream and the browser cannot seek it.
                return $response
                    ->withFile($handle, 'audio/mpeg')
                    ->withHeader('Cache-Control', 'no-store');
            }
        );
    }
}
