<?php

declare(strict_types=1);

/**
 * The scramble algorithms behind MySQL's two current password plugins.
 *
 * Pure functions over (password, scramble) — no I/O, so the vectors are
 * testable without a server. The password is never sent, and never hashed in a
 * way that is reusable off this one connection: both plugins fold the server's
 * per-connection nonce into the digest.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Realtime\Protocol;

use PHPdot\Filesystem\Exception\ReplicationAuthenticationFailed;

final class Authentication
{
    public const NATIVE_PASSWORD = 'mysql_native_password';
    public const CACHING_SHA2_PASSWORD = 'caching_sha2_password';
    public const SHA256_PASSWORD = 'sha256_password';

    /**
     * Scramble for the named plugin.
     *
     * An empty password always yields an empty response — MySQL reads that as
     * "no credential offered" rather than as a hash of the empty string.
     *
     * @param string $plugin
     * @param string $password
     * @param string $scramble
     *
     * @return string
     */
    public static function scramble(string $plugin, string $password, string $scramble): string
    {
        if ($password === '') {
            return '';
        }

        return match ($plugin) {
            self::NATIVE_PASSWORD => self::nativePassword($password, $scramble),
            self::CACHING_SHA2_PASSWORD => self::cachingSha2Password($password, $scramble),
            self::SHA256_PASSWORD => '',
            default => throw ReplicationAuthenticationFailed::unsupportedPlugin($plugin),
        };
    }

    /**
     * mysql_native_password: SHA1(password) XOR SHA1(scramble . SHA1(SHA1(password))).
     *
     * @param string $password
     * @param string $scramble
     *
     * @return string
     */
    public static function nativePassword(string $password, string $scramble): string
    {
        $stage1 = hash('sha1', $password, true);
        $stage2 = hash('sha1', $stage1, true);

        return self::xorStrings($stage1, hash('sha1', $scramble . $stage2, true));
    }

    /**
     * caching_sha2_password fast path:
     * SHA256(password) XOR SHA256(SHA256(SHA256(password)) . scramble).
     *
     * @param string $password
     * @param string $scramble
     *
     * @return string
     */
    public static function cachingSha2Password(string $password, string $scramble): string
    {
        $stage1 = hash('sha256', $password, true);
        $stage2 = hash('sha256', $stage1, true);

        return self::xorStrings($stage1, hash('sha256', $stage2 . $scramble, true));
    }

    /**
     * The payload for caching_sha2/sha256 full authentication against an RSA
     * public key: the NUL-terminated password XOR the scramble, then OAEP.
     *
     * @param string $password
     * @param string $scramble
     * @param string $publicKey
     *
     * @return string
     */
    public static function encryptWithPublicKey(string $password, string $scramble, string $publicKey): string
    {
        if (!function_exists('openssl_public_encrypt')) {
            throw ReplicationAuthenticationFailed::insecureFullAuthentication();
        }

        $obfuscated = self::xorRepeating($password . "\0", $scramble);
        $encrypted = '';

        if (!openssl_public_encrypt($obfuscated, $encrypted, $publicKey, OPENSSL_PKCS1_OAEP_PADDING) || !is_string($encrypted)) {
            throw ReplicationAuthenticationFailed::rejected('', 'the server RSA public key could not be used');
        }

        return $encrypted;
    }

    /**
     * XOR two equal-length strings.
     *
     * @param string $left
     * @param string $right
     *
     * @return string
     */
    public static function xorStrings(string $left, string $right): string
    {
        return $left ^ $right;
    }

    /**
     * XOR a string against a key that repeats to cover it.
     *
     * @param string $value
     * @param string $key
     *
     * @return string
     */
    public static function xorRepeating(string $value, string $key): string
    {
        $keyLength = strlen($key);

        if ($keyLength === 0) {
            return $value;
        }

        $repeated = str_repeat($key, intdiv(strlen($value), $keyLength) + 1);

        return $value ^ substr($repeated, 0, strlen($value));
    }
}
