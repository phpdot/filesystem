<?php

declare(strict_types=1);
namespace PHPdot\Filesystem;

use Closure;
use Generator;
use IteratorAggregate;
use PHPdot\Filesystem\Contract\StorageAttributes;
use Traversable;

/**
 * A lazy, single-pass listing of storage entries.
 *
 * Backed by any iterable (typically a generator from an adapter), so entries
 * are produced on demand. `filter()`/`map()` stay lazy; `sortByPath()` and
 * `toArray()` necessarily materialize the listing.
 *
 * @implements IteratorAggregate<int,StorageAttributes>
 */
final class DirectoryListing implements IteratorAggregate
{
    /**
     * @param iterable<StorageAttributes> $listing
     */
    public function __construct(private readonly iterable $listing) {}

    /**
     * @param Closure(StorageAttributes): bool $predicate
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
        // Re-key positionally: the source iterable's keys are irrelevant for a
        // listing, and `yield from` would otherwise leak its (mixed) key type.
        foreach ($this->listing as $item) {
            yield $item;
        }
    }
}
