<?php

declare(strict_types=1);

/**
 * A tus-style (tus.io) resumable upload endpoint, driving the shared
 * {@see UploadManagerInterface}. Each chunk is bounded, so a Swoole request
 * buffer holds at most one chunk per upload in flight.
 *
 * POST   /uploads        create()      -> 201 + Location + Upload-Offset: 0
 * HEAD   /uploads/{id}    status()      -> 200 + Upload-Offset + Upload-Length
 * PATCH  /uploads/{id}    writeChunk()  -> 204 + Upload-Offset  (the filling PATCH completes the upload)
 * DELETE /uploads/{id}    abort()       -> 204
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Http;

use PHPdot\Filesystem\Contract\UploadManagerInterface;
use PHPdot\Filesystem\Exception\UploadOffsetMismatch;
use PHPdot\Filesystem\Exception\UploadSessionExpired;
use PHPdot\Filesystem\Exception\UploadSessionNotFound;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ResumableUploadHandler implements RequestHandlerInterface
{
    private const TUS_VERSION = '1.0.0';

    /**
     * __construct.
     *
     * @param UploadManagerInterface $uploads
     * @param ResponseFactoryInterface $responses
     */
    public function __construct(
        private readonly UploadManagerInterface $uploads,
        private readonly ResponseFactoryInterface $responses,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            return match (strtoupper($request->getMethod())) {
                'POST' => $this->create($request),
                'HEAD' => $this->status($request),
                'PATCH' => $this->writeChunk($request),
                'DELETE' => $this->abort($request),
                default => $this->tus($this->responses->createResponse(405))->withHeader('Allow', 'POST, HEAD, PATCH, DELETE'),
            };
        } catch (UploadSessionNotFound) {
            return $this->tus($this->responses->createResponse(404));
        } catch (UploadSessionExpired) {
            return $this->tus($this->responses->createResponse(410));
        } catch (UploadOffsetMismatch $exception) {
            return $this->tus($this->responses->createResponse(409))
                ->withHeader('Upload-Offset', (string) $exception->expectedOffset());
        }
    }

    /**
     * Create.
     *
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     */
    private function create(ServerRequestInterface $request): ResponseInterface
    {
        $uploadLength = $this->header($request, 'Upload-Length');
        $filename = $this->metadataValue($request, 'filename') ?? bin2hex(random_bytes(8));

        $session = $this->uploads->create(
            'uploads/' . $filename,
            $uploadLength === null ? null : (int) $uploadLength,
        );

        $location = rtrim($request->getUri()->getPath(), '/') . '/' . $session->id;

        return $this->tus($this->responses->createResponse(201))
            ->withHeader('Location', $location)
            ->withHeader('Upload-Offset', '0');
    }

    /**
     * Status.
     *
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     */
    private function status(ServerRequestInterface $request): ResponseInterface
    {
        $session = $this->uploads->status($this->sessionId($request));

        $response = $this->tus($this->responses->createResponse(200))
            ->withHeader('Upload-Offset', (string) $session->bytesReceived)
            ->withHeader('Cache-Control', 'no-store');

        if ($session->totalSize !== null) {
            $response = $response->withHeader('Upload-Length', (string) $session->totalSize);
        }

        return $response;
    }

    /**
     * Write chunk.
     *
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     */
    private function writeChunk(ServerRequestInterface $request): ResponseInterface
    {
        $sessionId = $this->sessionId($request);
        $offset = (int) ($this->header($request, 'Upload-Offset') ?? '0');

        $body = $request->getBody();
        $length = $body->getSize() ?? (int) $request->getHeaderLine('Content-Length');

        $result = $this->uploads->writeChunk($sessionId, $offset, $body, $length);

        if ($result->complete) {
            $this->uploads->complete($sessionId);
        }

        return $this->tus($this->responses->createResponse(204))
            ->withHeader('Upload-Offset', (string) $result->offset);
    }

    /**
     * Abort.
     *
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     */
    private function abort(ServerRequestInterface $request): ResponseInterface
    {
        $this->uploads->abort($this->sessionId($request));

        return $this->tus($this->responses->createResponse(204));
    }

    /**
     * Session id.
     *
     * @param ServerRequestInterface $request
     *
     * @return string
     */
    private function sessionId(ServerRequestInterface $request): string
    {
        return basename($request->getUri()->getPath());
    }

    /**
     * Header.
     *
     * @param ServerRequestInterface $request
     * @param string $name
     *
     * @return ?string
     */
    private function header(ServerRequestInterface $request, string $name): ?string
    {
        return $request->hasHeader($name) ? $request->getHeaderLine($name) : null;
    }

    /**
     * Metadata value.
     *
     * @param ServerRequestInterface $request
     * @param string $key
     *
     * @return ?string
     */
    private function metadataValue(ServerRequestInterface $request, string $key): ?string
    {
        foreach (explode(',', $request->getHeaderLine('Upload-Metadata')) as $pair) {
            $parts = explode(' ', trim($pair), 2);
            if ($parts[0] === $key && isset($parts[1])) {
                $decoded = base64_decode($parts[1], true);

                return $decoded === false ? null : $decoded;
            }
        }

        return null;
    }

    /**
     * Tus.
     *
     * @param ResponseInterface $response
     *
     * @return ResponseInterface
     */
    private function tus(ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader('Tus-Resumable', self::TUS_VERSION);
    }
}
