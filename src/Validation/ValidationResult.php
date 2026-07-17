<?php

declare(strict_types=1);

/**
 * The collect-all outcome of running a {@see ValidatorPipeline}. Holds every
 * violation gathered across all validators; {@see throwIfInvalid} converts them
 * into a single {@see FileValidationFailed}.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Validation;

use PHPdot\Filesystem\Exception\FileValidationFailed;

final readonly class ValidationResult
{
    /**
     * The outcome of validating a file: pass or fail with any violations.
     *
     * @param list<Violation> $violations
     */
    public function __construct(private array $violations = []) {}

    /**
     * Return the recorded validation violations.
     *
     * @return list<Violation>
     */
    public function violations(): array
    {
        return $this->violations;
    }

    /**
     * Is valid.
     *
     * @return bool
     */
    public function isValid(): bool
    {
        return $this->violations === [];
    }

    /**
     * Throw if invalid.
     *
     * @return void
     */
    public function throwIfInvalid(): void
    {
        if ($this->violations !== []) {
            throw FileValidationFailed::withViolations($this->violations);
        }
    }
}
