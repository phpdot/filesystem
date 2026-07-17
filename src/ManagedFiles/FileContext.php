<?php

declare(strict_types=1);

/**
 * The per-store inputs the caller supplies to {@see Files::store}: the declared
 * name, optional owner reference and tags, the desired visibility, an optional
 * path pattern override, and the validators to enforce for this upload.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\ManagedFiles;

use PHPdot\Filesystem\Contract\Validator;
use PHPdot\Filesystem\Visibility;

final readonly class FileContext
{
    /**
     * The context of a file being validated or stored.
     *
     * @param list<string> $tags
     * @param list<Validator> $validators
     * @param string $originalName
     * @param ?string $reference
     * @param ?string $referenceId
     * @param ?Visibility $visibility
     * @param ?string $pathPattern
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
