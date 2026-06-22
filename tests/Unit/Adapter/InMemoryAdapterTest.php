<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Tests\Unit\Adapter;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPdot\Filesystem\Adapter\InMemoryAdapter;
use PHPdot\Filesystem\Contract\AdapterInterface;

final class InMemoryAdapterTest extends AdapterTestCase
{
    protected function createAdapter(): AdapterInterface
    {
        return new InMemoryAdapter(new Psr17Factory());
    }

    public function testChecksumHashesContent(): void
    {
        $adapter = new InMemoryAdapter(new Psr17Factory());
        $adapter->write('sum.txt', $this->stream('hash me'), new \PHPdot\Filesystem\Config());

        self::assertSame(hash('sha256', 'hash me'), $adapter->checksum('sum.txt', 'sha256'));
    }
}
