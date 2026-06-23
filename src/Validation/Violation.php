<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Validation;

/**
 * A single validation failure. Immutable; carries a machine-readable {@see code}
 * for branching, a human {@see message}, the originating {@see rule}, and
 * arbitrary {@see context} (e.g. the offending size and the configured limit).
 */
final readonly class Violation
{
    /**
     * @param array<string,mixed> $context
     */
    public function __construct(
        public string $rule,
        public string $code,
        public string $message,
        public array $context = [],
    ) {}
}
