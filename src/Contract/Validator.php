<?php

declare(strict_types=1);

/**
 * A single, composable validation rule. Implementations inspect the immutable
 * {@see FileSubject} and *return* their violations — they never throw for
 * invalid input, so a {@see \PHPdot\Filesystem\Validation\ValidatorPipeline}
 * can aggregate every rule's findings (collect-all, not fail-fast).
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Contract;

use PHPdot\Filesystem\Validation\FileSubject;
use PHPdot\Filesystem\Validation\Violation;

interface Validator
{
    /**
     * Validate the given file context, returning any violations.
     *
     * @param FileSubject $subject
     *
     * @return iterable<Violation>
     */
    public function validate(FileSubject $subject): iterable;
}
