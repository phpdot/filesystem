<?php

declare(strict_types=1);

namespace PHPdot\Filesystem\Tests\Unit\Realtime\Protocol;

use PHPdot\Filesystem\Exception\ReplicationAuthenticationFailed;
use PHPdot\Filesystem\Realtime\Protocol\Authentication;
use PHPUnit\Framework\TestCase;

final class AuthenticationTest extends TestCase
{
    public function testNativePasswordSatisfiesTheServerSideCheck(): void
    {
        // The server stores SHA1(SHA1(password)) and verifies a reply by
        // recovering SHA1(password) from it, then hashing that to compare. If
        // the concatenation order were wrong, this would not close.
        $password = 'correct horse battery staple';
        $scramble = random_bytes(20);

        $reply = Authentication::nativePassword($password, $scramble);
        $stored = hash('sha1', hash('sha1', $password, true), true);

        $recovered = $reply ^ hash('sha1', $scramble . $stored, true);

        self::assertSame($stored, hash('sha1', $recovered, true));
    }

    public function testCachingSha2SatisfiesTheServerSideCheck(): void
    {
        $password = 'hunter2';
        $scramble = random_bytes(20);

        $reply = Authentication::cachingSha2Password($password, $scramble);
        $stage1 = hash('sha256', $password, true);
        $stage2 = hash('sha256', $stage1, true);

        self::assertSame($stage1, $reply ^ hash('sha256', $stage2 . $scramble, true));
        self::assertSame(32, strlen($reply));
    }

    public function testAnEmptyPasswordSendsNothingAtAll(): void
    {
        // Hashing the empty string would be a valid-looking credential; MySQL
        // expects a zero-length response to mean "no password".
        self::assertSame('', Authentication::scramble(Authentication::NATIVE_PASSWORD, '', random_bytes(20)));
        self::assertSame('', Authentication::scramble(Authentication::CACHING_SHA2_PASSWORD, '', random_bytes(20)));
    }

    public function testScrambleDispatchesOnPluginName(): void
    {
        $scramble = random_bytes(20);

        self::assertSame(
            Authentication::nativePassword('pw', $scramble),
            Authentication::scramble(Authentication::NATIVE_PASSWORD, 'pw', $scramble),
        );

        self::assertSame(
            Authentication::cachingSha2Password('pw', $scramble),
            Authentication::scramble(Authentication::CACHING_SHA2_PASSWORD, 'pw', $scramble),
        );
    }

    public function testAnUnknownPluginIsRefusedRatherThanGuessedAt(): void
    {
        $this->expectException(ReplicationAuthenticationFailed::class);

        Authentication::scramble('auth_gssapi_client', 'pw', random_bytes(20));
    }

    public function testScrambleDiffersPerConnectionNonce(): void
    {
        self::assertNotSame(
            Authentication::nativePassword('pw', str_repeat("\x01", 20)),
            Authentication::nativePassword('pw', str_repeat("\x02", 20)),
        );
    }

    public function testXorRepeatingCoversValuesLongerThanTheKey(): void
    {
        $value = 'abcdefghij';
        $key = "\x0F\xF0";

        $obfuscated = Authentication::xorRepeating($value, $key);

        self::assertSame(strlen($value), strlen($obfuscated));
        self::assertSame($value, Authentication::xorRepeating($obfuscated, $key));
    }
}
