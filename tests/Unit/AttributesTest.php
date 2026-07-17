<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Tests\Unit;

use PHPdot\Container\Attribute\Binds;
use PHPdot\Container\Attribute\Config as ConfigSection;
use PHPdot\Container\Attribute\Singleton;
use PHPdot\Filesystem\Adapter\LocalAdapter;
use PHPdot\Filesystem\Contract\AdapterInterface;
use PHPdot\Filesystem\Contract\FilesystemInterface;
use PHPdot\Filesystem\Contract\PathNormalizer;
use PHPdot\Filesystem\Contract\SessionStoreInterface;
use PHPdot\Filesystem\Contract\UploadManagerInterface;
use PHPdot\Filesystem\Filesystem;
use PHPdot\Filesystem\FilesystemConfig;
use PHPdot\Filesystem\Path\WhitespacePathNormalizer;
use PHPdot\Filesystem\Upload\Store\LocalSessionStore;
use PHPdot\Filesystem\Upload\UploadManager;
use PHPdot\Filesystem\Write\WriteContents;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Verifies the container self-wiring metadata directly (no host app needed):
 * each service carries the right lifecycle + binding attributes, and the config
 * DTO declares its section and hydrates from defaults.
 */
final class AttributesTest extends TestCase
{
    /**
     * @param class-string $class
     * @param class-string $interface
     */
    #[DataProvider('bindingProvider')]
    public function testServiceIsSingletonBoundToInterface(string $class, string $interface): void
    {
        $reflection = new ReflectionClass($class);

        self::assertNotEmpty($reflection->getAttributes(Singleton::class), "{$class} must be #[Singleton]");

        $bindings = array_map(
            static fn($attribute): string => $attribute->newInstance()->interface,
            $reflection->getAttributes(Binds::class),
        );

        self::assertContains($interface, $bindings, "{$class} must bind {$interface}");
    }

    /**
     * @return iterable<string,array{class-string,class-string}>
     */
    public static function bindingProvider(): iterable
    {
        yield 'local adapter' => [LocalAdapter::class, AdapterInterface::class];
        yield 'operator' => [Filesystem::class, FilesystemInterface::class];
        yield 'path normalizer' => [WhitespacePathNormalizer::class, PathNormalizer::class];
        yield 'upload manager' => [UploadManager::class, UploadManagerInterface::class];
        yield 'session store' => [LocalSessionStore::class, SessionStoreInterface::class];
    }

    public function testWriteContentsIsASingleton(): void
    {
        self::assertNotEmpty((new ReflectionClass(WriteContents::class))->getAttributes(Singleton::class));
    }

    public function testFilesystemConfigDeclaresItsConfigSection(): void
    {
        $attributes = (new ReflectionClass(FilesystemConfig::class))->getAttributes(ConfigSection::class);

        self::assertNotEmpty($attributes);
        self::assertSame('filesystem', $attributes[0]->newInstance()->name);
    }

    public function testFilesystemConfigHydratesFromDefaults(): void
    {
        $config = new FilesystemConfig();

        self::assertSame('storage', $config->root);
        self::assertSame('private', $config->visibility);
        self::assertSame(8388608, $config->chunkSize);
        self::assertSame(86400, $config->sessionTtl);
        self::assertNull($config->publicUrl);
    }
}
