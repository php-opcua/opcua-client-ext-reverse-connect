<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtReverseConnect;

use Closure;
use PhpOpcua\Client\Client;
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Transport\TcpTransport;

/**
 * Bridge between an accepted {@see ReverseConnectSession} and the standard
 * {@see ClientBuilder} flow of `php-opcua/opcua-client`.
 *
 * Wraps the live socket from the session into a
 * {@see TcpTransport::fromConnectedSocket()} transport, hands it to a fresh
 * `ClientBuilder` via {@see ClientBuilder::setTransport()}, then runs
 * {@see ClientBuilder::connect()} on the announced `EndpointUrl`. The
 * `Client::connect()` pipeline detects the already-connected transport (via
 * `ManagesConnectionTrait::performConnect()` in `opcua-client` v4.4.0+) and
 * skips the redundant TCP-level connect, jumping straight to the UA-TCP
 * HEL/ACK handshake on the inherited socket.
 *
 * A caller-supplied {@see Closure} (the `$configure` argument of
 * {@see buildClient()}) can tune the builder before connect — typically to
 * set security policy, identity credentials, an event dispatcher, a custom
 * logger, or to register extra modules.
 */
final class ReverseConnectClientFactory
{
    /**
     * Turn a validated reverse-connect session into a fully connected
     * {@see Client}.
     *
     * @param ReverseConnectSession $session Output of
     *                                       {@see ReverseConnectListener::accept()}.
     * @param null|(Closure(ClientBuilder): void) $configure Optional callback
     *                                                       invoked on the
     *                                                       builder before
     *                                                       `connect()` so
     *                                                       the caller can
     *                                                       set security,
     *                                                       credentials, …
     * @param null|float $readTimeout Read timeout applied to the inherited
     *                                socket. `null` uses the
     *                                {@see TcpTransport::DEFAULT_TIMEOUT}.
     */
    public function buildClient(
        ReverseConnectSession $session,
        ?Closure $configure = null,
        ?float $readTimeout = null,
    ): Client {
        $transport = TcpTransport::fromConnectedSocket($session->socket, $readTimeout);

        $builder = ClientBuilder::create();
        $builder->setTransport($transport);

        if ($configure !== null) {
            $configure($builder);
        }

        return $builder->connect($session->endpointUrl);
    }
}
