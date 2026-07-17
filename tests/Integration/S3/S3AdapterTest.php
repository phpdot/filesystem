<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Tests\Integration\S3;

use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Client;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPdot\Filesystem\Adapter\S3\S3Adapter;
use PHPdot\Filesystem\Adapter\S3\S3Client;
use PHPdot\Filesystem\Adapter\S3\S3Config;
use PHPdot\Filesystem\Adapter\S3\SignatureV4;
use PHPdot\Filesystem\Config;
use PHPdot\Filesystem\Contract\AdapterInterface;
use PHPdot\Filesystem\Tests\Unit\Adapter\AdapterTestCase;

/**
 * Runs the full adapter contract suite against a real S3-compatible bucket,
 * plus S3-only checks (multipart, presigned URLs). Isolated under a unique
 * per-run key prefix and torn down afterward. Skips entirely without env.
 *
 * Configure: AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_DEFAULT_REGION,
 * PHPDOT_S3_TEST_BUCKET (and optionally PHPDOT_S3_TEST_ENDPOINT +
 * PHPDOT_S3_TEST_PATH_STYLE=1 for MinIO).
 */
final class S3AdapterTest extends AdapterTestCase
{
    private string $prefix = '';

    protected function setUp(): void
    {
        if (getenv('PHPDOT_S3_TEST_BUCKET') === false || getenv('AWS_ACCESS_KEY_ID') === false) {
            self::markTestSkipped('S3 integration not configured (set AWS creds + PHPDOT_S3_TEST_BUCKET).');
        }

        $this->prefix = 'phpdot-fs-it/' . bin2hex(random_bytes(6));
        parent::setUp();
    }

    protected function tearDown(): void
    {
        if ($this->prefix !== '') {
            $this->s3Adapter()->deleteDirectory('');
        }
    }

    protected function supportsVisibility(): bool
    {
        return false;
    }

    protected function supportsEmptyDirectories(): bool
    {
        return false;
    }

    protected function createAdapter(): AdapterInterface
    {
        return $this->s3Adapter();
    }

    public function testPresignedTemporaryUrlIsFetchable(): void
    {
        $adapter = $this->s3Adapter();
        $adapter->write('presign/obj.txt', $this->stream('presigned content'), new Config());

        $url = $adapter->temporaryUrl(
            'presign/obj.txt',
            new DateTimeImmutable('+5 minutes', new DateTimeZone('UTC')),
            new Config(),
        );

        $body = (string)(new Client())->get($url, ['http_errors' => false])->getBody();
        self::assertSame('presigned content', $body);
    }

    public function testPublicUrlPointsAtTheObject(): void
    {
        $adapter = $this->s3Adapter();

        self::assertStringContainsString('presign/obj.txt', $adapter->publicUrl('presign/obj.txt', new Config()));
    }

    public function testMultipartUploadRoundTrip(): void
    {
        $adapter = $this->s3Adapter();
        $part1 = str_repeat('A', 5 * 1024 * 1024); // 5 MiB — S3's minimum non-final part
        $part2 = 'TAIL';

        $uploadId = $adapter->createMultipart('mpu/big.bin', new Config());
        $etag1 = $adapter->uploadPart('mpu/big.bin', $uploadId, 1, $this->stream($part1), strlen($part1));
        $etag2 = $adapter->uploadPart('mpu/big.bin', $uploadId, 2, $this->stream($part2), strlen($part2));
        $adapter->completeMultipart('mpu/big.bin', $uploadId, [1 => $etag1, 2 => $etag2]);

        self::assertSame($part1 . $part2, $adapter->read('mpu/big.bin'));
    }

    public function testChecksumStreamsAndHashes(): void
    {
        $adapter = $this->s3Adapter();
        $adapter->write('sum/obj.txt', $this->stream('hash me'), new Config());

        self::assertSame(hash('sha256', 'hash me'), $adapter->checksum('sum/obj.txt', 'sha256'));
    }

    private function s3Adapter(): S3Adapter
    {
        $factory = new Psr17Factory();
        $config = new S3Config(
            bucket: getenv('PHPDOT_S3_TEST_BUCKET') ?: '',
            region: getenv('AWS_DEFAULT_REGION') ?: 'us-east-1',
            endpoint: getenv('PHPDOT_S3_TEST_ENDPOINT') ?: null,
            pathStyle: getenv('PHPDOT_S3_TEST_PATH_STYLE') === '1',
            key: getenv('AWS_ACCESS_KEY_ID') ?: null,
            secret: getenv('AWS_SECRET_ACCESS_KEY') ?: null,
            prefix: $this->prefix,
        );

        return new S3Adapter(new S3Client(new Client(), $factory, $factory, new SignatureV4(), $config), $config);
    }
}
