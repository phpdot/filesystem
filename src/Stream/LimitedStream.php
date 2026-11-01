<?php

declare(strict_types=1);

/**
 * A read-only PSR-7 stream decorator that ends after a set number of bytes of the stream it wraps, counted from where
 * that stream stood when wrapped; seeking stays inside the window.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Stream;

use PHPdot\Filesystem\Exception\StreamOperationFailed;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

final class LimitedStream implements StreamInterface
{
    private readonly int $start;

    private int $position = 0;

    /**
     * @param non-negative-int $limit
     */
    public function __construct(
        private readonly StreamInterface $inner,
        private readonly int $limit,
    ) {
        $this->start = $inner->isSeekable() ? $inner->tell() : 0;
    }

    public function read(int $length): string
    {
        $remaining = $this->limit - $this->position;

        if ($remaining <= 0 || $length <= 0) {
            return '';
        }

        $chunk = $this->inner->read(min($length, $remaining));
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function getContents(): string
    {
        $contents = '';

        while (!$this->eof()) {
            $chunk = $this->read(65_536);

            if ($chunk === '') {
                break;
            }

            $contents .= $chunk;
        }

        return $contents;
    }

    public function __toString(): string
    {
        try {
            if ($this->isSeekable()) {
                $this->rewind();
            }

            return $this->getContents();
        } catch (RuntimeException) {
            return '';
        }
    }

    public function eof(): bool
    {
        return $this->position >= $this->limit || $this->inner->eof();
    }

    public function getSize(): int|null
    {
        $size = $this->inner->getSize();

        return $size === null ? null : max(0, min($this->limit, $size - $this->start));
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function isSeekable(): bool
    {
        return $this->inner->isSeekable();
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $target = match ($whence) {
            SEEK_CUR => $this->position + $offset,
            SEEK_END => ($this->getSize() ?? $this->limit) + $offset,
            default => $offset,
        };

        if (!$this->isSeekable() || $target < 0 || $target > $this->limit) {
            throw StreamOperationFailed::becauseOutsideWindow($target, $this->limit);
        }

        $this->inner->seek($this->start + $target);
        $this->position = $target;
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw StreamOperationFailed::becauseReadOnly();
    }

    public function isReadable(): bool
    {
        return $this->inner->isReadable();
    }

    public function close(): void
    {
        $this->inner->close();
    }

    /**
     * @return resource|null
     */
    public function detach()
    {
        return $this->inner->detach();
    }

    public function getMetadata(string|null $key = null): mixed
    {
        return $this->inner->getMetadata($key);
    }
}
