<?php

declare(strict_types=1);

/**
 * The credentials and scope needed to compute an AWS SigV4 signature.
 *
 * Deliberately service-agnostic: the S3 transport passes service "s3", but the
 * same signer is validated against AWS's generic published test vectors.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Adapter\S3;

final readonly class SigningContext
{
    /**
     * __construct.
     *
     * @param string $accessKey
     * @param string $secretKey
     * @param string $region
     * @param string $service
     * @param ?string $sessionToken
     */
    public function __construct(
        public string $accessKey,
        public string $secretKey,
        public string $region,
        public string $service,
        public ?string $sessionToken = null,
    ) {}
}
