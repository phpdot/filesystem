<?php

declare(strict_types=1);

/**
 * Runs a set of {@see Validator}s against a {@see FileSubject} and aggregates
 * every violation into a single {@see ValidationResult} (collect-all, not
 * fail-fast). Stateless and immutable, so it is safe to share across coroutines.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Validation;

use PHPdot\Filesystem\Contract\Validator;

final readonly class ValidatorPipeline
{
    /**
     * @var list<Validator>
     */
    private array $validators;

    /**
     * __construct.
     *
     * @param Validator $validators
     */
    public function __construct(Validator ...$validators)
    {
        $this->validators = array_values($validators);
    }

    /**
     * Return a copy with the given validators appended.
     *
     * @param Validator $validators
     *
     * @return self
     */
    public function with(Validator ...$validators): self
    {
        return new self(...$this->validators, ...$validators);
    }

    /**
     * Validate.
     *
     * @param FileSubject $subject
     *
     * @return ValidationResult
     */
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
