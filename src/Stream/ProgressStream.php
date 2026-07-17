<?php

declare(strict_types=1);

/**
 * A PSR-7 stream decorator that reports how many bytes have flowed through it.
 *
 * The byte counter advances on every consumption path — incremental `read()`,
 * a single `getContents()`, and `__toString()` — so progress is reported no
 * matter how the underlying transport chooses to pull the body. Every other
 * stream method is delegated verbatim to the wrapped stream.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Stream;

use Closure;
use Psr\Http\Message\StreamInterface;

final class ProgressStream implements StreamInterface
{
    private int $seen = 0;

    /**
     * Wrap a stream to report read/write progress through a callback.
     *
     * @param Closure(int $soFar, ?int $total): void $onProgress
     * @param ?int $total the expected total in bytes, or null when unknown
     * @param StreamInterface $inner
     */
    public function __construct(
        private readonly StreamInterface $inner,
        private readonly Closure $onProgress,
        private readonly ?int $total = null,
    ) {}

    public function read(int $length): string
    {
        $chunk = $this->inner->read($length);
        $this->advance($chunk);

        return $chunk;
    }

    public function getContents(): string
    {
        $contents = $this->inner->getContents();
        $this->advance($contents);

        return $contents;
    }

    public function __toString(): string
    {
        $contents = (string) $this->inner;
        $this->advance($contents);

        return $contents;
    }

    /**
     * Bytes observed flowing through this stream so far.
     *
     * @return int
     */
    public function bytesSeen(): int
    {
        return $this->seen;
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

    public function getSize(): ?int
    {
        return $this->inner->getSize();
    }

    public function tell(): int
    {
        return $this->inner->tell();
    }

    public function eof(): bool
    {
        return $this->inner->eof();
    }

    public function isSeekable(): bool
    {
        return $this->inner->isSeekable();
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->inner->seek($offset, $whence);
    }

    public function rewind(): void
    {
        $this->inner->rewind();
    }

    public function isWritable(): bool
    {
        return $this->inner->isWritable();
    }

    public function write(string $string): int
    {
        return $this->inner->write($string);
    }

    public function isReadable(): bool
    {
        return $this->inner->isReadable();
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $this->inner->getMetadata($key);
    }

    /**
     * Advance.
     *
     * @param string $bytes
     *
     * @return void
     */
    private function advance(string $bytes): void
    {
        $length = strlen($bytes);
        if ($length === 0) {
            return;
        }

        $this->seen += $length;
        ($this->onProgress)($this->seen, $this->total);
    }
}
