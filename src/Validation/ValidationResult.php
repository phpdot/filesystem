<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Validation;

use PHPdot\Filesystem\Exception\FileValidationFailed;

/**
 * The collect-all outcome of running a {@see ValidatorPipeline}. Holds every
 * violation gathered across all validators; {@see throwIfInvalid} converts them
 * into a single {@see FileValidationFailed}.
 */
final readonly class ValidationResult
{
    /**
     * @param list<Violation> $violations
     */
    public function __construct(private array $violations = []) {}

    /**
     * @return list<Violation>
     */
    public function violations(): array
    {
        return $this->violations;
    }

    public function isValid(): bool
    {
        return $this->violations === [];
    }

    public function throwIfInvalid(): void
    {
        if ($this->violations !== []) {
            throw FileValidationFailed::withViolations($this->violations);
        }
    }
}
