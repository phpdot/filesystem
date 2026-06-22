<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Adapter\S3;

/**
 * Connection settings for an S3-compatible backend.
 *
 * Defaults target AWS; set `endpoint` + `pathStyle` for MinIO, or `endpoint`
 * with `region: 'auto'` for Cloudflare R2. We never send `x-amz-checksum-*`
 * headers, so the CRC integrity checks that R2/MinIO reject are avoided by
 * construction.
 */
final readonly class S3Config
{
    public function __construct(
        public string $bucket,
        public string $region = 'us-east-1',
        public ?string $endpoint = null,
        public bool $pathStyle = false,
        public ?string $key = null,
        public ?string $secret = null,
        public ?string $token = null,
        public string $prefix = '',
        public ?string $publicUrl = null,
    ) {}

    public function signingContext(): SigningContext
    {
        return new SigningContext(
            $this->key ?? '',
            $this->secret ?? '',
            $this->region,
            's3',
            $this->token,
        );
    }
}
