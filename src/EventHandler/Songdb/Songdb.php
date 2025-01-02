<?php

/*
 * This file is part of PHP-FFmpeg.
 *
 * (c) Alchemy <info@alchemy.fr>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

 namespace Plugin\UtilsPlugin\EventHandler\Songdb;


use Alchemy\BinaryDriver\ConfigurationInterface;
use Alchemy\BinaryDriver\Exception\ExecutionFailureException;
use Plugin\UtilsPlugin\EventHandler\Songdb\Exception\InvalidArgumentException;
use Plugin\UtilsPlugin\EventHandler\Songdb\Exception\RuntimeException;
use Plugin\UtilsPlugin\EventHandler\Songdb\DataMapping\Format;
use Plugin\UtilsPlugin\EventHandler\Songdb\DataMapping\SubsongCollection;
use Plugin\UtilsPlugin\EventHandler\Songdb\Mapper;
use Plugin\UtilsPlugin\EventHandler\Songdb\MapperInterface;
use Plugin\UtilsPlugin\EventHandler\Songdb\OptionsTester;
use Plugin\UtilsPlugin\EventHandler\Songdb\OptionsTesterInterface;
use Plugin\UtilsPlugin\EventHandler\Songdb\OutputParser;
use Plugin\UtilsPlugin\EventHandler\Songdb\OutputParserInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Plugin\UtilsPlugin\EventHandler\Songdb\Driver\SongdbDriver;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class Songdb
{
    public const TYPE_STREAMS = 'streams';
    public const TYPE_FORMAT  = 'format';

    /** @var CacheItemPoolInterface */
    private $cache;
    /** @var OptionsTesterInterface */
    private $optionsTester;
    /** @var OutputParserInterface */
    private $parser;
    /** @var Songdbriver */
    private $songdb;
    /** @var MapperInterface */
    private $mapper;

    public function __construct(SongdbDriver $songdb, CacheItemPoolInterface $cache)
    {
        $this->songdb       = $songdb;
        $this->optionsTester = new OptionsTester($songdb, $cache);
        $this->parser        = new OutputParser();
        $this->mapper        = new Mapper();
        $this->cache         = $cache;
    }

    /**
     * @return OutputParserInterface
     */
    public function getParser()
    {
        return $this->parser;
    }

    /**
     * @return FFProbe
     */
    public function setParser(OutputParserInterface $parser)
    {
        $this->parser = $parser;

        return $this;
    }

    /**
     * @return SongdbDriver
     */
    public function getSongdbDriver()
    {
        return $this->songdb;
    }

    /**
     * @return Songdb
     */
    public function setSongdbDriver(SongdbDriver $songdb)
    {
        $this->songdb = $songdb;

        return $this;
    }

    /**
     * @return Songdb
     */
    public function setOptionsTester(OptionsTesterInterface $tester)
    {
        $this->optionsTester = $tester;

        return $this;
    }

    /**
     * @return OptionsTesterInterface
     */
    public function getOptionsTester()
    {
        return $this->optionsTester;
    }

    /**
     * @param CacheItemPoolInterface $cache
     *
     * @return Songdb
     */
    public function setCache(CacheItemPoolInterface $cache)
    {
        $this->cache = $cache;

        return $this;
    }

    /**
     * @return Cache
     */
    public function getCache()
    {
        return $this->cache;
    }

    /**
     * @return MapperInterface
     */
    public function getMapper()
    {
        return $this->mapper;
    }

    /**
     * @return Songdb
     */
    public function setMapper(MapperInterface $mapper)
    {
        $this->mapper = $mapper;

        return $this;
    }

    /**
     * @api
     *
     * Probes the format of a given file.
     *
     * @param string $pathfile
     *
     * @return Format A Format object
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function format($pathfile)
    {
        return $this->probe($pathfile, '--show_format', static::TYPE_FORMAT);
    }

    /**
     * @api
     *
     * Checks wether the given `$pathfile` is considered a valid media file.
     *
     * @param string $pathfile
     *
     * @return bool
     *
     * @since 0.10.0
     */
    public function isValid($pathfile)
    {
        try {
            return $this->format($pathfile)->get('duration') > 0;
        } catch (\Exception $e) {
            // complete invalid data
            return false;
        }
    }

    /**
     * @api
     *
     * Probes the streams contained in a given file.
     *
     * @param string $pathfile
     *
     * @return SubsongCollection A collection of subsongs
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function subsongs($pathfile)
    {
        return $this->probe($pathfile, '--show_subsongs', static::TYPE_STREAMS);
    }

    /**
     * @api
     *
     * Creates an Songdb.
     *
     * @param array|ConfigurationInterface $configuration
     * @param LoggerInterface              $logger
     * @param CacheItemPoolInterface       $cache
     *
     * @return Songdb
     */
    public static function create($configuration = [], LoggerInterface $logger = null, CacheItemPoolInterface $cache = null)
    {
        if (null === $cache) {
            $cache = new ArrayAdapter();
        }

        return new static(SongdbDriver::create($configuration, $logger), $cache);
    }

    private function probe($pathfile, $command, $type, $allowJson = true)
    {
        $id = md5(sprintf('%s-%s', $command, $pathfile));

        if ($this->cache->hasItem($id)) {
            return $this->cache->getItem($id)->get();
        }

        if (!$this->optionsTester->has($command)) {
            throw new RuntimeException(sprintf('This version of songdb is too old and ' . 'does not support `%s` option, please upgrade', $command));
        }

        $commands = [$pathfile, $command];

        $parseIsToDo = false;

        if ($allowJson && $this->optionsTester->has('-print_format')) {
            // allowed in latest PHP-FFmpeg version
            $commands[] = '-print_format';
            $commands[] = 'json';
        } elseif ($allowJson && $this->optionsTester->has('-of')) {
            // option has changed in avconv 9
            $commands[] = '-of';
            $commands[] = 'json';
        } else {
            $parseIsToDo = true;
        }

        try {
            $output = $this->songdb->command($commands);
        } catch (ExecutionFailureException $e) {
            throw new RuntimeException(sprintf('Unable to probe %s', $pathfile), $e->getCode(), $e);
        }

        if ($parseIsToDo) {
            $data = $this->parser->parse($type, $output);
        } else {
            try {
                // Malformed json may be retrieved
                $data = $this->parseJson($output);
            } catch (RuntimeException $e) {
                return $this->probe($pathfile, $command, $type, false);
            }
        }

        $ret = $this->mapper->map($type, $data);

        $cacheItem = $this->cache->getItem($id);
        $cacheItem->set($ret);
        $this->cache->save($cacheItem);

        return $ret;
    }

    private function parseJson($data)
    {
        $ret = @json_decode($data, true);

        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new RuntimeException(sprintf('Unable to parse json %s', $ret));
        }

        return $ret;
    }
}
