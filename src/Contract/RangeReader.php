<?php

declare(strict_types=1);

/**
 * Capability: the adapter reads a stretch of a file without reading what comes before it, as an S3 ranged GET does;
 * absent it, the operator streams the file from its start and skips to the offset.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Contract;

use PHPdot\Filesystem\Exception\UnableToReadFile;
use Psr\Http\Message\StreamInterface;

interface RangeReader
{
    /**
     * The bytes from `$offset`, at most `$length` of them and none past the end, as a stream; empty past the end.
     *
     * @param non-negative-int $offset
     * @param non-negative-int $length
     *
     * @throws UnableToReadFile When the file cannot be read.
     */
    public function readRange(string $path, int $offset, int $length): StreamInterface;
}
