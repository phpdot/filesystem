<?php

declare(strict_types=1);
namespace PHPdot\Filesystem;

use Closure;
use PHPdot\Filesystem\Exception\InvalidConfigurationValue;

/**
 * An immutable options bag passed through write/read operations.
 *
 * `get()` returns the raw value; the typed accessors are the preferred entry
 * points — they narrow `mixed` to a concrete type in one place and throw
 * {@see InvalidConfigurationValue} on a type mismatch.
 */
final class Config
{
    public const VISIBILITY = 'visibility';
    public const DIRECTORY_VISIBILITY = 'directory_visibility';
    public const PROGRESS = 'progress';
    public const MIME_TYPE = 'mimetype';
    public const CHUNK_SIZE = 'chunk_size';
    public const RETAIN_VISIBILITY = 'retain_visibility';
    public const EXPIRES_AT = 'expires_at';

    /**
     * @param array<string,mixed> $options
     */
    public function __construct(private readonly array $options = []) {}

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->options);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    public function getString(string $key, string $default = ''): string
    {
        $value = $this->options[$key] ?? $default;

        if (!is_string($value)) {
            throw InvalidConfigurationValue::forKey($key, 'string');
        }

        return $value;
    }

    public function getNullableString(string $key): ?string
    {
        $value = $this->options[$key] ?? null;

        if ($value !== null && !is_string($value)) {
            throw InvalidConfigurationValue::forKey($key, '?string');
        }

        return $value;
    }

    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->options[$key] ?? $default;

        if (!is_int($value)) {
            throw InvalidConfigurationValue::forKey($key, 'int');
        }

        return $value;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->options[$key] ?? $default;

        if (!is_bool($value)) {
            throw InvalidConfigurationValue::forKey($key, 'bool');
        }

        return $value;
    }

    /**
     * Resolve a key to a {@see Closure}, normalizing any callable. Returns null
     * when the key is absent.
     */
    public function getCallable(string $key): ?Closure
    {
        $value = $this->options[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_callable($value)) {
            throw InvalidConfigurationValue::forKey($key, 'callable');
        }

        return Closure::fromCallable($value);
    }

    /**
     * Return a new Config with the given options layered on top (override wins).
     *
     * @param array<string,mixed> $options
     */
    public function extend(array $options): self
    {
        return new self([...$this->options, ...$options]);
    }

    /**
     * Return a new Config with the given defaults filling only absent keys.
     *
     * @param array<string,mixed> $defaults
     */
    public function withDefaults(array $defaults): self
    {
        return new self([...$defaults, ...$this->options]);
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return $this->options;
    }
}
