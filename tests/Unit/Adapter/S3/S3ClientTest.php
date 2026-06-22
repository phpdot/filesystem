<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Tests\Unit\Adapter\S3;

use DateTimeImmutable;
use DateTimeZone;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPdot\Filesystem\Adapter\S3\S3Client;
use PHPdot\Filesystem\Adapter\S3\S3Config;
use PHPdot\Filesystem\Adapter\S3\SignatureV4;
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

        self::assertSame('the contents', (string)$this->client()->getObject('a.txt'));
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

    private function client(?S3Config $config = null): S3Client
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

    private function listXml(string $key, bool $truncated, ?string $token): string
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
