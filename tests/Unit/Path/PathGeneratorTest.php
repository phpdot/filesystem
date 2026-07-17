<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Tests\Unit\Path;

use DateTimeImmutable;
use DateTimeZone;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPdot\Filesystem\Exception\UnableToGeneratePath;
use PHPdot\Filesystem\Path\PathGenerator;
use PHPdot\Filesystem\Validation\FileSubject;
use PHPdot\Filesystem\Write\WriteContents;
use PHPUnit\Framework\TestCase;

final class PathGeneratorTest extends TestCase
{
    public function testRendersDateAndExtensionTokens(): void
    {
        $key = $this->generator()->generate('{date}/{uuid}{ext}', $this->subject('photo.JPG'));

        $today = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y/m/d');
        self::assertStringStartsWith($today . '/', $key);
        self::assertStringEndsWith('.jpg', $key);
        self::assertMatchesRegularExpression('#^\d{4}/\d{2}/\d{2}/[0-9a-f-]{36}\.jpg$#', $key);
    }

    public function testRandomTokenHonoursLengthAndIsCryptoRandom(): void
    {
        $generator = $this->generator();
        $subject = $this->subject('a.txt');

        $first = $generator->generate('{random:32}', $subject);
        $second = $generator->generate('{random:32}', $subject);

        self::assertSame(32, strlen($first));
        self::assertNotSame($first, $second, 'Entropy must differ between renders.');
    }

    public function testSafeNameSlugifies(): void
    {
        $key = $this->generator()->generate('{safe_name}{ext}', $this->subject('My Résumé (final).PDF'));

        self::assertStringEndsWith('.pdf', $key);
        self::assertStringContainsString('my-r', $key);
        self::assertStringNotContainsString(' ', $key);
    }

    public function testHashTokenIsContentAddressed(): void
    {
        $subject = $this->subject('a.bin', 'fixed content');

        $key = $this->generator()->generate('{hash:sha256}', $subject);

        self::assertSame(hash('sha256', 'fixed content'), $key);
        // The body must survive hashing for the subsequent write.
        self::assertSame('fixed content', $subject->stream()->getContents());
    }

    public function testCollisionRetryRerollsEntropy(): void
    {
        $seen = [];
        $exists = static function (string $key) use (&$seen): bool {
            $first = !in_array($key, $seen, true) && $seen === [];
            $seen[] = $key;

            return $first; // first generated key "collides" once, forcing a re-roll.
        };

        $key = $this->generator()->generate('{uuid}', $this->subject('a.txt'), $exists);

        self::assertCount(2, $seen);
        self::assertSame($seen[1], $key);
        self::assertNotSame($seen[0], $seen[1]);
    }

    public function testThrowsWhenEntropylessKeyKeepsColliding(): void
    {
        $this->expectException(UnableToGeneratePath::class);

        $this->generator()->generate('{safe_name}', $this->subject('fixed.txt'), static fn(): bool => true);
    }

    public function testUnknownTokenThrows(): void
    {
        $this->expectException(UnableToGeneratePath::class);

        $this->generator()->generate('{nope}', $this->subject('a.txt'));
    }

    public function testCustomTokenCanBeRegistered(): void
    {
        $generator = $this->generator();
        $generator->addToken('shard', static fn(): string => 'shard-7');

        self::assertSame('shard-7/a.txt', $generator->generate('{shard}/{name}{ext}', $this->subject('a.txt')));
    }

    private function generator(): PathGenerator
    {
        return new PathGenerator();
    }

    private function subject(string $name, string $contents = 'body'): FileSubject
    {
        return FileSubject::fromContents($contents, $name, new WriteContents(new Psr17Factory()), new Psr17Factory());
    }
}
