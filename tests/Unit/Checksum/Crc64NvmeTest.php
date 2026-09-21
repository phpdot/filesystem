<?php

declare(strict_types=1);

namespace PHPdot\Filesystem\Tests\Unit\Checksum;

use PHPdot\Filesystem\Checksum\Crc64Nvme;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Vectors: the catalogue check value ("123456789"), plus digests R2 and S3
 * verified against real bytes — the big-endian wire form is load-bearing.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */
final class Crc64NvmeTest extends TestCase
{
    #[Test]
    public function matchesTheCatalogueCheckValue(): void
    {
        self::assertSame('ae8b14860a799888', Crc64Nvme::hexChecksum('123456789'));
    }

    #[Test]
    public function digestsBytesBigEndianAsTheWireWants(): void
    {
        $digest = Crc64Nvme::base64Digest('r2 ground truth');
        self::assertSame('F67EejBum58=', $digest);
        self::assertSame(bin2hex((string) base64_decode($digest, true)), Crc64Nvme::hexChecksum('r2 ground truth'));
    }

    #[Test]
    public function emptyInputIsTheXoroutOfTheInit(): void
    {
        self::assertSame('AAAAAAAAAAA=', Crc64Nvme::base64Digest(''));
        self::assertSame('0000000000000000', Crc64Nvme::hexChecksum(''));
    }
}
