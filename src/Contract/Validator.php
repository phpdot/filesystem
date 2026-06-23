<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Contract;

use PHPdot\Filesystem\Validation\FileSubject;
use PHPdot\Filesystem\Validation\Violation;

/**
 * A single, composable validation rule. Implementations inspect the immutable
 * {@see FileSubject} and *return* their violations — they never throw for
 * invalid input, so a {@see \PHPdot\Filesystem\Validation\ValidatorPipeline}
 * can aggregate every rule's findings (collect-all, not fail-fast).
 */
interface Validator
{
    /**
     * @return iterable<Violation>
     */
    public function validate(FileSubject $subject): iterable;
}
