<?php

declare(strict_types=1);

namespace Plugin\UtilsPlugin\EventHandler\Songdb;

use Plugin\UtilsPlugin\EventHandler\Songdb\Exception\ExecutableNotFoundException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Thin wrapper around the `songdb` CLI from mvtiaine/audacious-uade.
 *
 * PORTING NOTE
 * ------------
 * This class used to be a renamed copy of PHP-FFmpeg's FFProbe: it probed
 * `songdb --help` for supported options and then invoked `--show_format`,
 * `--show_subsongs` and `-print_format json`.
 *
 * The real binary supports none of that. It takes exactly one argument — a file
 * path — and writes tab-separated lines to stdout:
 *
 *   songlengths.tsv:<hash>\t<subsong>\t<milliseconds>,<flags>
 *   modinfos.tsv:<hash>\t<player>\t<n>
 *   metadata.tsv:<hash>\t<author>\t<publisher>\t<album>\t<year>
 *
 * The old code therefore threw
 * "Your songdb version is too old and does not support `--help` option" for every
 * single file, which made AzuraCast's media scanner reject the entire library.
 *
 * It also exits 0 when the file is missing ("File not found: <path>"), so success
 * cannot be inferred from the exit status.
 */
final class Songdb
{
    private const int TIMEOUT = 30;

    public function __construct(
        private readonly string $binary = 'songdb',
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public static function create(array $config = [], ?LoggerInterface $logger = null): self
    {
        $binary = $config['songdb.binary'] ?? 'songdb';

        $which = new Process(['sh', '-c', 'command -v ' . escapeshellarg($binary)]);
        $which->run();
        if (!$which->isSuccessful()) {
            throw new ExecutableNotFoundException(
                sprintf('Unable to locate the "%s" binary on PATH.', $binary)
            );
        }

        return new self($binary, $logger);
    }

    /**
     * Probe a file. Returns null when songdb has nothing to say about it — the
     * normal case for anything that is not an Amiga module.
     *
     * @return array{
     *     duration: float|null,
     *     subsongs: array<int, float>,
     *     player: string|null,
     *     author: string|null,
     *     publisher: string|null,
     *     album: string|null,
     *     year: string|null
     * }|null
     */
    public function probe(string $path): ?array
    {
        $process = new Process([$this->binary, $path]);
        $process->setTimeout(self::TIMEOUT);
        $process->run();

        $output = trim($process->getOutput());

        // Exit code is 0 even for a missing file, so detect that from stdout.
        if ('' === $output || str_starts_with($output, 'File not found')) {
            return null;
        }

        $result = [
            'duration' => null,
            'subsongs' => [],
            'player' => null,
            'author' => null,
            'publisher' => null,
            'album' => null,
            'year' => null,
        ];

        foreach (explode("\n", $output) as $line) {
            $line = rtrim($line, "\r");
            if ('' === $line) {
                continue;
            }

            $cols = explode("\t", $line);
            $head = array_shift($cols);
            $source = explode(':', $head, 2)[0];

            switch ($source) {
                case 'songlengths.tsv':
                    // <subsong> <milliseconds>[,flags]
                    if (count($cols) < 2) {
                        break;
                    }
                    $subsong = (int)$cols[0];
                    $ms = (int)explode(',', $cols[1], 2)[0];
                    if ($ms > 0) {
                        $result['subsongs'][$subsong] = $ms / 1000;
                    }
                    break;

                case 'modinfos.tsv':
                    $result['player'] = self::nullIfBlank($cols[0] ?? null);
                    break;

                case 'metadata.tsv':
                    $result['author'] = self::nullIfBlank($cols[0] ?? null);
                    $result['publisher'] = self::nullIfBlank($cols[1] ?? null);
                    $result['album'] = self::nullIfBlank($cols[2] ?? null);
                    $result['year'] = self::nullIfBlank($cols[3] ?? null);
                    break;
            }
        }

        if ([] !== $result['subsongs']) {
            // A module may list several subsongs; the lowest-numbered one is what
            // uade123 plays by default, so that is the duration AzuraCast wants.
            ksort($result['subsongs']);
            $result['duration'] = reset($result['subsongs']);
        }

        $this->logger?->debug('songdb probe', ['path' => $path, 'result' => $result]);

        return $result;
    }

    private static function nullIfBlank(?string $value): ?string
    {
        $value = null === $value ? '' : trim($value);

        return '' === $value ? null : $value;
    }
}
