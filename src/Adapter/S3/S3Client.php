<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Adapter\S3;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use PHPdot\Filesystem\Exception\MultipartUploadFailed;
use PHPdot\Filesystem\Exception\S3RequestFailed;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

/**
 * A minimal S3 client over an injected PSR-18 transport.
 *
 * Every request is SigV4-signed via {@see SignatureV4}. Object keys are encoded
 * exactly once into the request URI; `x-amz-content-sha256` carries either the
 * body hash (small/known bodies) or UNSIGNED-PAYLOAD (streamed parts), so a part
 * request always has an explicit Content-Length.
 */
final class S3Client
{
    private const EMPTY_PAYLOAD_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
    private const UNSIGNED_PAYLOAD = 'UNSIGNED-PAYLOAD';

    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly SignatureV4 $signer,
        private readonly S3Config $config,
        private readonly Xml $xml = new Xml(),
    ) {}

    /**
     * @param array<string,string> $headers
     */
    public function putObject(string $key, StreamInterface $body, ?int $length, array $headers = []): void
    {
        $request = $this->requests->createRequest('PUT', $this->url($key))->withBody($body);
        $request = $this->applyHeaders($request, $headers);

        if ($length !== null) {
            $request = $request->withHeader('Content-Length', (string)$length);
        }

        // UNSIGNED-PAYLOAD: the transport reads the body exactly once (valid over
        // TLS), so a wrapping ProgressStream counts each byte exactly once and a
        // large body is never buffered just to hash it.
        $this->ensureSuccess($this->send($request, self::UNSIGNED_PAYLOAD), 'PutObject ' . $key);
    }

    public function getObject(string $key): StreamInterface
    {
        $request = $this->requests->createRequest('GET', $this->url($key));

        return $this->ensureSuccess($this->send($request, self::EMPTY_PAYLOAD_HASH), 'GetObject ' . $key)->getBody();
    }

    /**
     * @return array{size: int, lastModified: ?int, mimeType: ?string, etag: string}
     */
    public function headObject(string $key): array
    {
        $request = $this->requests->createRequest('HEAD', $this->url($key));
        $response = $this->ensureSuccess($this->send($request, self::EMPTY_PAYLOAD_HASH), 'HeadObject ' . $key);

        $lastModified = $response->getHeaderLine('Last-Modified');
        $mimeType = $response->getHeaderLine('Content-Type');

        return [
            'size' => (int)$response->getHeaderLine('Content-Length'),
            'lastModified' => $lastModified === '' ? null : $this->httpDate($lastModified),
            'mimeType' => $mimeType === '' ? null : $mimeType,
            'etag' => trim($response->getHeaderLine('ETag'), '"'),
        ];
    }

    public function deleteObject(string $key): void
    {
        $request = $this->requests->createRequest('DELETE', $this->url($key));
        $this->ensureSuccess($this->send($request, self::EMPTY_PAYLOAD_HASH), 'DeleteObject ' . $key);
    }

    public function copyObject(string $from, string $to): void
    {
        $source = '/' . rawurlencode($this->config->bucket) . '/' . $this->encodeKey($from);
        $request = $this->requests->createRequest('PUT', $this->url($to))->withHeader('x-amz-copy-source', $source);
        $response = $this->ensureSuccess($this->send($request, self::EMPTY_PAYLOAD_HASH), 'CopyObject ' . $from);

        $body = (string)$response->getBody();
        if ($this->xml->isError($body)) {
            $error = $this->xml->parseError($body);

            throw S3RequestFailed::create(200, $error['code'], $error['message']);
        }
    }

    /**
     * @return iterable<array{key: string, size: int, lastModified: ?int, etag: string, isPrefix: bool}>
     */
    public function listObjectsV2(string $prefix, bool $deep): iterable
    {
        $continuationToken = null;

        do {
            $query = 'list-type=2&prefix=' . rawurlencode($prefix);
            if (!$deep) {
                $query .= '&delimiter=%2F';
            }
            if ($continuationToken !== null) {
                $query .= '&continuation-token=' . rawurlencode($continuationToken);
            }

            $request = $this->requests->createRequest('GET', $this->url('', $query));
            $response = $this->ensureSuccess($this->send($request, self::EMPTY_PAYLOAD_HASH), 'ListObjectsV2');
            $result = $this->xml->parseListObjectsV2((string)$response->getBody());

            foreach ($result['objects'] as $object) {
                yield [
                    'key' => $object['key'],
                    'size' => $object['size'],
                    'lastModified' => $object['lastModified'],
                    'etag' => $object['etag'],
                    'isPrefix' => false,
                ];
            }

            foreach ($result['prefixes'] as $prefix2) {
                yield ['key' => $prefix2, 'size' => 0, 'lastModified' => null, 'etag' => '', 'isPrefix' => true];
            }

            $continuationToken = $result['isTruncated'] ? $result['nextContinuationToken'] : null;
        } while ($continuationToken !== null);
    }

    /**
     * @param array<string,string> $headers
     */
    public function createMultipartUpload(string $key, array $headers = []): string
    {
        $request = $this->applyHeaders($this->requests->createRequest('POST', $this->url($key, 'uploads=')), $headers);
        $response = $this->ensureSuccess($this->send($request, self::EMPTY_PAYLOAD_HASH), 'CreateMultipartUpload ' . $key);

        $uploadId = $this->xml->parseUploadId((string)$response->getBody());
        if ($uploadId === null) {
            throw MultipartUploadFailed::withReason('Missing UploadId in CreateMultipartUpload response.');
        }

        return $uploadId;
    }

    public function uploadPart(string $key, string $uploadId, int $partNumber, StreamInterface $chunk, int $length): string
    {
        $query = 'partNumber=' . $partNumber . '&uploadId=' . rawurlencode($uploadId);
        $request = $this->requests->createRequest('PUT', $this->url($key, $query))
            ->withBody($chunk)
            ->withHeader('Content-Length', (string)$length);

        $response = $this->ensureSuccess($this->send($request, self::UNSIGNED_PAYLOAD), 'UploadPart ' . $key);

        $etag = trim($response->getHeaderLine('ETag'), '"');
        if ($etag === '') {
            throw MultipartUploadFailed::withReason('Missing ETag in UploadPart response.');
        }

        return $etag;
    }

    /**
     * @param array<int,string> $partsEtags partNumber => ETag
     */
    public function completeMultipartUpload(string $key, string $uploadId, array $partsEtags): void
    {
        $body = $this->xml->buildCompleteMultipartBody($partsEtags);
        $request = $this->requests->createRequest('POST', $this->url($key, 'uploadId=' . rawurlencode($uploadId)))
            ->withBody($this->streams->createStream($body))
            ->withHeader('Content-Type', 'application/xml');

        $response = $this->ensureSuccess($this->send($request, hash('sha256', $body)), 'CompleteMultipartUpload ' . $key);

        $responseBody = (string)$response->getBody();
        if ($this->xml->isError($responseBody)) {
            $error = $this->xml->parseError($responseBody);

            throw MultipartUploadFailed::withReason(sprintf('Complete failed: %s %s', $error['code'], $error['message']));
        }
    }

    public function abortMultipartUpload(string $key, string $uploadId): void
    {
        $request = $this->requests->createRequest('DELETE', $this->url($key, 'uploadId=' . rawurlencode($uploadId)));
        $this->ensureSuccess($this->send($request, self::EMPTY_PAYLOAD_HASH), 'AbortMultipartUpload ' . $key);
    }

    public function presign(string $key, DateTimeInterface $expiresAt): string
    {
        $now = $this->now();
        $expiresIn = max(1, $expiresAt->getTimestamp() - $now->getTimestamp());

        $uri = $this->signer->presign(
            $this->requests->createRequest('GET', $this->url($key)),
            $this->config->signingContext(),
            $now,
            $expiresIn,
        );

        return (string)$uri;
    }

    /**
     * The stable (unsigned) URL for an object — used for public URLs.
     */
    public function objectUrl(string $key): string
    {
        return $this->url($key);
    }

    private function send(RequestInterface $request, string $payloadHash): ResponseInterface
    {
        $request = $request->withHeader('x-amz-content-sha256', $payloadHash);
        $signed = $this->signer->sign($request, $this->config->signingContext(), $this->now());

        try {
            return $this->http->sendRequest($signed);
        } catch (ClientExceptionInterface $exception) {
            throw S3RequestFailed::create(0, 'NetworkError', $exception->getMessage(), $exception);
        }
    }

    private function ensureSuccess(ResponseInterface $response, string $operation): ResponseInterface
    {
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $error = $this->xml->parseError((string)$response->getBody());

            throw S3RequestFailed::create($status, $error['code'], $error['message'] !== '' ? $error['message'] : $operation);
        }

        return $response;
    }

    /**
     * @param array<string,string> $headers
     */
    private function applyHeaders(RequestInterface $request, array $headers): RequestInterface
    {
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    private function url(string $key, string $query = ''): string
    {
        $encodedKey = $this->encodeKey($key);

        if ($this->config->endpoint !== null) {
            $base = rtrim($this->config->endpoint, '/');

            if ($this->config->pathStyle) {
                $url = $base . '/' . rawurlencode($this->config->bucket) . '/' . $encodedKey;
            } else {
                $url = $this->injectBucketHost($base) . '/' . $encodedKey;
            }
        } elseif ($this->config->pathStyle) {
            $url = 'https://s3.' . $this->config->region . '.amazonaws.com/' . rawurlencode($this->config->bucket) . '/' . $encodedKey;
        } else {
            $url = 'https://' . $this->config->bucket . '.s3.' . $this->config->region . '.amazonaws.com/' . $encodedKey;
        }

        return $query === '' ? $url : $url . '?' . $query;
    }

    private function injectBucketHost(string $base): string
    {
        $parts = parse_url($base);
        $parts = $parts === false ? [] : $parts;

        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return $scheme . '://' . $this->config->bucket . '.' . $host . $port;
    }

    private function encodeKey(string $key): string
    {
        return implode('/', array_map('rawurlencode', explode('/', ltrim($key, '/'))));
    }

    private function httpDate(string $value): ?int
    {
        $timestamp = strtotime($value);

        return $timestamp === false ? null : $timestamp;
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
