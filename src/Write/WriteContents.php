<?php

declare(strict_types=1);

/**
 * Collapses the public write-input union into a single, rewound, readable
 * {@see StreamInterface} that adapters can consume. This is the seam where an
 * uploaded file becomes a stream — typed end to end, no raw resources.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Write;

use PHPdot\Container\Attribute\Singleton;
use PHPdot\Filesystem\Exception\InvalidStreamProvided;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;

#[Singleton]
final class WriteContents
{
    /**
     * __construct.
     *
     * @param StreamFactoryInterface $streams
     */
    public function __construct(private readonly StreamFactoryInterface $streams) {}

    /**
     * Normalize.
     *
     * @param string|StreamInterface|UploadedFileInterface $contents
     *
     * @return StreamInterface
     */
    public function normalize(string|StreamInterface|UploadedFileInterface $contents): StreamInterface
    {
        if (is_string($contents)) {
            $stream = $this->streams->createStream($contents);
        } elseif ($contents instanceof UploadedFileInterface) {
            $stream = $contents->getStream();
        } else {
            $stream = $contents;
        }

        if (!$stream->isReadable()) {
            throw InvalidStreamProvided::becauseNotReadable();
        }

        if ($stream->isSeekable() && $stream->tell() !== 0) {
            $stream->rewind();
        }

        return $stream;
    }
}
