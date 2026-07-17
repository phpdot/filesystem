<?php

declare(strict_types=1);

/**
 * The package config DTO, hydrated by phpdot/config from the generated
 * `config/filesystem.php`. Flat scalars only — {@see \PHPdot\Config\Configuration}
 * fills each constructor parameter by name, so nested arrays cannot be used.
 *
 * Swap the backend by rebinding AdapterInterface in the container; these values
 * configure the default LocalAdapter and the resumable-upload core.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem;

use PHPdot\Container\Attribute\Config as ConfigSection;
use PHPdot\Container\Attribute\Singleton;

#[Singleton]
#[ConfigSection('filesystem')]
final readonly class FilesystemConfig
{
    /**
     * __construct.
     *
     * @param string $root
     * @param ?string $publicUrl
     * @param string $visibility
     * @param int $chunkSize
     * @param int $sessionTtl
     * @param string $sessionDirectory
     * @param int $temporaryUrlTtl
     * @param string $defaultPathPattern
     * @param int $draftTtl
     * @param int $softDeleteRetention
     * @param string $quarantinePrefix
     * @param string $fileRecordsDirectory
     */
    public function __construct(
        public string $root = 'storage',
        public ?string $publicUrl = null,
        public string $visibility = 'private',
        public int $chunkSize = 8388608,
        public int $sessionTtl = 86400,
        public string $sessionDirectory = 'storage/.uploads',
        public int $temporaryUrlTtl = 3600,
        public string $defaultPathPattern = '{date}/{uuid}{ext}',
        public int $draftTtl = 86400,
        public int $softDeleteRetention = 2592000,
        public string $quarantinePrefix = '.quarantine',
        public string $fileRecordsDirectory = 'storage/.files',
    ) {}
}
