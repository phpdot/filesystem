<?php

declare(strict_types=1);

/**
 * The replication handshake was rejected, or asked for an auth plugin this
 * client cannot satisfy over the current transport.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Exception;

use RuntimeException;

final class ReplicationAuthenticationFailed extends RuntimeException implements FilesystemException
{
    public function errorCode(): string
    {
        return 'filesystem.replication_authentication_failed';
    }

    /**
     * Rejected.
     *
     * @param string $user
     * @param string $reason
     *
     * @return self
     */
    public static function rejected(string $user, string $reason): self
    {
        $detail = $reason !== '' ? $reason : 'no detail provided';

        return new self("MySQL rejected replication user \"{$user}\": {$detail}.");
    }

    /**
     * Unsupported plugin.
     *
     * @param string $plugin
     *
     * @return self
     */
    public static function unsupportedPlugin(string $plugin): self
    {
        return new self("Unsupported MySQL authentication plugin \"{$plugin}\".");
    }

    /**
     * caching_sha2_password full authentication needs either TLS or an RSA key
     * exchange; neither was available.
     *
     * @return self
     */
    public static function insecureFullAuthentication(): self
    {
        return new self(
            'caching_sha2_password requires full authentication, which needs TLS or ext-openssl '
            . 'for the RSA public-key exchange. Enable tls in the config, or install ext-openssl.',
        );
    }
}
