<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Tests\Unit\Adapter\S3;

use DateTimeImmutable;
use DateTimeZone;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPdot\Filesystem\Adapter\S3\SignatureV4;
use PHPdot\Filesystem\Adapter\S3\SigningContext;
use PHPUnit\Framework\TestCase;

/**
 * Gates every build on AWS's published Signature Version 4 test vectors
 * (the official aws-sig-v4-test-suite). Credentials, region, service "service"
 * and the 20150830T123600Z timestamp are fixed by that suite.
 */
final class SignatureV4Test extends TestCase
{
    private const ACCESS_KEY = 'AKIDEXAMPLE';
    private const SECRET_KEY = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';

    public function testGetVanilla(): void
    {
        $request = $this->factory()->createRequest('GET', 'https://example.amazonaws.com/');

        $this->assertSignature(
            $request,
            'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, SignedHeaders=host;x-amz-date, Signature=5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31',
        );
    }

    public function testQueryParametersAreSortedByKey(): void
    {
        $request = $this->factory()->createRequest('GET', 'https://example.amazonaws.com/?Param2=value2&Param1=value1');

        $this->assertSignature(
            $request,
            'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, SignedHeaders=host;x-amz-date, Signature=b97d918cfa904a5beff61c982a1b6f458b799221646efd99d3219ec94cdf2500',
        );
    }

    public function testHeaderValuesAreTrimmedAndInnerWhitespaceCollapsed(): void
    {
        $request = $this->factory()->createRequest('GET', 'https://example.amazonaws.com/')
            ->withHeader('My-Header1', 'value1')
            ->withHeader('My-Header2', '"a   b   c"');

        $this->assertSignature(
            $request,
            'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, SignedHeaders=host;my-header1;my-header2;x-amz-date, Signature=acc3ed3afb60bb290fc8d2dd0098b9911fcaa05412b367055dee359757a9c736',
        );
    }

    public function testPostWithBodyHashesPayload(): void
    {
        $factory = $this->factory();
        $request = $factory->createRequest('POST', 'https://example.amazonaws.com/')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($factory->createStream('Param1=value1'));

        $this->assertSignature(
            $request,
            'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, SignedHeaders=content-type;host;x-amz-date, Signature=ff11897932ad3f4e8b18135d722051e5ac45fc38421b1da7b9d196a0fe09473a',
        );
    }

    public function testXAmzDateHeaderIsApplied(): void
    {
        $request = $this->factory()->createRequest('GET', 'https://example.amazonaws.com/');

        $signed = (new SignatureV4())->sign($request, $this->context(), $this->clock());

        self::assertSame('20150830T123600Z', $signed->getHeaderLine('X-Amz-Date'));
    }

    private function assertSignature(\Psr\Http\Message\RequestInterface $request, string $expectedAuthorization): void
    {
        $signed = (new SignatureV4())->sign($request, $this->context(), $this->clock());

        self::assertSame($expectedAuthorization, $signed->getHeaderLine('Authorization'));
    }

    private function factory(): Psr17Factory
    {
        return new Psr17Factory();
    }

    private function context(): SigningContext
    {
        return new SigningContext(self::ACCESS_KEY, self::SECRET_KEY, 'us-east-1', 'service');
    }

    private function clock(): DateTimeImmutable
    {
        return new DateTimeImmutable('2015-08-30T12:36:00', new DateTimeZone('UTC'));
    }
}
