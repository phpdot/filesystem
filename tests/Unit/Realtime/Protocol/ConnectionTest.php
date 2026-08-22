<?php

declare(strict_types=1);

namespace PHPdot\Filesystem\Tests\Unit\Realtime\Protocol;

use PHPdot\Filesystem\Exception\ReplicationAuthenticationFailed;
use PHPdot\Filesystem\Exception\ReplicationConnectionFailed;
use PHPdot\Filesystem\Realtime\Protocol\Authentication;
use PHPdot\Filesystem\Realtime\Protocol\Capability;
use PHPdot\Filesystem\Realtime\Protocol\Connection;
use PHPdot\Filesystem\Realtime\Protocol\PacketReader;
use PHPdot\Filesystem\Tests\Unit\Realtime\ScriptedPacketStream;
use PHPUnit\Framework\TestCase;

final class ConnectionTest extends TestCase
{
    private const SCRAMBLE = '12345678abcdefghijkl';

    /**
     * A Protocol::HandshakeV10 packet, as a server opens every connection.
     */
    private static function handshake(string $plugin = Authentication::NATIVE_PASSWORD): string
    {
        $capabilities = Capability::PROTOCOL_41 | Capability::SECURE_CONNECTION | Capability::PLUGIN_AUTH;

        return "\x0A"
            . "8.4.0\0"
            . pack('V', 17)
            . substr(self::SCRAMBLE, 0, 8)
            . "\0"
            . pack('v', $capabilities & 0xFFFF)
            . chr(45)
            . pack('v', 2)
            . pack('v', ($capabilities >> 16) & 0xFFFF)
            . chr(21)
            . str_repeat("\0", 10)
            . substr(self::SCRAMBLE, 8) . "\0"
            . $plugin . "\0";
    }

    /**
     * Parse the client's HandshakeResponse41.
     *
     * @return array{flags: int, user: string, auth: string, plugin: string}
     */
    private static function parseResponse(string $payload): array
    {
        $reader = new PacketReader($payload);
        $flags = $reader->uint32();
        $reader->skip(4 + 1 + 23);

        return [
            'flags' => $flags,
            'user' => $reader->nullTerminatedString(),
            'auth' => $reader->lengthEncodedString() ?? '',
            'plugin' => $reader->nullTerminatedString(),
        ];
    }

    public function testNativePasswordHandshakeSendsTheExpectedScramble(): void
    {
        $stream = (new ScriptedPacketStream())->push(self::handshake())->pushOk();

        (new Connection($stream))->authenticate('repl', 'secret');

        $response = self::parseResponse($stream->writes()[0]);

        self::assertSame('repl', $response['user']);
        self::assertSame(Authentication::NATIVE_PASSWORD, $response['plugin']);
        self::assertSame(Authentication::nativePassword('secret', self::SCRAMBLE), $response['auth']);
        self::assertSame(0, $response['flags'] & Capability::CONNECT_WITH_DB);
    }

    public function testTheServerVersionIsCaptured(): void
    {
        $stream = (new ScriptedPacketStream())->push(self::handshake())->pushOk();

        $connection = new Connection($stream);
        $connection->authenticate('repl', 'secret');

        self::assertSame('8.4.0', $connection->serverVersion());
    }

    public function testConnectingWithADatabaseSetsTheCapabilityFlag(): void
    {
        $stream = (new ScriptedPacketStream())->push(self::handshake())->pushOk();

        (new Connection($stream))->authenticate('repl', 'secret', 'shop');

        self::assertNotSame(0, self::parseResponse($stream->writes()[0])['flags'] & Capability::CONNECT_WITH_DB);
    }

    public function testAnEmptyPasswordSendsAZeroLengthCredential(): void
    {
        $stream = (new ScriptedPacketStream())->push(self::handshake())->pushOk();

        (new Connection($stream))->authenticate('repl', '');

        self::assertSame('', self::parseResponse($stream->writes()[0])['auth']);
    }

    public function testAnAuthSwitchRequestIsAnsweredWithTheNewPlugin(): void
    {
        $newScramble = 'zyxwvutsrqponmlkjihg';

        $stream = (new ScriptedPacketStream())
            ->push(self::handshake())
            ->push("\xFE" . Authentication::CACHING_SHA2_PASSWORD . "\0" . $newScramble . "\0")
            ->push("\x01\x03") // fast auth success
            ->pushOk();

        (new Connection($stream))->authenticate('repl', 'secret');

        $writes = $stream->writes();

        self::assertSame(Authentication::cachingSha2Password('secret', $newScramble), $writes[1]);
    }

