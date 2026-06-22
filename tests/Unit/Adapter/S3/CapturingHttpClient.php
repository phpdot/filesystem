<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Tests\Unit\Adapter\S3;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * A PSR-18 test double: records every request and replays queued responses.
 */
final class CapturingHttpClient implements ClientInterface
{
    /**
     * @var list<RequestInterface>
     */
    public array $requests = [];

    /**
     * @var list<ResponseInterface>
     */
    public array $responses = [];

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        $response = array_shift($this->responses);
        if ($response === null) {
            throw new RuntimeException('CapturingHttpClient: no canned response queued.');
        }

        return $response;
    }
}
