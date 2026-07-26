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
    /** Seconds of decode used to test whether uade123 accepts a file. */
    private const int PROBE_SECONDS = 2;
    /** A refusal writes only a ~68-byte WAV header; real audio is far larger. */
    private const int MIN_AUDIO_BYTES = 4096;

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
                // UADE's format table is broader than what its replayers accept, so
                // songdb recognising a file does not guarantee uade123 can play it.
                // crystalhammer.mod is the case in point: "module check failed", a
                // 68-byte WAV header, exit 1 — while ffmpeg (built with libopenmpt)
                // decodes it fine.
                //
                // Piping uade123 straight into ffmpeg would hand ffmpeg that broken
                // header and emit a stub MP3 the browser cannot play, so probe with a
                // couple of seconds of decode first and hand the file to ffmpeg alone
                // when uade123 declines it.
                if (!$this->uadeCanDecode($localPath)) {
                    $this->logger->debug(
                        'uade123 declined this file; decoding with ffmpeg/libopenmpt instead.',
                        ['path' => $media->path]
                    );
                    return $this->stream(
                        sprintf(
                            'ffmpeg -hide_banner -loglevel error -i %s -f mp3 -b:a %s - 2>/dev/null',
                            escapeshellarg($localPath),
                            self::BITRATE
                        ),
                        $response,
                        $media->path
                    );
                }

                $cmd = sprintf(
                    'uade123 --stderr -e wav -t %d -y %d -f - %s 2>/dev/null '
                    . '| ffmpeg -hide_banner -loglevel error -i - -f mp3 -b:a %s - 2>/dev/null',
                    self::DECODE_TIMEOUT,
                    self::SILENCE_TIMEOUT,
                    escapeshellarg($localPath),
                    self::BITRATE
                );

                return $this->stream($cmd, $response, $media->path);
            }
        );
    }

    /**
     * Can uade123 actually replay this file?
     *
     * songdb recognising it is not sufficient: UADE's format table lists more
     * extensions than its replayers accept. A couple of seconds of decode is enough
     * to tell — a refusal writes only a WAV header and exits non-zero.
     */
    private function uadeCanDecode(string $localPath): bool
    {
        $probe = tempnam(sys_get_temp_dir(), 'uadeprobe');
        if (false === $probe) {
            return false;
        }

        try {
            exec(
                sprintf(
                    'uade123 --stderr -e wav -t %d -y 1 -f %s %s >/dev/null 2>&1',
                    self::PROBE_SECONDS,
                    escapeshellarg($probe),
                    escapeshellarg($localPath)
                ),
                $out,
                $code
            );

            clearstatcache(true, $probe);
            $size = @filesize($probe) ?: 0;

            // Both conditions: uade123 does return 1 on refusal, and the size check
            // stops a zero-exit-but-empty decode being mistaken for success.
            return 0 === $code && $size > self::MIN_AUDIO_BYTES;
        } finally {
            @unlink($probe);
        }
    }

    private function stream(string $cmd, Response $response, string $path): ResponseInterface
    {
        $handle = popen($cmd, 'rb');
        if (false === $handle) {
            $this->logger->error('Could not start the decoder for preview.', ['path' => $path]);
            return $response->withStatus(500);
        }

        // withFile() is the same helper streamFilesystemFile() uses and takes a
        // resource directly. Do NOT reach for Slim\Psr7\Stream: AzuraCast ships
        // slim/http over guzzlehttp/psr7, so that class is absent.
        //
        // No Content-Length: the length is unknown until the decode finishes, so
        // this is a progressive stream and the browser cannot seek it.
        return $response
            ->withFile($handle, 'audio/mpeg')
            ->withHeader('Cache-Control', 'no-store');
    }
}