    public function testCachingSha2FastPathCompletesWithoutSendingThePassword(): void
    {
        $stream = (new ScriptedPacketStream())
            ->push(self::handshake(Authentication::CACHING_SHA2_PASSWORD))
            ->push("\x01\x03")
            ->pushOk();

        (new Connection($stream))->authenticate('repl', 'secret');

        // Only the handshake response was written: the plaintext never left.
        self::assertCount(1, $stream->writes());
        self::assertStringNotContainsString('secret', $stream->writes()[0]);
    }

    public function testCachingSha2FullAuthOverTlsSendsThePasswordInClear(): void
    {
        $stream = (new ScriptedPacketStream())
            ->push(self::handshake(Authentication::CACHING_SHA2_PASSWORD))
            ->push("\x01\x04") // full authentication required
            ->pushOk();

        $stream->enableEncryption();

        (new Connection($stream))->authenticate('repl', 'secret');

        // Legitimate only because the channel is already encrypted.
        self::assertSame("secret\0", $stream->writes()[1]);
    }

    public function testCachingSha2FullAuthWithoutTlsRequestsThePublicKey(): void
    {
        $stream = (new ScriptedPacketStream())
            ->push(self::handshake(Authentication::CACHING_SHA2_PASSWORD))
            ->push("\x01\x04")
            ->push("\x01" . self::publicKey())
            ->pushOk();

        (new Connection($stream))->authenticate('repl', 'secret');

        $writes = $stream->writes();

        // A public-key request, then an RSA-encrypted credential — never the
        // password itself.
        self::assertSame("\x02", $writes[1]);
        self::assertNotSame('', $writes[2]);
        self::assertStringNotContainsString('secret', $writes[2]);
    }

    public function testRejectedCredentialsRaiseAnAuthenticationFailure(): void
    {
        $stream = (new ScriptedPacketStream())
            ->push(self::handshake())
            ->pushError(1045, "Access denied for user 'repl'@'localhost'");

        $this->expectException(ReplicationAuthenticationFailed::class);
        $this->expectExceptionMessageMatches('/Access denied/');

        (new Connection($stream))->authenticate('repl', 'wrong');
    }

    public function testAnErrorInsteadOfAHandshakeIsSurfaced(): void
    {
        $stream = (new ScriptedPacketStream())->pushError(1129, 'Host is blocked');

        $this->expectException(ReplicationConnectionFailed::class);
        $this->expectExceptionMessageMatches('/Host is blocked/');

        (new Connection($stream))->authenticate('repl', 'secret');
    }

    public function testAQueryReturnsItsRows(): void
    {
        $stream = (new ScriptedPacketStream())->push(self::handshake())->pushOk();
        $connection = new Connection($stream);
        $connection->authenticate('repl', 'secret');

        $stream->pushResultSet('ROW');

        self::assertSame([['ROW']], $connection->query('SELECT @@GLOBAL.binlog_format'));

        // Each query consumes its own scripted result set.
        $stream->pushResultSet('CRC32');

        self::assertSame('CRC32', $connection->serverVariable('binlog_checksum'));
    }

    public function testReadEventStripsTheLeadingOkByte(): void
    {
        $stream = (new ScriptedPacketStream())->push("\x00binlog-event-bytes");

        self::assertSame('binlog-event-bytes', (new Connection($stream))->readEvent());
    }

    public function testAnIdleStreamReadsAsNullRatherThanAnError(): void
    {
        self::assertNull((new Connection(new ScriptedPacketStream()))->readEvent());
    }

    public function testAServerErrorMidStreamIsRaised(): void
    {
        $stream = (new ScriptedPacketStream())->pushError(1236, 'Could not find first log file name');

        $this->expectException(ReplicationConnectionFailed::class);
        $this->expectExceptionMessageMatches('/1236/');

        (new Connection($stream))->readEvent();
    }

    /**
     * A throwaway RSA public key for the full-authentication exchange.
     */
    private static function publicKey(): string
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        self::assertNotFalse($resource);

        $details = openssl_pkey_get_details($resource);

        self::assertIsArray($details);
        self::assertIsString($details['key']);

        return $details['key'];
    }
}
