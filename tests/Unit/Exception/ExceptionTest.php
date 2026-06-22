<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Tests\Unit\Exception;

use InvalidArgumentException;
use PHPdot\Filesystem\Exception\FilesystemException;
use PHPdot\Filesystem\Exception\FilesystemOperationFailed;
use PHPdot\Filesystem\Exception\InvalidStreamProvided;
use PHPdot\Filesystem\Exception\PathTraversalDetected;
use PHPdot\Filesystem\Exception\S3RequestFailed;
use PHPdot\Filesystem\Exception\UnableToMoveFile;
use PHPdot\Filesystem\Exception\UnableToRetrieveMetadata;
use PHPdot\Filesystem\Exception\UnableToWriteFile;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ExceptionTest extends TestCase
{
    public function testWriteFailureIsAnOperationFailureWithStableCode(): void
    {
        $previous = new RuntimeException('disk full');
        $exception = UnableToWriteFile::atLocation('foo/bar.txt', 'permission denied', $previous);

        self::assertInstanceOf(FilesystemOperationFailed::class, $exception);
        self::assertInstanceOf(FilesystemException::class, $exception);
        self::assertSame('filesystem.write_failed', $exception->errorCode());
        self::assertSame('WRITE', $exception->operation());
        self::assertStringContainsString('foo/bar.txt', $exception->getMessage());
        self::assertStringContainsString('permission denied', $exception->getMessage());
        self::assertSame($previous, $exception->getPrevious());
    }

    public function testMoveFailureMentionsBothLocations(): void
    {
        $exception = UnableToMoveFile::fromTo('a.txt', 'b.txt');

        self::assertSame('MOVE', $exception->operation());
        self::assertStringContainsString('a.txt', $exception->getMessage());
        self::assertStringContainsString('b.txt', $exception->getMessage());
    }

    public function testMetadataFailureTracksType(): void
    {
        $exception = UnableToRetrieveMetadata::mimeType('x', 'boom');

        self::assertSame('mimeType', $exception->metadataType());
        self::assertSame('filesystem.retrieve_metadata_failed', $exception->errorCode());
    }

    public function testInvalidStreamIsNotAnOperationFailure(): void
    {
        $exception = InvalidStreamProvided::becauseNotReadable();

        self::assertInstanceOf(FilesystemException::class, $exception);
        self::assertNotInstanceOf(FilesystemOperationFailed::class, $exception);
        self::assertInstanceOf(InvalidArgumentException::class, $exception);
    }

    public function testPathTraversalCarriesPath(): void
    {
        $exception = PathTraversalDetected::forPath('../etc/passwd');

        self::assertSame('filesystem.path_traversal', $exception->errorCode());
        self::assertStringContainsString('../etc/passwd', $exception->getMessage());
    }

    public function testS3RequestFailureCarriesStatusAndCode(): void
    {
        $exception = S3RequestFailed::create(404, 'NoSuchKey', 'The specified key does not exist.');

        self::assertSame(404, $exception->status());
        self::assertSame('NoSuchKey', $exception->awsErrorCode());
        self::assertStringContainsString('404', $exception->getMessage());
        self::assertStringContainsString('NoSuchKey', $exception->getMessage());
    }
}
