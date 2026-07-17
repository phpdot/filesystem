<?php

declare(strict_types=1);

/**
 * Thrown when an S3 API request returns an error response.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Exception;

use RuntimeException;
use Throwable;

final class S3RequestFailed extends RuntimeException implements FilesystemException
{
    private int $status = 0;
    private string $awsErrorCode = '';

    public function errorCode(): string
    {
        return 'filesystem.s3_request_failed';
    }

    /**
     * The HTTP status code returned by the S3 endpoint.
     *
     * @return int
     */
    public function status(): int
    {
        return $this->status;
    }

    /**
     * The S3 error code parsed from the response body (e.g. "NoSuchKey"), if any.
     *
     * @return string
     */
    public function awsErrorCode(): string
    {
        return $this->awsErrorCode;
    }

    /**
     * Create.
     *
     * @param int $status
     * @param string $awsErrorCode
     * @param string $message
     * @param ?Throwable $previous
     *
     * @return self
     */
    public static function create(int $status, string $awsErrorCode, string $message = '', ?Throwable $previous = null): self
    {
        $detail = $message !== '' ? $message : 'no detail provided';
        $label = $awsErrorCode !== '' ? $awsErrorCode : 'UnknownError';

        $exception = new self("S3 request failed ({$status} {$label}): {$detail}", 0, $previous);
        $exception->status = $status;
        $exception->awsErrorCode = $awsErrorCode;

        return $exception;
    }
}
