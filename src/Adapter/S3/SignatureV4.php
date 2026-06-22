<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Adapter\S3;

use DateTimeImmutable;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;

/**
 * AWS Signature Version 4 signer.
 *
 * Validated offline against AWS's published test vectors before it ever touches
 * a bucket. Two signing paths: header authorization (`sign`) and query-string
 * presigning (`presign`). The S3 object-key path is encoded exactly once by the
 * caller and used as-is here — never double-encoded.
 */
final class SignatureV4
{
    private const ALGORITHM = 'AWS4-HMAC-SHA256';
    private const UNSIGNED_PAYLOAD = 'UNSIGNED-PAYLOAD';

    /**
     * Sign a request, returning a copy with Host, X-Amz-Date, (optional)
     * X-Amz-Security-Token and Authorization headers applied.
     */
    public function sign(RequestInterface $request, SigningContext $context, DateTimeImmutable $now): RequestInterface
    {
        $amzDate = $now->format('Ymd\THis\Z');
        $dateStamp = $now->format('Ymd');
        $scope = $this->credentialScope($context, $dateStamp);

        $request = $request
            ->withHeader('Host', $this->hostHeader($request->getUri()))
            ->withHeader('X-Amz-Date', $amzDate);

        if ($context->sessionToken !== null) {
            $request = $request->withHeader('X-Amz-Security-Token', $context->sessionToken);
        }

        $payloadHash = $this->payloadHash($request);
        [$canonicalHeaders, $signedHeaders] = $this->canonicalHeaders($request);

        $canonicalRequest = implode("\n", [
            $request->getMethod(),
            $this->canonicalUri($request->getUri()),
            $this->canonicalQuery($request->getUri()->getQuery()),
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $signature = $this->signature($context, $dateStamp, $amzDate, $scope, $canonicalRequest);

        $authorization = sprintf(
            '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            self::ALGORITHM,
            $context->accessKey,
            $scope,
            $signedHeaders,
            $signature,
        );

        return $request->withHeader('Authorization', $authorization);
    }

    /**
     * Presign a request, returning a URI carrying the X-Amz-* query parameters
     * (host-only signed headers, UNSIGNED-PAYLOAD).
     */
    public function presign(RequestInterface $request, SigningContext $context, DateTimeImmutable $now, int $expiresInSeconds): UriInterface
    {
        $amzDate = $now->format('Ymd\THis\Z');
        $dateStamp = $now->format('Ymd');
        $scope = $this->credentialScope($context, $dateStamp);
        $uri = $request->getUri();

        $params = $this->parseQuery($uri->getQuery());
        $params['X-Amz-Algorithm'] = self::ALGORITHM;
        $params['X-Amz-Credential'] = $context->accessKey . '/' . $scope;
        $params['X-Amz-Date'] = $amzDate;
        $params['X-Amz-Expires'] = (string)$expiresInSeconds;
        $params['X-Amz-SignedHeaders'] = 'host';

        if ($context->sessionToken !== null) {
            $params['X-Amz-Security-Token'] = $context->sessionToken;
        }

        $canonicalQuery = $this->encodeQueryParams($params);

        $canonicalRequest = implode("\n", [
            $request->getMethod(),
            $this->canonicalUri($uri),
            $canonicalQuery,
            'host:' . $this->hostHeader($uri) . "\n",
            'host',
            self::UNSIGNED_PAYLOAD,
        ]);

        $signature = $this->signature($context, $dateStamp, $amzDate, $scope, $canonicalRequest);

        return $uri->withQuery($canonicalQuery . '&X-Amz-Signature=' . $signature);
    }

    private function credentialScope(SigningContext $context, string $dateStamp): string
    {
        return $dateStamp . '/' . $context->region . '/' . $context->service . '/aws4_request';
    }

    private function signature(SigningContext $context, string $dateStamp, string $amzDate, string $scope, string $canonicalRequest): string
    {
        $stringToSign = implode("\n", [
            self::ALGORITHM,
            $amzDate,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        return hash_hmac('sha256', $stringToSign, $this->signingKey($context, $dateStamp));
    }

    private function signingKey(SigningContext $context, string $dateStamp): string
    {
        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $context->secretKey, true);
        $kRegion = hash_hmac('sha256', $context->region, $kDate, true);
        $kService = hash_hmac('sha256', $context->service, $kRegion, true);

        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }

    private function payloadHash(RequestInterface $request): string
    {
        if ($request->hasHeader('x-amz-content-sha256')) {
            return $request->getHeaderLine('x-amz-content-sha256');
        }

        $body = $request->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
            $contents = $body->getContents();
            $body->rewind();

            return hash('sha256', $contents);
        }

        return hash('sha256', (string)$body);
    }

    /**
     * @return array{string,string} [canonicalHeaders, signedHeaders]
     */
    private function canonicalHeaders(RequestInterface $request): array
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = $this->canonicalHeaderValue($values);
        }
        ksort($headers);

        $canonical = '';
        foreach ($headers as $name => $value) {
            $canonical .= $name . ':' . $value . "\n";
        }

        return [$canonical, implode(';', array_keys($headers))];
    }

    /**
     * @param array<string> $values
     */
    private function canonicalHeaderValue(array $values): string
    {
        $normalized = array_map(
            static fn(string $value): string => (string)preg_replace('/\s+/', ' ', trim($value)),
            $values,
        );

        return implode(',', $normalized);
    }

    private function canonicalUri(UriInterface $uri): string
    {
        $path = $uri->getPath();

        return $path === '' ? '/' : $path;
    }

    private function canonicalQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }

        $pairs = [];
        foreach (explode('&', $query) as $part) {
            if ($part === '') {
                continue;
            }

            $eq = strpos($part, '=');
            if ($eq === false) {
                $pairs[] = [rawurlencode(rawurldecode($part)), ''];

                continue;
            }

            $pairs[] = [
                rawurlencode(rawurldecode(substr($part, 0, $eq))),
                rawurlencode(rawurldecode(substr($part, $eq + 1))),
            ];
        }

        usort($pairs, static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return implode('&', array_map(static fn(array $pair): string => $pair[0] . '=' . $pair[1], $pairs));
    }

    /**
     * @param array<string,string> $params
     */
    private function encodeQueryParams(array $params): string
    {
        $encoded = [];
        foreach ($params as $key => $value) {
            $encoded[rawurlencode($key)] = rawurlencode($value);
        }
        ksort($encoded);

        $query = [];
        foreach ($encoded as $key => $value) {
            $query[] = $key . '=' . $value;
        }

        return implode('&', $query);
    }

    /**
     * @return array<string,string>
     */
    private function parseQuery(string $query): array
    {
        if ($query === '') {
            return [];
        }

        $params = [];
        foreach (explode('&', $query) as $part) {
            if ($part === '') {
                continue;
            }

            $eq = strpos($part, '=');
            if ($eq === false) {
                $params[rawurldecode($part)] = '';

                continue;
            }

            $params[rawurldecode(substr($part, 0, $eq))] = rawurldecode(substr($part, $eq + 1));
        }

        return $params;
    }

    private function hostHeader(UriInterface $uri): string
    {
        $host = $uri->getHost();
        $port = $uri->getPort();

        return $port === null ? $host : $host . ':' . $port;
    }
}
