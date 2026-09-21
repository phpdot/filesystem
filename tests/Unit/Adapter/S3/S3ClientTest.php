<?php

declare(strict_types=1);

namespace PHPdot\Filesystem\Tests\Unit\Adapter\S3;

use DateTimeImmutable;
use DateTimeZone;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPdot\Filesystem\Adapter\S3\S3Adapter;
use PHPdot\Filesystem\Adapter\S3\S3Client;
use PHPdot\Filesystem\Adapter\S3\S3Config;
use PHPdot\Filesystem\Adapter\S3\SignatureV4;
use PHPdot\Filesystem\Config;
use PHPdot\Filesystem\Exception\InvalidConfigurationValue;
use PHPdot\Filesystem\Exception\MultipartUploadFailed;
use PHPdot\Filesystem\Exception\S3RequestFailed;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class S3ClientTest extends TestCase
{
    private Psr17Factory $factory;
    private CapturingHttpClient $http;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
        $this->http = new CapturingHttpClient();
    }

    public function testPutObjectUsesVirtualHostedUrlAndSignedHeaders(): void
    {
        $this->http->responses[] = $this->response(200);
        $client = $this->client();

        $client->putObject('a/b.txt', $this->factory->createStream('payload'), 7, ['Content-Type' => 'text/plain']);

        $request = $this->http->requests[0];
        self::assertSame('PUT', $request->getMethod());
        self::assertSame('phpdot-test.s3.us-east-1.amazonaws.com', $request->getUri()->getHost());
        self::assertSame('/a/b.txt', $request->getUri()->getPath());
        self::assertSame('7', $request->getHeaderLine('Content-Length'));
        self::assertNotSame('', $request->getHeaderLine('x-amz-content-sha256'));
        self::assertStringStartsWith('AWS4-HMAC-SHA256 ', $request->getHeaderLine('Authorization'));
    }

    public function testPathStyleEndpointForMinio(): void
    {
        $this->http->responses[] = $this->response(200);
        $client = $this->client(new S3Config(
            bucket: 'phpdot-test',
            region: 'us-east-1',
            endpoint: 'http://localhost:9000',
            pathStyle: true,
            key: 'k',
            secret: 's',
        ));

        $client->putObject('k.txt', $this->factory->createStream('x'), 1);

        $uri = $this->http->requests[0]->getUri();
        self::assertSame('localhost', $uri->getHost());
        self::assertSame(9000, $uri->getPort());
        self::assertSame('/phpdot-test/k.txt', $uri->getPath());
    }

    public function testGetObjectReturnsBody(): void
    {
        $this->http->responses[] = $this->response(200, 'the contents');

        self::assertSame('the contents', (string) $this->client()->getObject('a.txt'));
    }

    public function testErrorResponseThrowsS3RequestFailedWithStatusAndCode(): void
    {
        $errorXml = '<?xml version="1.0"?><Error><Code>NoSuchKey</Code><Message>Key not found.</Message></Error>';
        $this->http->responses[] = $this->response(404, $errorXml);

        try {
            $this->client()->getObject('missing.txt');
            self::fail('Expected S3RequestFailed.');
        } catch (S3RequestFailed $exception) {
            self::assertSame(404, $exception->status());
            self::assertSame('NoSuchKey', $exception->awsErrorCode());
        }
    }

    public function testListObjectsV2PaginatesWithContinuationToken(): void
    {
        $this->http->responses[] = $this->response(200, $this->listXml('a.txt', truncated: true, token: 'TOK'));
        $this->http->responses[] = $this->response(200, $this->listXml('b.txt', truncated: false, token: null));

        $entries = iterator_to_array($this->client()->listObjectsV2('', true), false);

        self::assertCount(2, $entries);
        self::assertSame('a.txt', $entries[0]['key']);
        self::assertSame('b.txt', $entries[1]['key']);
        self::assertCount(2, $this->http->requests);
        self::assertStringContainsString('continuation-token=TOK', $this->http->requests[1]->getUri()->getQuery());
    }

    public function testUploadPartSendsSizedUnsignedPayload(): void
    {
        $this->http->responses[] = $this->response(200, '', ['ETag' => '"part-etag"']);

        $etag = $this->client()->uploadPart('big.bin', 'UP1', 2, $this->factory->createStream('chunkbytes'), 10);

        self::assertSame('part-etag', $etag);
        $request = $this->http->requests[0];
        self::assertSame('10', $request->getHeaderLine('Content-Length'));
        self::assertSame('UNSIGNED-PAYLOAD', $request->getHeaderLine('x-amz-content-sha256'));
        self::assertStringContainsString('partNumber=2', $request->getUri()->getQuery());
        self::assertStringContainsString('uploadId=UP1', $request->getUri()->getQuery());
    }

    public function testCompleteMultipartWith200ErrorBodyThrows(): void
    {
        $this->http->responses[] = $this->response(200, '<?xml version="1.0"?><Error><Code>InternalError</Code><Message>boom</Message></Error>');

        $this->expectException(MultipartUploadFailed::class);

        $this->client()->completeMultipartUpload('big.bin', 'UP1', [1 => '"e1"', 2 => '"e2"']);
    }

    public function testPresignProducesQuerySignedUrl(): void
    {
        $url = $this->client()->presign('a/b.txt', new DateTimeImmutable('+5 minutes', new DateTimeZone('UTC')));

        self::assertStringContainsString('X-Amz-Algorithm=AWS4-HMAC-SHA256', $url);
        self::assertStringContainsString('X-Amz-Signature=', $url);
        self::assertStringContainsString('X-Amz-Credential=', $url);
        self::assertSame([], $this->http->requests);
    }

    public function testPresignPutGrantsOnePutWithHostOnlySignature(): void
    {
        $grant = $this->client()->presignPut('a/b.txt', new DateTimeImmutable('+10 minutes'));

        self::assertSame('PUT', $grant->method);
        self::assertSame('a/b.txt', $grant->key);
        self::assertSame([], $grant->headers, 'no digest, no content type — nothing to send verbatim');

        parse_str(parse_url($grant->url, PHP_URL_QUERY), $params);
        self::assertSame('host', $params['X-Amz-SignedHeaders']);
        self::assertSame('600', $params['X-Amz-Expires']);
        self::assertArrayHasKey('X-Amz-Signature', $params);
    }

    public function testAGrantSignsTheDigestValueWhenGiven(): void
    {
        $digest = base64_encode(hash('sha256', 'bytes', true));
        $grant = $this->client()->presignPut('a.bin', new DateTimeImmutable('+5 minutes'), null, $digest);

        self::assertSame(['X-Amz-Checksum-Sha256' => $digest], $grant->headers, 'the client sends the digest verbatim; S3 verifies it against the bytes and stores it');

        parse_str(parse_url($grant->url, PHP_URL_QUERY), $params);
        self::assertSame('host;x-amz-checksum-sha256', $params['X-Amz-SignedHeaders']);
    }

    public function testAPartGrantSignsItsOwnPartDigest(): void
    {
        $digest = base64_encode(hash('sha256', 'part-bytes', true));
        $grant = $this->client()->presignPartPut('a.bin', 'u1', 1, new DateTimeImmutable('+5 minutes'), null, $digest);

        self::assertSame(['X-Amz-Checksum-Sha256' => $digest], $grant->headers);

        parse_str(parse_url($grant->url, PHP_URL_QUERY), $params);
        self::assertSame('host;x-amz-checksum-sha256', $params['X-Amz-SignedHeaders']);
    }

    public function testPresignPutPinsContentTypeIntoTheSignature(): void
    {
        $grant = $this->client()->presignPut('a/b.txt', new DateTimeImmutable('+10 minutes'), 'image/png');

        self::assertSame(['Content-Type' => 'image/png'], $grant->headers, 'the grant names what the client must send verbatim');

        parse_str(parse_url($grant->url, PHP_URL_QUERY), $params);
        self::assertSame('content-type;host', $params['X-Amz-SignedHeaders'], 'signed headers sort alphabetically');
    }

    public function testPresignPutSignatureDiffersPerContentType(): void
    {
        $expires = new DateTimeImmutable('+10 minutes');
        $png = $this->client()->presignPut('a/b.txt', $expires, 'image/png');
        $html = $this->client()->presignPut('a/b.txt', $expires, 'text/html');

        self::assertNotSame(
            parse_url($png->url, PHP_URL_QUERY),
            parse_url($html->url, PHP_URL_QUERY),
            'the content type is part of the signature — a swapped type is rejected by the bucket',
        );
    }

    public function testPresignPutPinsDeclaredSizeIntoTheSignature(): void
    {
        $expires = new DateTimeImmutable('+10 minutes');
        $grant = $this->client()->presignPut('a/b.txt', $expires, null, null, 12);
        $other = $this->client()->presignPut('a/b.txt', $expires, null, null, 13);

        self::assertSame('12', $grant->headers['Content-Length']);
        self::assertStringContainsString(
            'X-Amz-SignedHeaders=content-length%3Bhost',
            (string) parse_url($grant->url, PHP_URL_QUERY),
        );
        self::assertNotSame(parse_url($grant->url, PHP_URL_QUERY), parse_url($other->url, PHP_URL_QUERY));
    }

    public function testPresignPartPutPinsDeclaredSizeIntoTheSignature(): void
    {
        $expires = new DateTimeImmutable('+10 minutes');
        $grant = $this->client()->presignPartPut('a/b.bin', 'UP1', 1, $expires, size: 6);

        self::assertSame('6', $grant->headers['Content-Length']);
        self::assertSame(1, $grant->partNumber);
        self::assertStringContainsString(
            'X-Amz-SignedHeaders=content-length%3Bhost',
            (string) parse_url($grant->url, PHP_URL_QUERY),
        );
    }

    public function testPresignPartPutSignsTheChecksumUnderTheChosenAlgorithm(): void
    {
        $expires = new DateTimeImmutable('+10 minutes');
        $grant = $this->client()->presignPartPut('a/b.bin', 'UP1', 1, $expires, checksumBase64: 'AcOrFCa+XCM=', checksumAlgorithm: 'CRC64NVME');

        self::assertSame('AcOrFCa+XCM=', $grant->headers['X-Amz-Checksum-Crc64nvme']);
        self::assertStringContainsString(
            'X-Amz-SignedHeaders=host%3Bx-amz-checksum-crc64nvme',
            (string) parse_url($grant->url, PHP_URL_QUERY),
        );

        $sha = $this->client()->presignPartPut('a/b.bin', 'UP1', 1, $expires, checksumBase64: 'AcOrFCa+XCM=');
        self::assertSame('AcOrFCa+XCM=', $sha->headers['X-Amz-Checksum-Sha256']);
    }

    public function testPresignPartPutRefusesAnUnknownChecksumAlgorithm(): void
    {
        $this->expectException(InvalidConfigurationValue::class);
        $this->expectExceptionMessage('SHA256 or CRC64NVME');

        $this->client()->presignPartPut('a/b.bin', 'UP1', 1, new DateTimeImmutable('+10 minutes'), checksumBase64: 'x', checksumAlgorithm: 'CRC32');
    }

    public function testHeadObjectDecodesPlainCompositeAndUndecodableChecksums(): void
    {
        $digest = str_repeat('ab', 32);
        $this->http->responses[] = $this->response(200, '', [
            'x-amz-checksum-sha256' => base64_encode((string) hex2bin($digest)),
        ]);
        $this->http->responses[] = $this->response(200, '', [
            'x-amz-checksum-sha256' => base64_encode((string) hex2bin($digest)) . '-150',
        ]);
        $this->http->responses[] = $this->response(200, '', [
            'x-amz-checksum-sha256' => '###',
        ]);

        self::assertSame($digest, $this->client()->headObject('a.txt')['checksumSha256']);
        self::assertSame($digest . '-150', $this->client()->headObject('a.txt')['checksumSha256']);
        self::assertNull($this->client()->headObject('a.txt')['checksumSha256']);
    }

    public function testHeadObjectSurfacesCrc64AndChecksumTypeVerbatim(): void
    {
        $this->http->responses[] = $this->response(200, '', [
            'x-amz-checksum-crc64nvme' => 'AcOrFCa+XCM=-2',
            'x-amz-checksum-type' => 'COMPOSITE',
        ]);
        $this->http->responses[] = $this->response(200, '');

        $head = $this->client()->headObject('a.txt');
        self::assertSame('AcOrFCa+XCM=-2', $head['checksumCrc64']);
        self::assertSame('COMPOSITE', $head['checksumType']);
        self::assertNull($head['checksumSha256']);

        $head = $this->client()->headObject('a.txt');
        self::assertNull($head['checksumCrc64']);
        self::assertNull($head['checksumType']);
    }

    public function testStoredChecksumPrefersSha256ThenCrc64AndNeverReadsTheObject(): void
    {
        $adapter = new S3Adapter(
            $this->client(),
            new S3Config(bucket: 'phpdot-test', region: 'us-east-1', key: 'AKIDEXAMPLE', secret: 'secret'),
        );
        $digest = str_repeat('ab', 32);
        $this->http->responses[] = $this->response(200, '', [
            'x-amz-checksum-sha256' => base64_encode((string) hex2bin($digest)),
            'x-amz-checksum-crc64nvme' => 'AcOrFCa+XCM=',
        ]);
        $this->http->responses[] = $this->response(200, '', [
            'x-amz-checksum-crc64nvme' => 'AcOrFCa+XCM=',
        ]);
        $this->http->responses[] = $this->response(200, '');

        self::assertSame('sha256:' . $digest, $adapter->storedChecksum('a.txt'));
        self::assertSame('crc64nvme:AcOrFCa+XCM=', $adapter->storedChecksum('a.txt'));
        self::assertNull($adapter->storedChecksum('a.txt'));

        foreach ($this->http->requests as $request) {
            self::assertSame('HEAD', $request->getMethod());
        }
    }

    public function testCreateMultipartCarriesTheChecksumAlgorithmDirective(): void
    {
        $adapter = new S3Adapter(
            $this->client(),
            new S3Config(bucket: 'phpdot-test', region: 'us-east-1', key: 'AKIDEXAMPLE', secret: 'secret'),
        );
        $this->http->responses[] = $this->response(
            200,
            '<?xml version="1.0" encoding="UTF-8"?><InitiateMultipartUploadResult><UploadId>UP9</UploadId></InitiateMultipartUploadResult>',
        );

        $adapter->createMultipart('a/b.bin', new Config([Config::CHECKSUM_ALGORITHM => 'SHA256']));

        self::assertSame('SHA256', $this->http->requests[0]->getHeaderLine('x-amz-checksum-algorithm'));
    }

    public function testListPartsFollowsPaginationAndReturnsTheBucketsOwnParts(): void
    {
        $this->http->responses[] = $this->response(200, $this->listPartsXml(true, null, '"etag-1"', '"etag-2"'));
        $this->http->responses[] = $this->response(200, $this->listPartsXml(false, 2, '"etag-3"'));

        $parts = $this->client()->listParts('a/b.bin', 'upload-1');

        self::assertSame([
            1 => ['etag' => '"etag-1"', 'size' => 8, 'checksumSha256' => null, 'checksumCrc64' => null],
            2 => ['etag' => '"etag-2"', 'size' => 8, 'checksumSha256' => null, 'checksumCrc64' => null],
            3 => ['etag' => '"etag-3"', 'size' => 4, 'checksumSha256' => null, 'checksumCrc64' => null],
        ], $parts);

        $second = $this->http->requests[1]->getUri();
        self::assertStringContainsString('part-number-marker=2', $second->getQuery(), 'page two continues after the marker');
    }

    public function testPresignedPartGrantSignsPartNumberAndUploadId(): void
    {
        $grant = $this->client()->presignPartPut('a/big.bin', 'upload-xyz', 2, new DateTimeImmutable('+10 minutes'));

        self::assertSame(2, $grant->partNumber);
        self::assertSame('upload-xyz', $grant->uploadId);

        $query = parse_url($grant->url, PHP_URL_QUERY);
        self::assertStringContainsString('partNumber=2', $query);
        self::assertStringContainsString('uploadId=upload-xyz', $query);

        parse_str($query, $params);
        self::assertSame('host', $params['X-Amz-SignedHeaders']);
        self::assertArrayHasKey('X-Amz-Signature', $params);
    }

    public function testPartGrantsDifferPerPartNumber(): void
    {
        $expires = new DateTimeImmutable('+10 minutes');
        $one = $this->client()->presignPartPut('a/big.bin', 'upload-xyz', 1, $expires);
        $two = $this->client()->presignPartPut('a/big.bin', 'upload-xyz', 2, $expires);

        self::assertNotSame(
            parse_url($one->url, PHP_URL_QUERY),
            parse_url($two->url, PHP_URL_QUERY),
            'partNumber is inside the canonical query — each part is its own signature',
        );
    }

    public function testPartGrantPinsContentTypeLikeAWholeObjectGrant(): void
    {
        $grant = $this->client()->presignPartPut('a/big.bin', 'upload-xyz', 1, new DateTimeImmutable('+10 minutes'), 'video/mp4');

        self::assertSame(['Content-Type' => 'video/mp4'], $grant->headers);

        parse_str(parse_url($grant->url, PHP_URL_QUERY), $params);
        self::assertSame('content-type;host', $params['X-Amz-SignedHeaders']);
    }

    public function testPartGrantToArrayCarriesThePartIdentity(): void
    {
        $grant = $this->client()->presignPartPut('a/big.bin', 'upload-xyz', 3, new DateTimeImmutable('+10 minutes'));
        $shape = $grant->toArray();

        self::assertSame(3, $shape['partNumber']);
        self::assertSame('upload-xyz', $shape['uploadId']);
        self::assertArrayNotHasKey('partNumber', $this->client()->presignPut('a.txt', new DateTimeImmutable('+1 minute'))->toArray(), 'whole-object grants carry no part identity');
    }

    public function testPutObjectDoesNotSendTheBareChecksumDirective(): void
    {
        $this->http->responses[] = $this->response(200);

        $this->client()->putObject('a.txt', $this->factory->createStream('x'), 1);

        self::assertSame('', $this->http->requests[0]->getHeaderLine('x-amz-sdk-checksum-algorithm'), 'AWS 400s on the bare directive with header auth; grants only');
    }



    public function testHeadObjectReturnsTheStoredChecksumAsHex(): void
    {
        $digest = hash('sha256', 'payload', true);
        $this->http->responses[] = $this->response(200, '', [
            'Content-Length' => '7',
            'x-amz-checksum-sha256' => base64_encode($digest),
        ]);

        $head = $this->client()->headObject('a.txt');

        self::assertSame(hash('sha256', 'payload'), $head['checksumSha256'], 'base64 from the bucket becomes the same hex hash_file would produce');
        self::assertSame('ENABLED', $this->http->requests[0]->getHeaderLine('x-amz-checksum-mode'));
    }

    public function testHeadObjectLeavesChecksumNullWhenStorageHasNone(): void
    {
        $this->http->responses[] = $this->response(200, '', ['Content-Length' => '7']);

        $head = $this->client()->headObject('a.txt');

        self::assertNull($head['checksumSha256'], 'an object uploaded before the checksum directive has none stored');
    }

    public function testChecksumUsesTheStoredDigestWithoutDownloading(): void
    {
        $stored = hash('sha256', 'the-object-bytes', true);
        $this->http->responses[] = $this->response(200, '', ['x-amz-checksum-sha256' => base64_encode($stored)]);

        $adapter = new S3Adapter($this->client(), new S3Config(bucket: 'b', region: 'r', key: 'k', secret: 's'));

        self::assertSame(hash('sha256', 'the-object-bytes'), $adapter->checksum('a.txt', 'sha256'));
        self::assertSame('HEAD', $this->http->requests[0]->getMethod(), 'no GET — the object never crosses the wire');
    }

    public function testChecksumFallsBackToStreamingWhenNothingIsStored(): void
    {
        $this->http->responses[] = $this->response(200, '', []);
        $this->http->responses[] = $this->response(200, 'legacy-bytes');

        $adapter = new S3Adapter($this->client(), new S3Config(bucket: 'b', region: 'r', key: 'k', secret: 's'));

        self::assertSame(hash('sha256', 'legacy-bytes'), $adapter->checksum('a.txt', 'sha256'), 'objects uploaded before the directive still checksum correctly, by download');
    }

    private function client(null|S3Config $config = null): S3Client
    {
        return new S3Client(
            $this->http,
            $this->factory,
            $this->factory,
            new SignatureV4(),
            $config ?? new S3Config(bucket: 'phpdot-test', region: 'us-east-1', key: 'AKIDEXAMPLE', secret: 'secret'),
        );
    }

    /**
     * @param array<string,string> $headers
     */
    private function response(int $status, string $body = '', array $headers = []): ResponseInterface
    {
        $response = $this->factory->createResponse($status)->withBody($this->factory->createStream($body));
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    private function listPartsXml(bool $truncated, null|int $marker, string ...$etags): string
    {
        $parts = '';
        $number = $marker ?? 0;

        foreach ($etags as $etag) {
            ++$number;
            $parts .= "<Part><PartNumber>{$number}</PartNumber><ETag>{$etag}</ETag><Size>" . ($number === 3 ? 4 : 8) . '</Size></Part>';
        }

        $markerXml = $truncated ? "<NextPartNumberMarker>{$number}</NextPartNumberMarker>" : '';

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<ListPartsResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
            . '<IsTruncated>' . ($truncated ? 'true' : 'false') . '</IsTruncated>' . $markerXml
            . $parts
            . '</ListPartsResult>';
    }

    private function listXml(string $key, bool $truncated, null|string $token): string
    {
        $tokenXml = $token === null ? '' : "<NextContinuationToken>{$token}</NextContinuationToken>";

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
            . '<IsTruncated>' . ($truncated ? 'true' : 'false') . '</IsTruncated>' . $tokenXml
            . "<Contents><Key>{$key}</Key><Size>5</Size><ETag>\"e\"</ETag>"
            . '<LastModified>2023-05-01T10:00:00.000Z</LastModified></Contents>'
            . '</ListBucketResult>';
    }
}
