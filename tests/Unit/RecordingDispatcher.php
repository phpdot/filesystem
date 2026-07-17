<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Tests\Unit;

use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * A PSR-14 dispatcher that records every dispatched event for assertions.
 */
final class RecordingDispatcher implements EventDispatcherInterface
{
    /**
     * @var list<object>
     */
    public array $events = [];

    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        return $event;
    }
}
