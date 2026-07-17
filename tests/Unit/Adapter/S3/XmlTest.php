<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Tests\Unit\Adapter\S3;

use DOMDocument;
use PHPdot\Filesystem\Adapter\S3\Xml;
use PHPUnit\Framework\TestCase;

final class XmlTest extends TestCase
{
    private Xml $xml;

    protected function setUp(): void
    {
        $this->xml = new Xml();
    }

    public function testParsesErrorCodeAndMessage(): void
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Error><Code>NoSuchKey</Code><Message>The specified key does not exist.</Message><Key>x</Key></Error>';

        self::assertTrue($this->xml->isError($body));
        self::assertSame(
            ['code' => 'NoSuchKey', 'message' => 'The specified key does not exist.'],
            $this->xml->parseError($body),
        );
    }

    public function testIsErrorFalseForSuccessBody(): void
    {
        self::assertFalse($this->xml->isError('<Ok/>'));
        self::assertFalse($this->xml->isError(''));
    }

    public function testParsesUploadId(): void
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<InitiateMultipartUploadResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
            . '<Bucket>b</Bucket><Key>k</Key><UploadId>UPLOAD-XYZ</UploadId>'
            . '</InitiateMultipartUploadResult>';

        self::assertSame('UPLOAD-XYZ', $this->xml->parseUploadId($body));
    }

    public function testParsesListObjectsV2(): void
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
            . '<Name>bucket</Name><Prefix>docs/</Prefix><Delimiter>/</Delimiter>'
            . '<IsTruncated>true</IsTruncated><NextContinuationToken>TOKEN123</NextContinuationToken>'
            . '<Contents><Key>docs/a.txt</Key><LastModified>2023-05-01T10:00:00.000Z</LastModified>'
            . '<ETag>"abc123"</ETag><Size>42</Size></Contents>'
            . '<Contents><Key>docs/b.txt</Key><LastModified>2023-05-02T11:00:00.000Z</LastModified>'
            . '<ETag>"def456"</ETag><Size>100</Size></Contents>'
            . '<CommonPrefixes><Prefix>docs/sub/</Prefix></CommonPrefixes>'
            . '</ListBucketResult>';

        $result = $this->xml->parseListObjectsV2($body);

        self::assertTrue($result['isTruncated']);
        self::assertSame('TOKEN123', $result['nextContinuationToken']);
        self::assertSame(['docs/sub/'], $result['prefixes']);
        self::assertCount(2, $result['objects']);

        self::assertSame('docs/a.txt', $result['objects'][0]['key']);
        self::assertSame(42, $result['objects'][0]['size']);
        self::assertSame('abc123', $result['objects'][0]['etag']);
        self::assertNotNull($result['objects'][0]['lastModified']);
        self::assertGreaterThan(0, $result['objects'][0]['lastModified']);
        self::assertSame('docs/b.txt', $result['objects'][1]['key']);
        self::assertSame(100, $result['objects'][1]['size']);
    }

    public function testEmptyListIsHandled(): void
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
            . '<Name>bucket</Name><KeyCount>0</KeyCount><IsTruncated>false</IsTruncated>'
            . '</ListBucketResult>';

        $result = $this->xml->parseListObjectsV2($body);

        self::assertSame([], $result['objects']);
        self::assertSame([], $result['prefixes']);
        self::assertFalse($result['isTruncated']);
        self::assertNull($result['nextContinuationToken']);
    }

    public function testBuildsCompleteMultipartBodySortedByPartNumber(): void
    {
        $body = $this->xml->buildCompleteMultipartBody([2 => '"etag-two"', 1 => '"etag-one"']);

        self::assertStringContainsString('<PartNumber>1</PartNumber>', $body);
        self::assertStringContainsString('<PartNumber>2</PartNumber>', $body);
        self::assertLessThan(
            strpos($body, '<PartNumber>2</PartNumber>'),
            strpos($body, '<PartNumber>1</PartNumber>'),
        );
        self::assertStringContainsString('&quot;etag-one&quot;', $body);

        // The generated body is well-formed XML.
        self::assertTrue((new DOMDocument())->loadXML($body));
    }
}
