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

use Plugin\UtilsPlugin\EventHandler\Songdb\Exception\InvalidArgumentException;
use Plugin\UtilsPlugin\EventHandler\Songdb\DataMapping\Format;
use Plugin\UtilsPlugin\EventHandler\Songdb\DataMapping\Stream;
use Plugin\UtilsPlugin\EventHandler\Songdb\DataMapping\SubsongCollection;

class Mapper implements MapperInterface
{
    /**
     * {@inheritdoc}
     */
    public function map($type, $data)
    {
        switch ($type) {
            case Songdb::TYPE_FORMAT:
                return $this->mapFormat($data);
            case Songdb::TYPE_STREAMS:
                return $this->mapStreams($data);
            default:
                throw new InvalidArgumentException(sprintf('Invalid type `%s`.', $type));
        }
    }

    private function mapFormat($data)
    {
        return new Format($data['format']);
    }

    private function mapStreams($data)
    {
        $streams = new SubsongCollection();

        foreach ($data['streams'] as $properties) {
            $streams->add(new Stream($properties));
        }

        return $streams;
    }
}
