<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Exception;

use PHPdot\Filesystem\Validation\Violation;
use RuntimeException;

/**
 * Thrown when a {@see \PHPdot\Filesystem\Validation\ValidationResult} that
 * carries violations is asserted. Unlike the old fail-fast pipeline, this
 * aggregates *every* violation so callers can surface them all at once.
 */
final class FileValidationFailed extends RuntimeException implements FilesystemException
{
    /**
     * @param list<Violation> $violations
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
     * @param list<Violation> $violations
     */
    public static function withViolations(array $violations): self
    {
        $count = count($violations);
        $summary = implode('; ', array_map(static fn(Violation $v): string => $v->message, $violations));

        return new self($violations, "File validation failed with {$count} violation(s): {$summary}");
    }

    /**
     * @return list<Violation>
     */
    public function violations(): array
    {
        return $this->violations;
    }
}
