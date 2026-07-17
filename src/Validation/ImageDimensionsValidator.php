<?php

declare(strict_types=1);

/**
 * Asserts an image's pixel dimensions fall within bounds. Non-images (a subject
 * whose {@see FileSubject::dimensions} is null) are skipped silently, so this
 * rule can sit in a pipeline that also accepts non-image files.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Validation;

use PHPdot\Filesystem\Contract\Validator;

final readonly class ImageDimensionsValidator implements Validator
{
    /**
     * __construct.
     *
     * @param int $maxWidth
     * @param int $maxHeight
     * @param int $minWidth
     * @param int $minHeight
     */
    public function __construct(
        private int $maxWidth,
        private int $maxHeight,
        private int $minWidth = 0,
        private int $minHeight = 0,
    ) {}

    public function validate(FileSubject $subject): iterable
    {
        $dimensions = $subject->dimensions();
        if ($dimensions === null) {
            return;
        }

        [$width, $height] = $dimensions;

        if ($width > $this->maxWidth || $height > $this->maxHeight) {
            yield new Violation(
                'image_dimensions',
                'filesystem.image_too_large',
                "Image is {$width}x{$height}, exceeding the maximum of {$this->maxWidth}x{$this->maxHeight}.",
                ['width' => $width, 'height' => $height, 'maxWidth' => $this->maxWidth, 'maxHeight' => $this->maxHeight],
            );
        }

        if ($width < $this->minWidth || $height < $this->minHeight) {
            yield new Violation(
                'image_dimensions',
                'filesystem.image_too_small',
                "Image is {$width}x{$height}, below the minimum of {$this->minWidth}x{$this->minHeight}.",
                ['width' => $width, 'height' => $height, 'minWidth' => $this->minWidth, 'minHeight' => $this->minHeight],
            );
        }
    }
}
