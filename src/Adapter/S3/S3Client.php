<?php

declare(strict_types=1);

/**
 * A minimal S3 client over an injected PSR-18 transport.
 *
 * Every request is SigV4-signed via {@see SignatureV4}. Object keys are encoded
 * exactly once into the request URI; `x-amz-content-sha256` carries either the
 * body hash (small/known bodies) or UNSIGNED-PAYLOAD (streamed parts), so a part
 * request always has an explicit Content-Length.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Adapter\S3;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use PHPdot\Filesystem\Exception\InvalidConfigurationValue;
use PHPdot\Filesystem\Exception\MultipartUploadFailed;
use PHPdot\Filesystem\Exception\S3RequestFailed;
use PHPdot\Filesystem\Upload\PresignedUpload;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

final class S3Client
{
    private const EMPTY_PAYLOAD_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
    private const UNSIGNED_PAYLOAD = 'UNSIGNED-PAYLOAD';

    /**
     * The checksum header every storage path carries as a VALUE, never a bare
     * directive: AWS rejects `x-amz-sdk-checksum-algorithm` alone on both
     * header- and query-signed requests (400 InvalidRequest — MinIO accepts
     * it, which hides the gap). The digest travels base64; S3 verifies it
     * against the received bytes and stores it, so the client cannot lie, and
     * `headObject()` hands it back with no download. Grants sign the header
     * with a digest the caller supplies; server writes hash the stream they
     * already hold.
     */
    public const string CHECKSUM_HEADER = 'x-amz-checksum-sha256';

    /**
     * __construct.
     *
     * @param ClientInterface $http
     * @param RequestFactoryInterface $requests
     * @param StreamFactoryInterface $streams
     * @param SignatureV4 $signer
     * @param S3Config $config
     * @param Xml $xml
     */
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly SignatureV4 $signer,
        private readonly S3Config $config,
        private readonly Xml $xml = new Xml(),
    ) {}

    /**
     * Upload an object in a single request.
     *
     * @param array<string,string> $headers
     * @param string $key
     * @param StreamInterface $body
     * @param ?int $length
     *
     * @return void
     */
    public function putObject(string $key, StreamInterface $body, null|int $length, array $headers = []): void
    {
        $request = $this->requests->createRequest('PUT', $this->url($key))->withBody($body);
        $request = $this->applyHeaders($request, $headers);

        if ($length !== null) {
            $request = $request->withHeader('Content-Length', (string) $length);
        }

        $this->ensureSuccess($this->send($request, self::UNSIGNED_PAYLOAD), 'PutObject ' . $key);
    }

    /**
     * Get object.
     *
     * @param string $key
     *
     * @return StreamInterface
     */
    public function getObject(string $key): StreamInterface
    {
        $request = $this->requests->createRequest('GET', $this->url($key));

        return $this->ensureSuccess($this->send($request, self::EMPTY_PAYLOAD_HASH), 'GetObject ' . $key)->getBody();
    }

    /**
     * Fetch an object's metadata via a HEAD request. A checksum a multipart
     * complete stored is composite ("<digest>-<partCount>") and reads back
     * with its suffix, honest about being a checksum of the parts. The CRC64
     * value stays base64 exactly as the storage gives it, and the checksum
     * type names FULL_OBJECT or COMPOSITE when the storage says so.
     *
     * @param string $key
     *
     * @return array{size: int, lastModified: ?int, mimeType: ?string, etag: string, checksumSha256: null|string, checksumCrc64: null|string, checksumType: null|string}
     */
    public function headObject(string $key): array
    {
        $request = $this->requests->createRequest('HEAD', $this->url($key))
            ->withHeader('x-amz-checksum-mode', 'ENABLED')
            ->withHeader('Accept-Encoding', 'identity');
        $response = $this->ensureSuccess($this->send($request, self::EMPTY_PAYLOAD_HASH), 'HeadObject ' . $key);

        $lastModified = $response->getHeaderLine('Last-Modified');
        $mimeType = $response->getHeaderLine('Content-Type');
        $checksumSha256 = $this->decodeChecksum($response->getHeaderLine('x-amz-checksum-sha256'));
        $checksumCrc64 = $response->getHeaderLine('x-amz-checksum-crc64nvme');
        $checksumType = $response->getHeaderLine('x-amz-checksum-type');

        return [
            'size' => (int) $response->getHeaderLine('Content-Length'),
            'lastModified' => $lastModified === '' ? null : $this->httpDate($lastModified),
            'mimeType' => $mimeType === '' ? null : $mimeType,
            'etag' => $this->normalizeEtag($response->getHeaderLine('ETag')),
            'checksumSha256' => $checksumSha256,
            'checksumCrc64' => $checksumCrc64 === '' ? null : $checksumCrc64,
            'checksumType' => $checksumType === '' ? null : $checksumType,
        ];
    }

    /**
     * Delete object.
     *
     * @param string $key
     *
     * @return void
     */
    public function deleteObject(string $key): void
    {
        $request = $this->requests->createRequest('DELETE', $this->url($key));
        $this->ensureSuccess($this->send($request, self::EMPTY_PAYLOAD_HASH), 'DeleteObject ' . $key);
    }

    /**
     * Copy object.
     *
     * @param string $from
     * @param string $to
     *
     * @return void
     */
    public function copyObject(string $from, string $to): void
    {
        $source = '/' . rawurlencode($this->config->bucket) . '/' . $this->encodeKey($from);
        $request = $this->requests->createRequest('PUT', $this->url($to))->withHeader('x-amz-copy-source', $source);
        $response = $this->ensureSuccess($this->send($request, self::EMPTY_PAYLOAD_HASH), 'CopyObject ' . $from);

        $body = (string) $response->getBody();
        if ($this->xml->isError($body)) {
            $error = $this->xml->parseError($body);

            throw S3RequestFailed::create(200, $error['code'], $error['message']);
        }
    }

    /**
     * List objects under a prefix using the ListObjectsV2 API.
     *
     * @param string $prefix
     * @param bool $deep
     *
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
            $result = $this->xml->parseListObjectsV2((string) $response->getBody());

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
     * Begin a multipart upload and return its upload id.
     *
     * @param array<string,string> $headers
     * @param string $key
     *
     * @return string
     */
    public function createMultipartUpload(string $key, array $headers = []): string
    {
        $request = $this->applyHeaders($this->requests->createRequest('POST', $this->url($key, 'uploads=')), $headers);
        $response = $this->ensureSuccess($this->send($request, self::EMPTY_PAYLOAD_HASH), 'CreateMultipartUpload ' . $key);

        $uploadId = $this->xml->parseUploadId((string) $response->getBody());
        if ($uploadId === null) {
            throw MultipartUploadFailed::withReason('Missing UploadId in CreateMultipartUpload response.');
        }

        return $uploadId;
    }

    /**
     * Upload part.
     *
     * @param string $key
     * @param string $uploadId
     * @param int $partNumber
     * @param StreamInterface $chunk
     * @param int $length
     *
     * @return string
     */
    public function uploadPart(string $key, string $uploadId, int $partNumber, StreamInterface $chunk, int $length): string
    {
        $query = 'partNumber=' . $partNumber . '&uploadId=' . rawurlencode($uploadId);
        $request = $this->requests->createRequest('PUT', $this->url($key, $query))
            ->withBody($chunk)
            ->withHeader('Content-Length', (string) $length);

        $response = $this->ensureSuccess($this->send($request, self::UNSIGNED_PAYLOAD), 'UploadPart ' . $key);

        $etag = $this->normalizeEtag($response->getHeaderLine('ETag'));
        if ($etag === '') {
            throw MultipartUploadFailed::withReason('Missing ETag in UploadPart response.');
        }

        return $etag;
    }

    /**
     * Complete a multipart upload from its uploaded parts. Parts carrying
     * their checksum make S3 store a composite object checksum; ETag-only
     * parts complete exactly as before.
     *
     * @param array<int,string|array{etag: string, checksumSha256?: null|string, checksumCrc64?: null|string}> $partsEtags partNumber => part
     * @param string $key
     * @param string $uploadId
     *
     * @return void
     */
    public function completeMultipartUpload(string $key, string $uploadId, array $partsEtags): void
    {
        $body = $this->xml->buildCompleteMultipartBody($partsEtags);
        $request = $this->requests->createRequest('POST', $this->url($key, 'uploadId=' . rawurlencode($uploadId)))
            ->withBody($this->streams->createStream($body))
            ->withHeader('Content-Type', 'application/xml');

        $response = $this->ensureSuccess($this->send($request, hash('sha256', $body)), 'CompleteMultipartUpload ' . $key);

        $responseBody = (string) $response->getBody();
        if ($this->xml->isError($responseBody)) {
            $error = $this->xml->parseError($responseBody);

            throw MultipartUploadFailed::withReason(sprintf('Complete failed: %s %s', $error['code'], $error['message']));
        }
    }

    /**
     * Abort multipart upload.
     *
     * @param string $key
     * @param string $uploadId
     *
     * @return void
     */
    public function abortMultipartUpload(string $key, string $uploadId): void
    {
        $request = $this->requests->createRequest('DELETE', $this->url($key, 'uploadId=' . rawurlencode($uploadId)));
        $this->ensureSuccess($this->send($request, self::EMPTY_PAYLOAD_HASH), 'AbortMultipartUpload ' . $key);
    }

    /**
     * Presign.
     *
     * @param string $key
     * @param DateTimeInterface $expiresAt
     *
     * @return string
     */
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

        return (string) $uri;
    }

    /**
     * Grant a direct PUT: a URL a client can upload one object with, straight
     * to the bucket, until the expiry. A pinned content type joins the
     * signature — the returned grant names it in `headers`, and a client that
     * sends a different type is rejected by the bucket, keeping the stored
     * object's type a server decision. A declared size pins the body the same
     * way: the bucket refuses any body of a different length; without one the
     * grant accepts any size and the ceiling stays the caller's check.
     *
     * @param string $key
     * @param DateTimeInterface $expiresAt
     * @param null|string $contentType
     * @param null|int $size Pinned into the signature when given — the bucket refuses a body of any other length
     *
     * @return PresignedUpload
     */
    public function presignPut(string $key, DateTimeInterface $expiresAt, null|string $contentType = null, null|string $sha256Base64 = null, null|int $size = null): PresignedUpload
    {
        $now = $this->now();
        $expiresIn = max(1, $expiresAt->getTimestamp() - $now->getTimestamp());

        $request = $this->requests->createRequest('PUT', $this->url($key));
        $signed = [];

        if ($size !== null) {
            $signed['content-length'] = (string) $size;
        }

        if ($sha256Base64 !== null) {
            $signed['x-amz-checksum-sha256'] = $sha256Base64;
            $request = $request->withHeader('x-amz-checksum-sha256', $sha256Base64);
        }

        if ($contentType !== null) {
            $signed['content-type'] = $contentType;
            $request = $request->withHeader('Content-Type', $contentType);
        }

        $uri = $this->signer->presign($request, $this->config->signingContext(), $now, $expiresIn, $signed);

        $headers = [];
        foreach ($signed as $name => $value) {
            $headers[ucwords($name, '-')] = $value;
        }

        return new PresignedUpload((string) $uri, 'PUT', $key, DateTimeImmutable::createFromInterface($expiresAt), $headers);
    }

    /**
     * Grant a direct PUT for one part of a multipart upload — the resumable
     * form of a presigned grant. `partNumber` and `uploadId` are signed into
     * the canonical query, so each part is its own grant; the S3 5 GB
     * single-PUT ceiling and the start-from-zero failure mode both fall away.
     * The client never holds ETags: completion is built from the bucket's own
     * `listParts`, so the client remains untrusted.
     *
     * @param string $key
     * @param string $uploadId
     * @param int $partNumber 1-based, ascending; every part but the last must clear the storage's minimum part size
     * @param DateTimeInterface $expiresAt
     * @param null|string $contentType
     * @param null|string $checksumBase64 Signed into the grant under the algorithm's header; SHA256 everywhere, CRC64NVME where the storage refuses SHA-256 parts (R2)
     * @param string $checksumAlgorithm SHA256 (default) or CRC64NVME
     * @param null|int $size Pinned into the signature when given — the bucket refuses a body of any other length
     *
     * @return PresignedUpload
     */
    public function presignPartPut(string $key, string $uploadId, int $partNumber, DateTimeInterface $expiresAt, null|string $contentType = null, null|string $checksumBase64 = null, string $checksumAlgorithm = 'SHA256', null|int $size = null): PresignedUpload
    {
        $now = $this->now();
        $expiresIn = max(1, $expiresAt->getTimestamp() - $now->getTimestamp());

        $query = 'partNumber=' . $partNumber . '&uploadId=' . rawurlencode($uploadId);
        $request = $this->requests->createRequest('PUT', $this->url($key, $query));
        $signed = [];
        $checksumHeader = self::checksumHeader($checksumAlgorithm);

        if ($size !== null) {
            $signed['content-length'] = (string) $size;
        }

        if ($checksumBase64 !== null) {
            $signed[$checksumHeader] = $checksumBase64;
            $request = $request->withHeader($checksumHeader, $checksumBase64);
        }

        if ($contentType !== null) {
            $signed['content-type'] = $contentType;
            $request = $request->withHeader('Content-Type', $contentType);
        }

        $uri = $this->signer->presign($request, $this->config->signingContext(), $now, $expiresIn, $signed);

        $headers = [];
        foreach ($signed as $name => $value) {
            $headers[ucwords($name, '-')] = $value;
        }

        return new PresignedUpload((string) $uri, 'PUT', $key, DateTimeImmutable::createFromInterface($expiresAt), $headers, $partNumber, $uploadId);
    }

    /**
     * The parts the bucket itself holds for a multipart upload — the server-side
     * truth a completion should be built from, so a client's claim of what it
     * uploaded is never trusted. Paginated; every page is followed.
     *
     * @param string $key
     * @param string $uploadId
     *
     * @return array<int, array{etag: string, size: int, checksumSha256: null|string, checksumCrc64: null|string}> partNumber => etag/size/checksums
     */
    public function listParts(string $key, string $uploadId): array
    {
        $parts = [];
        $marker = null;

        do {
            $query = 'uploadId=' . rawurlencode($uploadId);

            if ($marker !== null) {
                $query .= '&part-number-marker=' . $marker;
            }

            $request = $this->requests->createRequest('GET', $this->url($key, $query))
                ->withHeader('x-amz-checksum-mode', 'ENABLED');
            $response = $this->ensureSuccess($this->send($request, self::EMPTY_PAYLOAD_HASH), 'ListParts ' . $key);
            $page = $this->xml->parseListParts((string) $response->getBody());

            foreach ($page['parts'] as $part) {
                $parts[$part['number']] = [
                    'etag' => $part['etag'],
                    'size' => $part['size'],
                    'checksumSha256' => $part['checksumSha256'] ?? null,
                    'checksumCrc64' => $part['checksumCrc64'] ?? null,
                ];
            }

            $marker = $page['nextMarker'];
        } while ($page['truncated'] && $marker !== null);

        ksort($parts);

        return $parts;
    }

    /**
     * The stable (unsigned) URL for an object — used for public URLs.
     *
     * @param string $key
     *
     * @return string
     */
    public function objectUrl(string $key): string
    {
        return $this->url($key);
    }

    /**
     * Send.
     *
     * @param RequestInterface $request
     * @param string $payloadHash
     *
     * @return ResponseInterface
     */
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

    /**
     * Ensure success.
     *
     * @param ResponseInterface $response
     * @param string $operation
     *
     * @return ResponseInterface
     */
    private function ensureSuccess(ResponseInterface $response, string $operation): ResponseInterface
    {
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $error = $this->xml->parseError((string) $response->getBody());

            throw S3RequestFailed::create($status, $error['code'], $error['message'] !== '' ? $error['message'] : $operation);
        }

        return $response;
    }

    /**
     * Apply the given headers to the outgoing request.
     *
     * @param array<string,string> $headers
     * @param RequestInterface $request
     *
     * @return RequestInterface
     */
    private function applyHeaders(RequestInterface $request, array $headers): RequestInterface
    {
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    /**
     * Url.
     *
     * @param string $key
     * @param string $query
     *
     * @return string
     */
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

    /**
     * Inject bucket host.
     *
     * @param string $base
     *
     * @return string
     */
    private function injectBucketHost(string $base): string
    {
        $parts = parse_url($base);
        $parts = $parts === false ? [] : $parts;

        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return $scheme . '://' . $this->config->bucket . '.' . $host . $port;
    }

    /**
     * Encode key.
     *
     * @param string $key
     *
     * @return string
     */
    private function encodeKey(string $key): string
    {
        return implode('/', array_map('rawurlencode', explode('/', ltrim($key, '/'))));
    }

    /**
     * ETag. Strip an optional weak-validator prefix before the quotes, so a
     * weakened answer never leaks into part maps or metadata.
     *
     * @param string $etag
     *
     * @return string
     */
    private function normalizeEtag(string $etag): string
    {
        return trim((string) preg_replace('/^W\//', '', $etag), '"');
    }

    /**
     * The wire header an algorithm name signs under. One digest family per
     * request is an S3 invariant, so callers choose exactly one.
     *
     * @param string $algorithm
     *
     * @return string
     */
    private function checksumHeader(string $algorithm): string
    {
        return match ($algorithm) {
            'SHA256' => 'x-amz-checksum-sha256',
            'CRC64NVME' => 'x-amz-checksum-crc64nvme',
            default => throw InvalidConfigurationValue::checksumAlgorithm($algorithm),
        };
    }

    /**
     * Decode the stored x-amz-checksum-sha256 header value into hex. A
     * composite value ("<base64>-<partCount>") keeps its suffix; an
     * undecodable one is null, never a silently empty fingerprint.
     *
     * @param string $stored
     *
     * @return null|string
     */
    private function decodeChecksum(string $stored): null|string
    {
        if ($stored === '') {
            return null;
        }

        $composite = str_contains($stored, '-');
        $split = $composite ? (int) strrpos($stored, '-') : 0;
        $base64 = $composite ? substr($stored, 0, $split) : $stored;
        $decoded = base64_decode($base64, true);

        if ($decoded === false) {
            return null;
        }

        return bin2hex($decoded) . ($composite ? substr($stored, $split) : '');
    }

    /**
     * Http date.
     *
     * @param string $value
     *
     * @return ?int
     */
    private function httpDate(string $value): null|int
    {
        $timestamp = strtotime($value);

        return $timestamp === false ? null : $timestamp;
    }

    /**
     * Now.
     *
     * @return DateTimeImmutable
     */
    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
