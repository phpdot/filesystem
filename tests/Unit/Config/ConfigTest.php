<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Tests\Unit\Config;

use Closure;
use PHPdot\Filesystem\Config;
use PHPdot\Filesystem\Exception\InvalidConfigurationValue;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testReturnsDefaultsForMissingKeys(): void
    {
        $config = new Config();

        self::assertFalse($config->has('nope'));
        self::assertNull($config->get('nope'));
        self::assertSame('fallback', $config->get('nope', 'fallback'));
        self::assertSame(7, $config->getInt('nope', 7));
        self::assertSame('x', $config->getString('nope', 'x'));
        self::assertTrue($config->getBool('nope', true));
        self::assertNull($config->getNullableString('nope'));
        self::assertNull($config->getCallable('nope'));
    }

    public function testTypedAccessorsReturnTypedValues(): void
    {
        $config = new Config([
            Config::CHUNK_SIZE => 1024,
            Config::VISIBILITY => 'public',
            Config::RETAIN_VISIBILITY => true,
        ]);

        self::assertSame(1024, $config->getInt(Config::CHUNK_SIZE));
        self::assertSame('public', $config->getString(Config::VISIBILITY));
        self::assertSame('public', $config->getNullableString(Config::VISIBILITY));
        self::assertTrue($config->getBool(Config::RETAIN_VISIBILITY));
        self::assertTrue($config->has(Config::CHUNK_SIZE));
    }

    public function testGetIntThrowsOnTypeMismatch(): void
    {
        $this->expectException(InvalidConfigurationValue::class);

        (new Config([Config::CHUNK_SIZE => 'big']))->getInt(Config::CHUNK_SIZE);
    }

    public function testGetStringThrowsOnTypeMismatch(): void
    {
        $this->expectException(InvalidConfigurationValue::class);

        (new Config(['x' => 123]))->getString('x');
    }

    public function testGetCallableNormalizesClosure(): void
    {
        $seen = 0;
        $config = new Config([Config::PROGRESS => static function (int $soFar, ?int $total) use (&$seen): void {
            $seen = $soFar;
        }]);

        $callback = $config->getCallable(Config::PROGRESS);

        self::assertInstanceOf(Closure::class, $callback);
        $callback(42, 100);
        self::assertSame(42, $seen);
    }

    public function testGetCallableWrapsInvokableObject(): void
    {
        $spy = new class {
            public int $seen = 0;

            public function __invoke(int $soFar, ?int $total): void
            {
                $this->seen = $soFar;
            }
        };

        $callback = (new Config(['cb' => $spy]))->getCallable('cb');

        self::assertInstanceOf(Closure::class, $callback);
        $callback(5, null);
        self::assertSame(5, $spy->seen);
    }

    public function testGetCallableThrowsOnNonCallable(): void
    {
        $this->expectException(InvalidConfigurationValue::class);

        (new Config([Config::PROGRESS => 99]))->getCallable(Config::PROGRESS);
    }

    public function testExtendOverridesAndIsImmutable(): void
    {
        $base = new Config(['a' => 1, 'b' => 2]);
        $extended = $base->extend(['b' => 20, 'c' => 30]);

        self::assertSame(2, $base->getInt('b'));
        self::assertSame(20, $extended->getInt('b'));
        self::assertSame(30, $extended->getInt('c'));
        self::assertSame(1, $extended->getInt('a'));
    }

    public function testWithDefaultsFillsOnlyAbsentKeys(): void
    {
        $config = (new Config(['a' => 1]))->withDefaults(['a' => 99, 'b' => 2]);

        self::assertSame(1, $config->getInt('a'));
        self::assertSame(2, $config->getInt('b'));
    }

    public function testToArrayRoundTrips(): void
    {
        $options = ['a' => 1, 'b' => 'two'];

        self::assertSame($options, (new Config($options))->toArray());
    }
}
