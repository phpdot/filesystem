<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Upload;

/**
 * The outcome of writing one chunk: the new contiguous offset, and whether the
 * upload has now received its full declared size.
 */
final readonly class ChunkResult
{
    public function __construct(
        public int $offset,
        public bool $complete,
    ) {}
}
