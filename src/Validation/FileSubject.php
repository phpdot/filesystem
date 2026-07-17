<?php

declare(strict_types=1);

/**
 * The immutable input every {@see \PHPdot\Filesystem\Contract\Validator} inspects.
 *
 * Replaces the old coroutine-unsafe `setOriginalFilename` mutator: the declared
 * name is fixed at construction, so two concurrent stores can never cross
 * filenames. Facts (size, mime type, image dimensions) are sniffed once and
 * cached, fixing the old triple-disk-read — and only a bounded prefix of the
 * body is ever held in memory, so a multi-gigabyte upload never becomes a
 * multi-gigabyte string.
 *
 * The wrapped stream is always seekable — {@see fromContents} buffers a
 * non-seekable body into a php://temp-backed copy so it survives both sniffing
 * and the subsequent write — and {@see stream} hands it back rewound.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Validation;

use League\MimeTypeDetection\FinfoMimeTypeDetector;
use League\MimeTypeDetection\MimeTypeDetector;
use PHPdot\Filesystem\Write\WriteContents;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;

final class FileSubject
{
    /**
     * The most of the body kept in memory for sniffing — finfo and image headers need only the leading bytes.
     */
    private const SNIFF_BUFFER = 1048576;

    private readonly MimeTypeDetector $mimeDetector;

    private ?string $sample = null;
    private ?int $size = null;
    private ?string $mimeType = null;
    private bool $dimensionsResolved = false;
    /**
     * @var array{int,int}|null
     */
    private ?array $dimensions = null;

    /**
     * __construct.
     *
     * @param StreamInterface $stream
     * @param string $originalName
     */
    public function __construct(
        private readonly StreamInterface $stream,
        private readonly string $originalName,
    ) {
        $this->mimeDetector = new FinfoMimeTypeDetector();
    }

    /**
     * Build a subject from any write input, reusing {@see WriteContents::normalize}
     * for coercion and buffering non-seekable bodies into a seekable copy.
     *
     * @param string|StreamInterface|UploadedFileInterface $contents
     * @param WriteContents $writeContents
     * @param StreamFactoryInterface $streams
     * @param string $originalName
     *
     * @return self
     */
    public static function fromContents(
        string|StreamInterface|UploadedFileInterface $contents,
        string $originalName,
        WriteContents $writeContents,
        StreamFactoryInterface $streams,
    ): self {
        $stream = $writeContents->normalize($contents);

        if (!$stream->isSeekable()) {
            $stream = $streams->createStream($stream->getContents());
        }

        return new self($stream, $originalName);
    }

    /**
     * Original name.
     *
     * @return string
     */
    public function originalName(): string
    {
        return $this->originalName;
    }

    /**
     * Size.
     *
     * @return int
     */
    public function size(): int
    {
        if ($this->size !== null) {
            return $this->size;
        }

        $size = $this->stream->getSize();
        if ($size === null && $this->stream->isSeekable()) {
            $this->stream->seek(0, SEEK_END);
            $size = $this->stream->tell();
            $this->stream->rewind();
        }

        return $this->size = $size ?? strlen($this->sample());
    }

    /**
     * Mime type.
     *
     * @return string
     */
    public function mimeType(): string
    {
        return $this->mimeType ??= $this->mimeDetector->detectMimeType($this->originalName, $this->sample())
            ?? 'application/octet-stream';
    }

    /**
     * The pixel dimensions when the body is a recognizable image, else null.
     * Validators that target images skip non-images by checking for null.
     *
     * @return array{int,int}|null
     */
    public function dimensions(): ?array
    {
        if ($this->dimensionsResolved) {
            return $this->dimensions;
        }

        $this->dimensionsResolved = true;

        if (!str_starts_with($this->mimeType(), 'image/')) {
            return $this->dimensions = null;
        }

        $info = @getimagesizefromstring($this->sample());
        if ($info === false) {
            return $this->dimensions = null;
        }

        return $this->dimensions = [$info[0], $info[1]];
    }

    /**
     * The body, rewound and ready to write. Safe to call after sniffing.
     *
     * @return StreamInterface
     */
    public function stream(): StreamInterface
    {
        if ($this->stream->isSeekable()) {
            $this->stream->rewind();
        }

        return $this->stream;
    }

    /**
     * A bounded prefix of the body (at most {@see SNIFF_BUFFER} bytes), cached.
     * Content MIME sniffing and image-header dimensions read only the leading
     * bytes, so this never buffers a large body into memory.
     *
     * @return string
     */
    private function sample(): string
    {
        if ($this->sample !== null) {
            return $this->sample;
        }

        if ($this->stream->isSeekable()) {
            $this->stream->rewind();
        }

        $sample = '';
        while (strlen($sample) < self::SNIFF_BUFFER && !$this->stream->eof()) {
            $chunk = $this->stream->read(self::SNIFF_BUFFER - strlen($sample));
            if ($chunk === '') {
                break;
            }
            $sample .= $chunk;
        }

        if ($this->stream->isSeekable()) {
            $this->stream->rewind();
        }

        return $this->sample = $sample;
    }
}
