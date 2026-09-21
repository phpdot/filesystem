<?php

declare(strict_types=1);

namespace PHPdot\Filesystem\Tests\Integration\S3;

use DateTimeImmutable;
use DateTimeZone;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPdot\Filesystem\Adapter\S3\S3Adapter;
use PHPdot\Filesystem\Adapter\S3\S3Client;
use PHPdot\Filesystem\Adapter\S3\S3Config;
use PHPdot\Filesystem\Adapter\S3\SignatureV4;
use PHPdot\Filesystem\Checksum\Crc64Nvme;
use PHPdot\Filesystem\Config;
use PHPdot\Filesystem\Contract\AdapterInterface;
use PHPdot\Filesystem\Tests\Unit\Adapter\AdapterTestCase;
use PHPdot\Filesystem\Upload\PresignedUpload;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * Runs the full adapter contract suite against a real S3-compatible bucket,
 * plus S3-only checks (multipart, presigned URLs). Isolated under a unique
 * per-run key prefix and torn down afterward. Skips entirely without env.
 *
 * Configure: AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_DEFAULT_REGION,
 * PHPDOT_S3_TEST_BUCKET (and optionally PHPDOT_S3_TEST_ENDPOINT +
 * PHPDOT_S3_TEST_PATH_STYLE=1 for MinIO).
 */
#[Group('integration')]
#[Group('s3')]
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

        $body = (string) (new Psr18Client())->sendRequest((new Psr17Factory())->createRequest('GET', $url))->getBody();
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

    public function testDirectLaneMultipartKeepsTheCompositeChecksumByHead(): void
    {
        $adapter = $this->s3Adapter();
        $factory = new Psr17Factory();
        $http = new Psr18Client();
        $part1 = str_repeat('B', 5 * 1024 * 1024);
        $part2 = 'TAIL2';
        $expires = new DateTimeImmutable('+5 minutes', new DateTimeZone('UTC'));

        $uploadId = $adapter->createMultipart('mpu/sum.bin', new Config([Config::CHECKSUM_ALGORITHM => 'SHA256']));
        $grant1 = $adapter->presignedPartUpload('mpu/sum.bin', $uploadId, 1, $expires, contentType: null, checksumBase64: base64_encode(hash('sha256', $part1, true)), checksumAlgorithm: 'SHA256', size: strlen($part1), config: new Config());
        $grant2 = $adapter->presignedPartUpload('mpu/sum.bin', $uploadId, 2, $expires, contentType: null, checksumBase64: base64_encode(hash('sha256', $part2, true)), checksumAlgorithm: 'SHA256', size: strlen($part2), config: new Config());

        foreach ([[$grant1, $part1], [$grant2, $part2]] as [$grant, $body]) {
            $request = $factory->createRequest('PUT', $grant->url)->withBody($factory->createStream($body));
            foreach ($grant->headers as $name => $value) {
                $request = $request->withHeader($name, $value);
            }
            $http->sendRequest($request);
        }

        $parts = $adapter->listParts('mpu/sum.bin', $uploadId);
        $adapter->completeMultipart('mpu/sum.bin', $uploadId, $parts);

        self::assertSame($part1 . $part2, $adapter->read('mpu/sum.bin'));

        $composite = hash('sha256', hash('sha256', $part1, true) . hash('sha256', $part2, true)) . '-2';
        self::assertSame($composite, $adapter->checksum('mpu/sum.bin', 'sha256'));
        self::assertSame('sha256:' . $composite, $adapter->storedChecksum('mpu/sum.bin'));
    }

    public function testCrc64PartGrantsRefuseACorruptedPieceAtTheDoor(): void
    {
        $adapter = $this->s3Adapter();
        $factory = new Psr17Factory();
        $http = new Psr18Client();
        $part1 = str_repeat('G', 5 * 1024 * 1024);
        $part2 = 'TAIL7';
        $expires = new DateTimeImmutable('+5 minutes', new DateTimeZone('UTC'));

        $uploadId = $adapter->createMultipart('mpu/crc.bin', new Config());
        $honest = $adapter->presignedPartUpload('mpu/crc.bin', $uploadId, 1, $expires, contentType: null, checksumBase64: Crc64Nvme::base64Digest($part1), checksumAlgorithm: 'CRC64NVME', size: strlen($part1), config: new Config());
        $lying = $adapter->presignedPartUpload('mpu/crc.bin', $uploadId, 2, $expires, contentType: null, checksumBase64: Crc64Nvme::base64Digest('different bytes'), checksumAlgorithm: 'CRC64NVME', size: strlen($part2), config: new Config());

        $send = static function (PresignedUpload $grant, string $body) use ($factory, $http): int {
            $request = $factory->createRequest('PUT', $grant->url)->withBody($factory->createStream($body));
            foreach ($grant->headers as $name => $value) {
                $request = $request->withHeader($name, $value);
            }

            return $http->sendRequest($request)->getStatusCode();
        };

        self::assertSame(200, $send($honest, $part1));
        self::assertSame(400, $send($lying, $part2), 'a piece whose bytes do not match its signed digest is refused at the door');

        $parts = $adapter->listParts('mpu/crc.bin', $uploadId);
        $adapter->completeMultipart('mpu/crc.bin', $uploadId, $parts);
        self::assertSame($part1, substr($adapter->read('mpu/crc.bin'), 0, strlen($part1)));
    }

    public function testChecksumStreamsAndHashes(): void
    {
        $adapter = $this->s3Adapter();
        $adapter->write('sum/obj.txt', $this->stream('hash me'), new Config());

        self::assertSame(hash('sha256', 'hash me'), $adapter->checksum('sum/obj.txt', 'sha256'));
        self::assertSame('sha256:' . hash('sha256', 'hash me'), $adapter->storedChecksum('sum/obj.txt'));
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

        return new S3Adapter(new S3Client(new Psr18Client(), $factory, $factory, new SignatureV4(), $config), $config);
    }
}
