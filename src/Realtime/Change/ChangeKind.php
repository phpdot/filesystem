<?php

declare(strict_types=1);

/**
 * What a row event did.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Realtime\Change;

enum ChangeKind: string
{
    case INSERT = 'insert';
    case UPDATE = 'update';
    case DELETE = 'delete';
}
