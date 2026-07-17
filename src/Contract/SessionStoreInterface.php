<?php

declare(strict_types=1);

/**
 * Persistence for resumable upload sessions.
 *
 * The default is a local JSON sidecar (shared across Swoole workers on one box).
 * For multi-node, back this with a PSR-16 cache.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Contract;

use DateTimeImmutable;
use PHPdot\Filesystem\Upload\UploadSession;

interface SessionStoreInterface
{
    /**
     * Put.
     *
     * @param UploadSession $session
     *
     * @return void
     */
    public function put(UploadSession $session): void;

    /**
     * Find.
     *
     * @param string $id
     *
     * @return ?UploadSession
     */
    public function find(string $id): ?UploadSession;

    /**
     * Delete.
     *
     * @param string $id
     *
     * @return void
     */
    public function delete(string $id): void;

    /**
     * Whether the given upload session has expired.
     *
     * @param DateTimeImmutable $now
     *
     * @return iterable<UploadSession>
     */
    public function expired(DateTimeImmutable $now): iterable;
}
