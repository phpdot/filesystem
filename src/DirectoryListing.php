<?php

declare(strict_types=1);

/**
 * A lazy, single-pass listing of storage entries.
 *
 * Backed by any iterable (typically a generator from an adapter), so entries
 * are produced on demand. `filter()`/`map()` stay lazy; `sortByPath()` and
 * `toArray()` necessarily materialize the listing.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem;

use Closure;
use Generator;
use IteratorAggregate;
use PHPdot\Filesystem\Contract\StorageAttributes;
use Traversable;

/**
 * @implements IteratorAggregate<int, StorageAttributes>
 */
final class DirectoryListing implements IteratorAggregate
{
    /**
     * Wrap an iterable of storage entries as a lazy, single-pass listing.
     *
     * @param iterable<StorageAttributes> $listing
     */
    public function __construct(private readonly iterable $listing) {}

    /**
     * Return a new listing keeping only entries matching the predicate.
     *
     * @param Closure(StorageAttributes): bool $predicate
     *
     * @return self
     */
    public function filter(Closure $predicate): self
    {
        $listing = $this->listing;

        $generator = static function () use ($listing, $predicate): Generator {
            foreach ($listing as $item) {
                if ($predicate($item)) {
                    yield $item;
                }
            }
        };

        return new self($generator());
    }

    /**
     * Return a new listing with each entry transformed.
     *
     * @template T
     *
     * @param Closure(StorageAttributes): T $mapper
     *
     * @return Generator<int,T>
     */
    public function map(Closure $mapper): Generator
    {
        foreach ($this->listing as $item) {
            yield $mapper($item);
        }
    }

    /**
     * Sort by path.
     *
     * @return self
     */
    public function sortByPath(): self
    {
        $items = $this->toArray();

        usort(
            $items,
            static fn(StorageAttributes $a, StorageAttributes $b): int => $a->path() <=> $b->path(),
        );

        return new self($items);
    }

    /**
     * Materialize the listing into an array.
     *
     * @return list<StorageAttributes>
     */
    public function toArray(): array
    {
        return iterator_to_array($this->getIterator(), false);
    }

    /**
     * @return Traversable<int,StorageAttributes>
     */
    public function getIterator(): Traversable
    {
        foreach ($this->listing as $item) {
            yield $item;
        }
    }
}
