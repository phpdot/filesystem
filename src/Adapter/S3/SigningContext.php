<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Adapter\S3;

/**
 * The credentials and scope needed to compute an AWS SigV4 signature.
 *
 * Deliberately service-agnostic: the S3 transport passes service "s3", but the
 * same signer is validated against AWS's generic published test vectors.
 */
final readonly class SigningContext
{
    public function __construct(
        public string $accessKey,
        public string $secretKey,
        public string $region,
        public string $service,
        public ?string $sessionToken = null,
    ) {}
}
