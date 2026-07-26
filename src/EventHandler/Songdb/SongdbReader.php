<?php

declare(strict_types=1);

namespace Plugin\UtilsPlugin\EventHandler\Songdb;

use App\Container\LoggerAwareTrait;
use App\Event\Media\ReadMetadata;
use App\Media\Enums\MetadataTags;
use App\Media\Metadata\Reader\AbstractReader;
use Plugin\UtilsPlugin\EventHandler\Songdb\Exception\ExecutableNotFoundException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Supplies duration and tags for Amiga modules, which ffprobe cannot read.
 *
 * PORTING NOTES (AzuraCast 0.23.x)
 * --------------------------------
 * - `AbstractReader::aggregateFFProbeMetaTags()` and `getAlbumArt()` no longer
 *   exist; the base class now offers `aggregateMetaTags(MetadataInterface, array)`.
 *   Amiga modules carry no embedded artwork, so the artwork path is simply gone.
 * - Songdb no longer returns FFProbe-shaped Format/StreamCollection objects; see
 *   the porting note in Songdb.php.
 * - This listener must never throw. It is dispatched for *every* file AzuraCast
 *   scans, and an exception here aborts metadata reading for the whole library —
 *   which is exactly what the old `songdb --help` probe did.
 */
final class SongdbReader extends AbstractReader
{
    use LoggerAwareTrait;

    private ?Songdb $songdb = null;
    private bool $unavailable = false;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function __invoke(ReadMetadata $event): void
    {
        $path = $event->getPath();

        $songdb = $this->getSongdb();
        if (null === $songdb) {
            return;
        }

        try {
            $info = $songdb->probe($path);
        } catch (Throwable $e) {
            $this->logger->warning(
                'songdb could not probe file; leaving metadata to other readers.',
                ['path' => $path, 'exception' => $e->getMessage()]
            );
            return;
        }

        // Not an Amiga module songdb recognises — leave the existing metadata alone.
        if (null === $info) {
            return;
        }

        $metadata = $event->getMetadata();

        if (null !== $info['duration'] && $info['duration'] > 0) {
            $metadata->setDuration($info['duration']);
        }

        $tags = [];

        // A title is mandatory, even though songdb has no track-title column.
        //
        // StationMediaRepository::loadFromFile() ends with:
        //     if (null === $artist || null === $title) {
        //         $media->setSong(Song::createFromText($filename));
        //     }
        // and setSong() overwrites title, artist *and* album. So supplying artist
        // and album without a title guarantees both are thrown away again. Derive
        // the same filename-based title AzuraCast would have used, which keeps the
        // displayed title identical while letting songdb's artist/album survive.
        $tags[MetadataTags::Title->value] = trim(
            str_replace('_', ' ', pathinfo($path, PATHINFO_FILENAME))
        );

        if (null !== $info['author']) {
            $tags[MetadataTags::Artist->value] = $info['author'];
        }
        if (null !== $info['album']) {
            $tags[MetadataTags::Album->value] = $info['album'];
        }
        if (null !== $info['year']) {
            $tags[MetadataTags::Year->value] = $info['year'];
        }
        if (null !== $info['publisher']) {
            $tags[MetadataTags::Comment->value] = $info['publisher'];
        }
        if (null !== $info['player']) {
            $tags[MetadataTags::EncodedBy->value] = $info['player'];
        }

        if ([] !== $tags) {
            $this->aggregateMetaTags($metadata, [$tags]);
        }

        $event->setMetadata($metadata);

        // Stop here. Listener order is PhpReader (0) -> SongdbReader (-1) ->
        // FfprobeReader (-10), and ffprobe cannot parse Amiga modules: it exits
        // with "Invalid data found when processing input" and throws. MetadataManager
        // catches that and returns an *empty* Metadata object, discarding everything
        // set above. Since songdb has already identified this file, ffprobe has
        // nothing to add and can only destroy the result.
        $event->stopPropagation();
    }

    /**
     * Resolve the binary once. If it is missing, log a single warning and stay
     * inert rather than failing every scan.
     */
    private function getSongdb(): ?Songdb
    {
        if ($this->unavailable) {
            return null;
        }

        if (null === $this->songdb) {
            try {
                $this->songdb = Songdb::create([], $this->logger);
            } catch (ExecutableNotFoundException $e) {
                $this->unavailable = true;
                $this->logger->warning(
                    'songdb binary not found; Amiga module durations will be unavailable.',
                    ['exception' => $e->getMessage()]
                );
                return null;
            }
        }

        return $this->songdb;
    }
}
