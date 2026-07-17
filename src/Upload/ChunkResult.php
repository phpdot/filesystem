<?php

declare(strict_types=1);

/**
 * The outcome of writing one chunk: the new contiguous offset, and whether the
 * upload has now received its full declared size.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Upload;

final readonly class ChunkResult
{
    /**
     * __construct.
     *
     * @param int $offset
     * @param bool $complete
     */
    public function __construct(
        public int $offset,
        public bool $complete,
    ) {}
}
