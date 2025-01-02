<?php

namespace Plugin\UtilsPlugin\EventHandler\Songdb;

use Alchemy\BinaryDriver\Exception\ExecutionFailureException;
use Plugin\UtilsPlugin\EventHandler\Songdb\Driver\SongdbDriver;
use Plugin\UtilsPlugin\EventHandler\Songdb\Exception\RuntimeException;
use Psr\Cache\CacheItemPoolInterface;

class OptionsTester implements OptionsTesterInterface
{
    /** @var SongdbDriver */
    private $songdb;
    /** @var CacheItemPoolInterface */
    private $cache;

    public function __construct(SongdbDriver $songdb, CacheItemPoolInterface $cache)
    {
        $this->songdb = $songdb;
        $this->cache = $cache;
    }

    /**
     * {@inheritdoc}
     */
    public function has($name)
    {
        $id = md5(sprintf('option-%s', $name));

        if ($this->cache->hasItem($id)) {
            return $this->cache->getItem($id)->get();
        }

        $output = $this->retrieveHelpOutput();

        $ret = (bool) preg_match('/^'.$name.'/m', $output);

        $cacheItem = $this->cache->getItem($id);
        $cacheItem->set($ret);
        $this->cache->save($cacheItem);

        return $ret;
    }

    private function retrieveHelpOutput()
    {
        $id = 'help';

        if ($this->cache->hasItem($id)) {
            return $this->cache->getItem($id)->get();
        }

        try {
            $output = $this->songdb->command(['--help']);
        } catch (ExecutionFailureException $e) {
            throw new RuntimeException('Your songdb version is too old and does not support `--help` option, please upgrade.', $e->getCode(), $e);
        }

        $cacheItem = $this->cache->getItem($id);
        $cacheItem->set($output);
        $this->cache->save($cacheItem);

        return $output;
    }
}
