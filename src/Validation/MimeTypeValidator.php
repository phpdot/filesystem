<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Validation;

use PHPdot\Filesystem\Contract\Validator;

/**
 * Asserts the content-sniffed MIME type is one of an allow-list.
 */
final readonly class MimeTypeValidator implements Validator
{
    /**
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
