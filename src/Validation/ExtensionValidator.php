<?php

declare(strict_types=1);

/**
 * Asserts the declared filename's extension is one of an allow-list. Reads the
 * name from the immutable {@see FileSubject} — never from mutable setter state.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Validation;

use PHPdot\Filesystem\Contract\Validator;

final readonly class ExtensionValidator implements Validator
{
    /**
     * @var list<string>
     */
    private array $allowed;

    /**
     * A validator that checks a file's extension against an allow-list.
     *
     * @param list<string> $allowed extensions, with or without a leading dot
     */
    public function __construct(array $allowed)
    {
        $this->allowed = array_map(self::normalize(...), $allowed);
    }

    public function validate(FileSubject $subject): iterable
    {
        $extension = self::normalize(pathinfo($subject->originalName(), PATHINFO_EXTENSION));

        if (!in_array($extension, $this->allowed, true)) {
            yield new Violation(
                'extension',
                'filesystem.extension_not_allowed',
                $extension === ''
                    ? 'File has no extension; an allowed extension is required.'
                    : "Extension '{$extension}' is not allowed.",
                ['extension' => $extension, 'allowed' => $this->allowed],
            );
        }
    }

    /**
     * Normalize.
     *
     * @param string $extension
     *
     * @return string
     */
    private static function normalize(string $extension): string
    {
        return strtolower(ltrim($extension, '.'));
    }
}
