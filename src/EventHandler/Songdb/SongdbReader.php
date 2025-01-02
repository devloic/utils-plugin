<?php

declare(strict_types=1);

namespace Plugin\UtilsPlugin\EventHandler\Songdb;

use App\Event\Media\ReadMetadata;
use App\Media\Metadata;
use App\Media\MetadataInterface;
use App\Media\MimeType;
use App\Utilities\File;
use App\Utilities\Time;
use App\Utilities\Types;
use Plugin\UtilsPlugin\EventHandler\Songdb\DataMapping\Stream;
use Plugin\UtilsPlugin\EventHandler\Songdb\DataMapping\Format;
use Plugin\UtilsPlugin\EventHandler\Songdb\DataMapping\SubsongCollection;
use Plugin\UtilsPlugin\EventHandler\Songdb\Songdb;
use Throwable;
use App\Container\LoggerAwareTrait;
use App\Media\Metadata\Reader\AbstractReader;
use Psr\Log\LoggerInterface;


final class SongdbReader extends AbstractReader
{
    use LoggerAwareTrait;

    private readonly Songdb $songdb;


    public function __construct(
        LoggerInterface $logger
    ) {
        $this->songdb = Songdb::create([], $logger);
    }

    public function __invoke(ReadMetadata $event): void
    {
        $path = $event->getPath();

        $format = $this->songdb->format($path);
        $streams = $this->songdb->subsongs($path);

        $metadata = new Metadata();
        $metadata->setMimeType(MimeType::getMimeTypeFromFile($path));

        $this->logger->error(
            '  $metadata->setMimeType(',
            [
                'runtmetadataime' => $metadata,
               
            ]
        );

        $duration = $this->getDuration($format, $streams);
        if (null !== $duration) {
            $metadata->setDuration($duration);
        }

        $this->aggregateFFProbeMetaTags(
            $metadata,
            $format,
            $streams
        );

        $metadata->setArtwork(
            $this->getAlbumArt(
                $streams,
                $path
            )
        );

        $event->setMetadata($metadata);
    }

    private function getDuration(
        Format $format,
        SubsongCollection $streams
    ): ?float {
        $formatDuration = $format->get('duration');
        if (is_numeric($formatDuration)) {
            return Time::displayTimeToSeconds($formatDuration);
        }

        /** @var Stream $stream */
        foreach ($streams->all() as $stream) {
            $duration = $stream->get('duration');
            if (is_numeric($duration)) {
                return Time::displayTimeToSeconds($duration);
            }
        }

        return null;
    }

    private function aggregateFFProbeMetaTags(
        MetadataInterface $metadata,
        Format $format,
        SubsongCollection $streams
    ): void {
        $toProcess = [
            Types::array($format->get('comments')),
            Types::array($format->get('tags')),
        ];

        /** @var Stream $stream */
        foreach ($streams->all() as $stream) {
            $toProcess[] = Types::array($stream->get('comments'));
            $toProcess[] = Types::array($stream->get('tags'));
        }

        $this->aggregateMetaTags($metadata, $toProcess);
    }

    private function getAlbumArt(
       SubsongCollection $streams,
        string $path
    ): ?string {
        // Pull album art directly from relevant streams.
        try {
            /** @var Stream $videoStream */
            foreach ($streams->all() as $videoStream) {
                $streamDisposition = $videoStream->get('disposition');
                if (!isset($streamDisposition['attached_pic']) || 1 !== $streamDisposition['attached_pic']) {
                    continue;
                }

                $artOutput = File::generateTempPath('artwork.jpg');
                @unlink($artOutput); // Ffmpeg won't overwrite the empty file.

                $this->songdb->getSongdbDriver()->command([
                    '-i',
                    $path,
                    '-an',
                    '-vcodec',
                    'copy',
                    $artOutput,
                ]);

                $artContent = file_get_contents($artOutput) ?: null;
                @unlink($artOutput);
                return $artContent;
            }
        } catch (Throwable) {
        }

        return null;
    }
}
