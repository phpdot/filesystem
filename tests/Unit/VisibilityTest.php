<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Tests\Unit;

use PHPdot\Filesystem\Exception\InvalidVisibilityProvided;
use PHPdot\Filesystem\Visibility;
use PHPUnit\Framework\TestCase;

final class VisibilityTest extends TestCase
{
    public function testParseValid(): void
    {
        self::assertSame(Visibility::Public, Visibility::parse('public'));
        self::assertSame(Visibility::Private, Visibility::parse('private'));
    }

    public function testParseInvalidThrows(): void
    {
        $this->expectException(InvalidVisibilityProvided::class);

        Visibility::parse('secret');
    }

    public function testIsPublic(): void
    {
        self::assertTrue(Visibility::Public->isPublic());
        self::assertFalse(Visibility::Private->isPublic());
    }

    public function testValueRoundTrip(): void
    {
        self::assertSame('public', Visibility::Public->value);
        self::assertSame('private', Visibility::Private->value);
    }
}
