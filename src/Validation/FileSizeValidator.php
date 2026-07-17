<?php

declare(strict_types=1);

/**
 * Asserts the body size falls within [minBytes, maxBytes].
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Validation;

use PHPdot\Filesystem\Contract\Validator;

final readonly class FileSizeValidator implements Validator
{
    /**
     * __construct.
     *
     * @param int $maxBytes
     * @param int $minBytes
     */
    public function __construct(
        private int $maxBytes,
        private int $minBytes = 0,
    ) {}

    public function validate(FileSubject $subject): iterable
    {
        $size = $subject->size();

        if ($size > $this->maxBytes) {
            yield new Violation(
                'file_size',
                'filesystem.file_too_large',
                "File is {$size} bytes, exceeding the maximum of {$this->maxBytes} bytes.",
                ['size' => $size, 'max' => $this->maxBytes],
            );
        }

        if ($size < $this->minBytes) {
            yield new Violation(
                'file_size',
                'filesystem.file_too_small',
                "File is {$size} bytes, below the minimum of {$this->minBytes} bytes.",
                ['size' => $size, 'min' => $this->minBytes],
            );
        }
    }
}
