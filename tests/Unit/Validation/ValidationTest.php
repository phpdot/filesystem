<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Tests\Unit\Validation;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPdot\Filesystem\Exception\FileValidationFailed;
use PHPdot\Filesystem\Validation\ExtensionValidator;
use PHPdot\Filesystem\Validation\FileSizeValidator;
use PHPdot\Filesystem\Validation\FileSubject;
use PHPdot\Filesystem\Validation\ImageDimensionsValidator;
use PHPdot\Filesystem\Validation\MimeTypeValidator;
use PHPdot\Filesystem\Validation\ValidatorPipeline;
use PHPdot\Filesystem\Write\WriteContents;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

final class ValidationTest extends TestCase
{
    public function testPipelineCollectsAllViolations(): void
    {
        $subject = $this->subject('hello world', 'note.exe');

        $result = (new ValidatorPipeline(
            new FileSizeValidator(maxBytes: 5),
            new ExtensionValidator(['txt', 'md']),
        ))->validate($subject);

        self::assertFalse($result->isValid());
        self::assertCount(2, $result->violations());
        $codes = array_map(static fn($v) => $v->code, $result->violations());
        self::assertContains('filesystem.file_too_large', $codes);
        self::assertContains('filesystem.extension_not_allowed', $codes);
    }

    public function testValidSubjectProducesNoViolations(): void
    {
        $subject = $this->subject('hello', 'note.txt');

        $result = (new ValidatorPipeline(
            new FileSizeValidator(maxBytes: 1024, minBytes: 1),
            new ExtensionValidator(['.txt']),
        ))->validate($subject);

        self::assertTrue($result->isValid());
        self::assertSame([], $result->violations());
    }

    public function testThrowIfInvalidCarriesEveryViolation(): void
    {
        $subject = $this->subject('xxxxxxxxxx', 'a.bin');

        $result = (new ValidatorPipeline(
            new FileSizeValidator(maxBytes: 3),
            new ExtensionValidator(['txt']),
        ))->validate($subject);

        try {
            $result->throwIfInvalid();
            self::fail('Expected FileValidationFailed.');
        } catch (FileValidationFailed $e) {
            self::assertCount(2, $e->violations());
            self::assertSame('filesystem.validation_failed', $e->errorCode());
        }
    }

    public function testMimeTypeValidatorSniffsContent(): void
    {
        // Declared as .txt but content is a PNG header — content sniffing wins.
        $subject = $this->subject($this->pngBytes(), 'fake.txt');

        $result = (new ValidatorPipeline(new MimeTypeValidator(['image/png'])))->validate($subject);

        self::assertTrue($result->isValid(), 'PNG content should be detected regardless of declared name.');
    }

    public function testImageDimensionsValidatorSkipsNonImages(): void
    {
        $subject = $this->subject('just text', 'a.txt');

        $result = (new ValidatorPipeline(new ImageDimensionsValidator(maxWidth: 1, maxHeight: 1)))->validate($subject);

        self::assertTrue($result->isValid(), 'Non-image subjects must be skipped by the dimensions rule.');
    }

    public function testImageDimensionsValidatorRejectsOversizedImage(): void
    {
        $subject = $this->subject($this->pngBytes(), 'pixel.png');

        $result = (new ValidatorPipeline(new ImageDimensionsValidator(maxWidth: 0, maxHeight: 0)))->validate($subject);

        self::assertFalse($result->isValid());
        self::assertSame('filesystem.image_too_large', $result->violations()[0]->code);
    }

    public function testFileSubjectBuffersNonSeekableStreamForSniffThenWrite(): void
    {
        $nonSeekable = new NonSeekableStream('streamed body');
        $subject = FileSubject::fromContents($nonSeekable, 'x.txt', $this->writeContents(), new Psr17Factory());

        // Sniff first...
        self::assertSame(13, $subject->size());
        self::assertStringStartsWith('text/', $subject->mimeType());

        // ...then the body must still be fully readable for the write.
        self::assertSame('streamed body', $subject->stream()->getContents());
    }

    private function subject(string $contents, string $name): FileSubject
    {
        return FileSubject::fromContents($contents, $name, $this->writeContents(), new Psr17Factory());
    }

    private function writeContents(): WriteContents
    {
        return new WriteContents(new Psr17Factory());
    }

    private function pngBytes(): string
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('ext-gd is required to generate the test image.');
        }

        $image = imagecreatetruecolor(2, 2);
        self::assertNotFalse($image);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        self::assertIsString($bytes);

        return $bytes;
    }
}

/**
 * A minimal non-seekable, read-once stream to prove {@see FileSubject} buffers.
 */
final class NonSeekableStream implements StreamInterface
{
    private int $pos = 0;

    public function __construct(private readonly string $contents) {}

    public function __toString(): string
    {
        return $this->contents;
    }

    public function close(): void {}

    public function detach()
    {
        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return $this->pos;
    }

    public function eof(): bool
    {
        return $this->pos >= strlen($this->contents);
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new RuntimeException('Not seekable.');
    }

    public function rewind(): void
    {
        throw new RuntimeException('Not seekable.');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('Not writable.');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        $chunk = substr($this->contents, $this->pos, $length);
        $this->pos += strlen($chunk);

        return $chunk;
    }

    public function getContents(): string
    {
        return $this->read(PHP_INT_MAX);
    }

    public function getMetadata(?string $key = null)
    {
        return $key === null ? [] : null;
    }
}
