<?php

declare(strict_types=1);

/**
 * A single validation failure. Immutable; carries a machine-readable {@see code}
 * for branching, a human {@see message}, the originating {@see rule}, and
 * arbitrary {@see context} (e.g. the offending size and the configured limit).
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Validation;

final readonly class Violation
{
    /**
     * One validation violation: the failing rule and its message.
     *
     * @param array<string,mixed> $context
     * @param string $rule
     * @param string $code
     * @param string $message
     */
    public function __construct(
        public string $rule,
        public string $code,
        public string $message,
        public array $context = [],
    ) {}
}
