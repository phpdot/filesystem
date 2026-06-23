<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Validation;

use PHPdot\Filesystem\Contract\Validator;

/**
 * Runs a set of {@see Validator}s against a {@see FileSubject} and aggregates
 * every violation into a single {@see ValidationResult} (collect-all, not
 * fail-fast). Stateless and immutable, so it is safe to share across coroutines.
 */
final readonly class ValidatorPipeline
{
    /** @var list<Validator> */
    private array $validators;

    public function __construct(Validator ...$validators)
    {
        $this->validators = array_values($validators);
    }

    /**
     * Return a copy with the given validators appended.
     */
    public function with(Validator ...$validators): self
    {
        return new self(...$this->validators, ...$validators);
    }

    public function validate(FileSubject $subject): ValidationResult
    {
        $violations = [];

        foreach ($this->validators as $validator) {
            foreach ($validator->validate($subject) as $violation) {
                $violations[] = $violation;
            }
        }

        return new ValidationResult($violations);
    }
}
