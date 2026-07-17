<?php

declare(strict_types=1);

/**
 * Thrown when a {@see \PHPdot\Filesystem\Validation\ValidationResult} that
 * carries violations is asserted. Unlike the old fail-fast pipeline, this
 * aggregates *every* violation so callers can surface them all at once.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Exception;

use PHPdot\Filesystem\Validation\Violation;
use RuntimeException;

final class FileValidationFailed extends RuntimeException implements FilesystemException
{
    /**
     * @param list<Violation> $violations
     * @param string $message
     */
    private function __construct(private readonly array $violations, string $message)
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'filesystem.validation_failed';
    }

    /**
     * Create the exception carrying the given violations.
     *
     * @param list<Violation> $violations
     *
     * @return self
     */
    public static function withViolations(array $violations): self
    {
        $count = count($violations);
        $summary = implode('; ', array_map(static fn(Violation $v): string => $v->message, $violations));

        return new self($violations, "File validation failed with {$count} violation(s): {$summary}");
    }

    /**
     * Return the validation violations that caused the failure.
     *
     * @return list<Violation>
     */
    public function violations(): array
    {
        return $this->violations;
    }
}
