<?php

/*
 * This file is part of PHP-FFmpeg.
 *
 * (c) Alchemy <info@alchemy.fr>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

 namespace Plugin\UtilsPlugin\EventHandler\Songdb\DataMapping;

use Traversable;

class SubsongCollection implements \Countable, \IteratorAggregate
{
    private $streams;

    public function __construct(array $streams = [])
    {
        $this->streams = array_values($streams);
    }

    /**
     * Returns the first stream of the collection, null if the collection is
     * empty.
     *
     * @return Stream|null
     */
    public function first()
    {
        $stream = reset($this->streams);

        return $stream ?: null;
    }

    /**
     * Adds a stream to the collection.
     *
     * @return StreamCollection
     */
    public function add(Stream $stream)
    {
        $this->streams[] = $stream;

        return $this;
    }

 

   

    /**
     * {@inheritdoc}
     */
    public function count(): int
    {
        return count($this->streams);
    }

    /**
     * Returns the array of contained streams.
     *
     * @return array
     */
    public function all()
    {
        return $this->streams;
    }

    /**
     * {@inheritdoc}
     */
    public function getIterator(): Traversable
    {
        return new \ArrayIterator($this->streams);
    }
}
