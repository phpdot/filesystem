<?php

declare(strict_types=1);

/**
 * Renders a server-side storage key from a token pattern, so the browser never
 * dictates where bytes land.
 *
 * Tokens: {date} {year} {month} {day} {uuid} {random:N} {hash:algo} {ext}
 * {name} {safe_name}. Entropy comes from {@see random_bytes} — never mt_rand —
 * and {@see generate} retries with fresh entropy when an existence probe reports
 * a collision (the old engine silently overwrote).
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Path;

use DateTimeImmutable;
use DateTimeZone;
use PHPdot\Container\Attribute\Singleton;
use PHPdot\Filesystem\Contract\PathNormalizer;
use PHPdot\Filesystem\Exception\UnableToGeneratePath;
use PHPdot\Filesystem\Validation\FileSubject;

#[Singleton]
final class PathGenerator
{
    private const MAX_ATTEMPTS = 5;
    private const HASH_BUFFER = 1048576;

    /**
     * @var array<string,callable(FileSubject,?string):string>
     */
    private array $tokens;

    private readonly PathNormalizer $normalizer;

    /**
     * __construct.
     *
     * @param ?PathNormalizer $normalizer
     */
    public function __construct(?PathNormalizer $normalizer = null)
    {
        $this->normalizer = $normalizer ?? new WhitespacePathNormalizer();
        $this->tokens = $this->defaultTokens();
    }

    /**
     * Register or override a token resolver. The resolver receives the subject
     * and the optional `:param` suffix, and returns the rendered fragment.
     *
     * @param callable(FileSubject,?string):string $resolver
     * @param string $name
     *
     * @return void
     */
    public function addToken(string $name, callable $resolver): void
    {
        $this->tokens[$name] = $resolver;
    }

    /**
     * Render the pattern into a normalized, root-relative key.
     *
     * When $exists is given, the key is re-rolled (fresh entropy) on collision,
     * up to a bounded number of attempts.
     *
     * @param (callable(string):bool)|null $exists
     * @param string $pattern
     * @param FileSubject $subject
     *
     * @return string
     */
    public function generate(string $pattern, FileSubject $subject, ?callable $exists = null): string
    {
        $attempts = $exists === null ? 1 : self::MAX_ATTEMPTS;

        for ($attempt = 0; $attempt < $attempts; ++$attempt) {
            $key = $this->normalizer->normalizePath($this->render($pattern, $subject));

            if ($key === '') {
                throw UnableToGeneratePath::emptyKey($pattern);
            }

            if ($exists === null || !$exists($key)) {
                return $key;
            }
        }

        throw UnableToGeneratePath::afterCollisions($pattern, $attempts);
    }

    /**
     * Render.
     *
     * @param string $pattern
     * @param FileSubject $subject
     *
     * @return string
     */
    private function render(string $pattern, FileSubject $subject): string
    {
        $result = preg_replace_callback(
            '/\{([a-z_]+)(?::([^}]+))?\}/',
            function (array $match) use ($subject): string {
                $name = $match[1];
                $param = $match[2] ?? null;

                $resolver = $this->tokens[$name] ?? throw UnableToGeneratePath::unknownToken($name);

                return $resolver($subject, $param);
            },
            $pattern,
        );

        return $result ?? $pattern;
    }

    /**
     * Return the default placeholder tokens for path templates.
     *
     * @return array<string,callable(FileSubject,?string):string>
     */
    private function defaultTokens(): array
    {
        return [
            'date' => fn(): string => $this->now()->format('Y/m/d'),
            'year' => fn(): string => $this->now()->format('Y'),
            'month' => fn(): string => $this->now()->format('m'),
            'day' => fn(): string => $this->now()->format('d'),
            'uuid' => fn(): string => $this->uuid(),
            'random' => fn(FileSubject $s, ?string $param): string => $this->random($param === null ? 16 : (int) $param),
            'hash' => fn(FileSubject $s, ?string $param): string => $this->hashBody($param ?? 'sha256', $s),
            'ext' => static function (FileSubject $s): string {
                $ext = pathinfo($s->originalName(), PATHINFO_EXTENSION);

                return $ext === '' ? '' : '.' . strtolower($ext);
            },
            'name' => static fn(FileSubject $s): string => pathinfo($s->originalName(), PATHINFO_FILENAME),
            'safe_name' => static function (FileSubject $s): string {
                $name = pathinfo($s->originalName(), PATHINFO_FILENAME);
                $slug = trim(strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $name)), '-');

                return $slug === '' ? 'file' : $slug;
            },
        ];
    }

    /**
     * Hash body.
     *
     * @param string $algo
     * @param FileSubject $subject
     *
     * @return string
     */
    private function hashBody(string $algo, FileSubject $subject): string
    {
        if (!in_array($algo, hash_algos(), true)) {
            throw UnableToGeneratePath::unknownHashAlgorithm($algo);
        }

        $stream = $subject->stream();
        $context = hash_init($algo);
        while (!$stream->eof()) {
            $chunk = $stream->read(self::HASH_BUFFER);
            if ($chunk === '') {
                break;
            }
            hash_update($context, $chunk);
        }

        return hash_final($context);
    }

    /**
     * Random.
     *
     * @param int $length
     *
     * @return string
     */
    private function random(int $length): string
    {
        $length = max(1, $length);

        return substr(bin2hex(random_bytes(max(1, (int) ceil($length / 2)))), 0, $length);
    }

    /**
     * Uuid.
     *
     * @return string
     */
    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
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
