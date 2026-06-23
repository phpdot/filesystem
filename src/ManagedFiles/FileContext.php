<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\ManagedFiles;

use PHPdot\Filesystem\Contract\Validator;
use PHPdot\Filesystem\Visibility;

/**
 * The per-store inputs the caller supplies to {@see Files::store}: the declared
 * name, optional owner reference and tags, the desired visibility, an optional
 * path pattern override, and the validators to enforce for this upload.
 */
final readonly class FileContext
{
    /**
     * @param list<string>    $tags
     * @param list<Validator> $validators
     */
    public function __construct(
        public string $originalName,
        public ?string $reference = null,
        public ?string $referenceId = null,
        public array $tags = [],
        public ?Visibility $visibility = null,
        public ?string $pathPattern = null,
        public array $validators = [],
    ) {}
}
