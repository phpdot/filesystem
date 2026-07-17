<?php

declare(strict_types=1);

/**
 * Asserts the content-sniffed MIME type is one of an allow-list.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Validation;

use PHPdot\Filesystem\Contract\Validator;

final readonly class MimeTypeValidator implements Validator
{
    /**
     * A validator that checks a file's MIME type against an allow-list.
     *
     * @param list<string> $allowed
     */
    public function __construct(private readonly array $allowed) {}

    public function validate(FileSubject $subject): iterable
    {
        $mimeType = $subject->mimeType();

        if (!in_array($mimeType, $this->allowed, true)) {
            yield new Violation(
                'mime_type',
                'filesystem.mime_type_not_allowed',
                "MIME type '{$mimeType}' is not allowed.",
                ['mimeType' => $mimeType, 'allowed' => $this->allowed],
            );
        }
    }
}
