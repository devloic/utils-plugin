<?php


namespace Plugin\UtilsPlugin\EventHandler\Songdb\Driver;

use Alchemy\BinaryDriver\AbstractBinary;
use Alchemy\BinaryDriver\Configuration;
use Alchemy\BinaryDriver\ConfigurationInterface;
use Alchemy\BinaryDriver\Exception\ExecutableNotFoundException as BinaryDriverExecutableNotFound;
use Plugin\UtilsPlugin\EventHandler\Songdb\Exception\ExecutableNotFoundException;
use Psr\Log\LoggerInterface;

class SongdbDriver extends AbstractBinary
{
 
    public function getName()
    {
        return 'songdb';
    }

    /**
     * Creates an SongdbDriver.
     *
     * @param array|ConfigurationInterface $configuration
     * @param LoggerInterface              $logger
     *
     * @return SongdbDriver
     */
    public static function create($configuration, LoggerInterface $logger = null)
    {
        if (!$configuration instanceof ConfigurationInterface) {
            $configuration = new Configuration($configuration);
        }

        $binaries = $configuration->get('songdb.binaries', ['songdb']);

        try {
            return static::load($binaries, $logger, $configuration);
        } catch (BinaryDriverExecutableNotFound $e) {
            throw new ExecutableNotFoundException('Unable to load songdb', $e->getCode(), $e);
        }
    }
}
