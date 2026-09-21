<?php

declare(strict_types=1);

/**
 * A presigned direct-upload grant: everything a client needs to PUT one object
 * straight to the bucket, and everything the application needs to reason about
 * what it granted.
 *
 * The URL authorizes exactly one PUT of exactly one key until the expiry. When
 * a content type or a size was pinned at signing it appears in `headers` — the
 * client MUST send it verbatim, because it is part of the signature; a pinned
 * `Content-Length` makes the bucket refuse any body of a different length. An
 * unpinned aspect stays the client's to choose, and an unpinned size accepts
 * any body, leaving the ceiling to the application's completion check.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Upload;

use DateTimeImmutable;

final readonly class PresignedUpload
{
    /**
     * Hold the grant.
     *
     * @param string $url The signed URL the client PUTs to
     * @param string $method Always PUT — named so a generic client needs no assumptions
     * @param string $key The object key the URL authorizes
     * @param DateTimeImmutable $expiresAt The instant the grant dies
     * @param array<string,string> $headers Headers the client must send verbatim (signed)
     * @param null|int $partNumber For a part grant: which part of which multipart upload
     * @param null|string $uploadId For a part grant: the multipart upload it belongs to
     */
    public function __construct(
        public string $url,
        public string $method,
        public string $key,
        public DateTimeImmutable $expiresAt,
        public array $headers,
        public null|int $partNumber = null,
        public null|string $uploadId = null,
    ) {}

    /**
     * A plain shape for handing the grant to a template or a JSON endpoint.
     *
     * @return array{url: string, method: string, key: string, expiresAt: string, headers: array<string,string>, partNumber?: int, uploadId?: string}
     */
    public function toArray(): array
    {
        $grant = [
            'url' => $this->url,
            'method' => $this->method,
            'key' => $this->key,
            'expiresAt' => $this->expiresAt->format(DateTimeImmutable::ATOM),
            'headers' => $this->headers,
        ];

        if ($this->partNumber !== null) {
            $grant['partNumber'] = $this->partNumber;
        }

        if ($this->uploadId !== null) {
            $grant['uploadId'] = $this->uploadId;
        }

        return $grant;
    }
}
