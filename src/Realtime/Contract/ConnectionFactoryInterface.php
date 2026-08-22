<?php

declare(strict_types=1);

/**
 * Produces authenticated MySQL connections.
 *
 * Exists so the client's reconnect loop can be driven in tests: each call
 * yields a fresh connection, and a test can hand back a scripted one.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Realtime\Contract;

use PHPdot\Filesystem\Realtime\Protocol\Connection;

interface ConnectionFactoryInterface
{
    /**
     * Open and authenticate a connection.
     *
     * @return Connection
     */
    public function connect(): Connection;
}
