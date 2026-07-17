<?php

declare(strict_types=1);

/**
 * A JSON-sidecar session store: one file per session under a directory.
 *
 * Shared across Swoole workers on a single host. For multi-node deployments,
 * swap in a PSR-16-backed store via the container.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Upload\Store;

use DateTimeImmutable;
use DateTimeZone;
use PHPdot\Container\Attribute\Binds;
use PHPdot\Container\Attribute\Singleton;
use PHPdot\Filesystem\Contract\SessionStoreInterface;
use PHPdot\Filesystem\Exception\UnableToCreateDirectory;
use PHPdot\Filesystem\FilesystemConfig;
use PHPdot\Filesystem\Upload\UploadSession;

#[Singleton]
#[Binds(SessionStoreInterface::class)]
final class LocalSessionStore implements SessionStoreInterface
{
    private readonly string $directory;

    /**
     * __construct.
     *
     * @param FilesystemConfig $config
     */
    public function __construct(FilesystemConfig $config)
    {
        $this->directory = $config->sessionDirectory;

        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw UnableToCreateDirectory::atLocation($this->directory);
        }
    }

    public function put(UploadSession $session): void
    {
        $data = [
            'id' => $session->id,
            'path' => $session->path,
            'uploadId' => $session->uploadId,
            'totalSize' => $session->totalSize,
            'bytesReceived' => $session->bytesReceived,
            'parts' => $session->parts,
            'chunkSize' => $session->chunkSize,
            'expiresAt' => $session->expiresAt->getTimestamp(),
        ];

        file_put_contents($this->file($session->id), json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function find(string $id): ?UploadSession
    {
        return $this->read($this->file($id));
    }

    public function delete(string $id): void
    {
        @unlink($this->file($id));
    }

    public function expired(DateTimeImmutable $now): iterable
    {
        $files = glob($this->directory . '/*.json');
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            $session = $this->read($file);
            if ($session !== null && $session->isExpired($now)) {
                yield $session;
            }
        }
    }

    /**
     * Read.
     *
     * @param string $file
     *
     * @return ?UploadSession
     */
    private function read(string $file): ?UploadSession
    {
        if (!is_file($file)) {
            return null;
        }

        $json = @file_get_contents($file);
        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);

        return is_array($data) ? $this->hydrate($data) : null;
    }

    /**
     * Hydrate an upload session from a raw stored row.
     *
     * @param array<array-key,mixed> $data
     *
     * @return UploadSession
     */
    private function hydrate(array $data): UploadSession
    {
        $parts = [];
        $rawParts = $data['parts'] ?? null;
        if (is_array($rawParts)) {
            foreach ($rawParts as $number => $identity) {
                if (is_string($identity)) {
                    $parts[(int) $number] = $identity;
                }
            }
        }

        $totalSize = $data['totalSize'] ?? null;

        return new UploadSession(
            id: $this->string($data, 'id'),
            path: $this->string($data, 'path'),
            uploadId: $this->string($data, 'uploadId'),
            totalSize: is_int($totalSize) ? $totalSize : null,
            bytesReceived: $this->int($data, 'bytesReceived'),
            parts: $parts,
            chunkSize: $this->int($data, 'chunkSize'),
            expiresAt: (new DateTimeImmutable('@' . $this->int($data, 'expiresAt')))->setTimezone(new DateTimeZone('UTC')),
        );
    }

    /**
     * Coerce a raw value to a string.
     *
     * @param array<array-key,mixed> $data
     * @param string $key
     *
     * @return string
     */
    private function string(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * Coerce a raw value to an int.
     *
     * @param array<array-key,mixed> $data
     * @param string $key
     *
     * @return int
     */
    private function int(array $data, string $key): int
    {
        $value = $data[$key] ?? 0;

        return is_int($value) ? $value : 0;
    }

    /**
     * File.
     *
     * @param string $id
     *
     * @return string
     */
    private function file(string $id): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $id) ?? '';

        return $this->directory . '/' . $safe . '.json';
    }
}
