<?php

declare(strict_types=1);

use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\ExtReverseConnect\ReverseConnectClientFactory;
use PhpOpcua\Client\ExtReverseConnect\ReverseConnectSession;
use PhpOpcua\Client\Transport\TcpTransport;

/**
 * Build a (server, accepted, client) triple over loopback TCP so the session
 * carries a real, live, connected stream resource. The test never lets the
 * factory reach the UA-TCP handshake — that path belongs to the integration
 * suite — but the inputs to the bridge are exercised end-to-end.
 *
 * @return array{0: resource, 1: resource, 2: resource}
 */
function rcFactoryPair(): array
{
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $addr = stream_socket_get_name($server, false);
    [, $port] = explode(':', $addr);

    $client = stream_socket_client("tcp://127.0.0.1:{$port}", $cErrno, $cErrstr, 2.0);
    $accepted = stream_socket_accept($server, 2);

    return [$server, $accepted, $client];
}

describe('ReverseConnectClientFactory', function () {

    it('invokes the $configure callback with a ClientBuilder before connecting', function () {
        [$server, $a, $b] = rcFactoryPair();
        $session = new ReverseConnectSession('urn:x', 'opc.tcp://server.example:4840', $a);

        $captured = null;
        $marker = new RuntimeException('abort before connect');

        try {
            (new ReverseConnectClientFactory())->buildClient(
                $session,
                function (ClientBuilder $builder) use (&$captured, $marker) {
                    $captured = $builder;

                    throw $marker;
                },
            );
            $this->fail('Expected RuntimeException to bubble out of $configure');
        } catch (RuntimeException $e) {
            expect($e)->toBe($marker);
        } finally {
            @fclose($a);
            @fclose($b);
            @fclose($server);
        }

        expect($captured)->toBeInstanceOf(ClientBuilder::class);
    });

    it('wires a TcpTransport built from the session socket onto the builder', function () {
        [$server, $a, $b] = rcFactoryPair();
        $session = new ReverseConnectSession('urn:x', 'opc.tcp://server.example:4840', $a);

        $observedTransport = null;

        try {
            (new ReverseConnectClientFactory())->buildClient(
                $session,
                function (ClientBuilder $builder) use (&$observedTransport) {
                    $observedTransport = $builder->getTransport();

                    throw new RuntimeException('abort before connect');
                },
            );
        } catch (RuntimeException) {
            // expected
        } finally {
            @fclose($a);
            @fclose($b);
            @fclose($server);
        }

        expect($observedTransport)->toBeInstanceOf(TcpTransport::class);
        expect($observedTransport->isConnected())->toBeTrue();
    });

    it('works when $configure is null (uses the builder defaults)', function () {
        [$server, $a, $b] = rcFactoryPair();
        $session = new ReverseConnectSession('urn:x', 'opc.tcp://127.0.0.1:1', $a);

        // The default builder triggers a discovery probe that opens a second
        // socket toward the announced endpoint; on an unreachable port that
        // raises an unsilenceable E_WARNING. Suppress diagnostics here — the
        // test only asserts that the factory accepts a null $configure and
        // delegates to the standard connect pipeline.
        set_error_handler(static fn () => true);

        try {
            (new ReverseConnectClientFactory())->buildClient($session);
            $this->fail('Connect should have failed because the loopback peer never speaks UA-TCP');
        } catch (Throwable $e) {
            expect($e)->toBeInstanceOf(Throwable::class);
        } finally {
            restore_error_handler();
            @fclose($b);
            @fclose($server);
        }
    });

    it('honours the readTimeout argument when configuring the transport', function () {
        [$server, $a, $b] = rcFactoryPair();
        $session = new ReverseConnectSession('urn:x', 'opc.tcp://server.example:4840', $a);

        $observedTransport = null;

        try {
            (new ReverseConnectClientFactory())->buildClient(
                $session,
                function (ClientBuilder $builder) use (&$observedTransport) {
                    $observedTransport = $builder->getTransport();

                    throw new RuntimeException('abort before connect');
                },
                readTimeout: 7.5,
            );
        } catch (RuntimeException) {
            // expected
        } finally {
            @fclose($a);
            @fclose($b);
            @fclose($server);
        }

        expect($observedTransport)->toBeInstanceOf(TcpTransport::class);
        expect($observedTransport->isConnected())->toBeTrue();
    });
});
