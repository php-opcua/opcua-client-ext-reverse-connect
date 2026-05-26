<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtReverseConnect;

/**
 * Outcome of a successful Reverse Connect handshake: the validated message
 * plus the live stream socket left in the exact state a normal UA-TCP
 * `connect()` would have produced (TCP connected, no UA frame written yet).
 *
 * Pass instances to {@see ReverseConnectClientFactory::buildClient()} to
 * obtain an `OpcUaClient` whose transport is wired on top of the socket via
 * `TcpTransport::fromConnectedSocket()`.
 *
 * The session takes ownership of the socket: the consumer (typically the
 * factory) is responsible for closing it via the resulting transport.
 */
final readonly class ReverseConnectSession
{
    /**
     * @param string $serverUri Validated ServerUri the remote server announced.
     * @param string $endpointUrl Validated EndpointUrl the remote server
     *                            announced. Use as the endpoint argument of
     *                            the eventual `CreateSession` call.
     * @param resource $socket Live TCP stream socket, blocking, in CONNECTED
     *                         state, with the RHE frame already consumed.
     */
    public function __construct(
        public string $serverUri,
        public string $endpointUrl,
        public mixed $socket,
    ) {
    }
}
