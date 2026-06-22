<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Contract;

use DateTimeImmutable;
use PHPdot\Filesystem\Upload\UploadSession;

/**
 * Persistence for resumable upload sessions.
 *
 * The default is a local JSON sidecar (shared across Swoole workers on one box).
 * For multi-node, back this with a PSR-16 cache.
 */
interface SessionStoreInterface
{
    public function put(UploadSession $session): void;

    public function find(string $id): ?UploadSession;

    public function delete(string $id): void;

    /**
     * @return iterable<UploadSession>
     */
    public function expired(DateTimeImmutable $now): iterable;
}
