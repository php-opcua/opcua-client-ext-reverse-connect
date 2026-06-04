<?php

declare(strict_types=1);

use PhpOpcua\Client\ExtReverseConnect\Event\ReverseConnectAccepted;
use PhpOpcua\Client\ExtReverseConnect\Event\ReverseConnectRejected;
use PhpOpcua\Client\ExtReverseConnect\Event\ReverseHelloReceived;
use PhpOpcua\Client\ExtReverseConnect\Exception\ReverseConnectException;
use PhpOpcua\Client\ExtReverseConnect\Exception\ReverseConnectRejectedException;
use PhpOpcua\Client\ExtReverseConnect\Exception\ReverseConnectTimeoutException;
use PhpOpcua\Client\ExtReverseConnect\Exception\ReverseHelloParseException;
use PhpOpcua\Client\ExtReverseConnect\ReverseConnectListener;
use PhpOpcua\Client\ExtReverseConnect\ReverseHelloValidator;
use PhpOpcua\Client\ExtReverseConnect\Tests\Unit\Helpers\InMemoryEventDispatcher;

/**
 * Build a syntactically valid RHE frame for the given URIs.
 */
function rcListenerFrame(string $serverUri, string $endpointUrl): string
{
    $payload = pack('V', strlen($serverUri)) . $serverUri
        . pack('V', strlen($endpointUrl)) . $endpointUrl;

    return 'RHE' . 'F' . pack('V', 8 + strlen($payload)) . $payload;
}

/**
 * Connect to the listener's bound address and write the given bytes.
 *
 * @return resource the client-side stream
 */
function rcDial(ReverseConnectListener $listener, string $bytes)
{
    $addr = $listener->getBindAddress();
    [, $port] = explode(':', $addr);

    $errno = 0;
    $errstr = '';
    $client = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 2.0);
    if ($client === false) {
        throw new RuntimeException("stream_socket_client failed: [{$errno}] {$errstr}");
    }
    if ($bytes !== '') {
        fwrite($client, $bytes);
    }

    return $client;
}

describe('ReverseConnectListener', function () {

    it('binds on a kernel-assigned port and exposes it via getBindAddress', function () {
        $listener = new ReverseConnectListener('127.0.0.1', 0, new ReverseHelloValidator(['urn:x']));

        try {
            $listener->listen();
            expect($listener->isListening())->toBeTrue();
            $addr = $listener->getBindAddress();
            expect($addr)->toStartWith('127.0.0.1:');
            [, $port] = explode(':', $addr);
            expect((int) $port)->toBeGreaterThan(0);
        } finally {
            $listener->close();
        }
    });

    it('listen() is idempotent', function () {
        $listener = new ReverseConnectListener('127.0.0.1', 0, new ReverseHelloValidator(['urn:x']));

        try {
            $listener->listen();
            $first = $listener->getBindAddress();
            $listener->listen();
            $second = $listener->getBindAddress();
            expect($second)->toBe($first);
        } finally {
            $listener->close();
        }
    });

    it('getBindAddress throws before listen() is called', function () {
        $listener = new ReverseConnectListener('127.0.0.1', 0, new ReverseHelloValidator(['urn:x']));

        expect(fn () => $listener->getBindAddress())
            ->toThrow(ReverseConnectException::class, 'not started');
    });

    it('accept() throws before listen() is called', function () {
        $listener = new ReverseConnectListener('127.0.0.1', 0, new ReverseHelloValidator(['urn:x']));

        expect(fn () => $listener->accept(0.1))
            ->toThrow(ReverseConnectException::class, 'not started');
    });

    it('isListening returns false before listen() and after close()', function () {
        $listener = new ReverseConnectListener('127.0.0.1', 0, new ReverseHelloValidator(['urn:x']));

        expect($listener->isListening())->toBeFalse();
        $listener->listen();
        expect($listener->isListening())->toBeTrue();
        $listener->close();
        expect($listener->isListening())->toBeFalse();
    });

    it('close() is idempotent', function () {
        $listener = new ReverseConnectListener('127.0.0.1', 0, new ReverseHelloValidator(['urn:x']));

        $listener->listen();
        $listener->close();
        $listener->close();
        expect($listener->isListening())->toBeFalse();
    });

    it('accept() times out when no peer connects within the budget', function () {
        $listener = new ReverseConnectListener('127.0.0.1', 0, new ReverseHelloValidator(['urn:x']));
        $listener->listen();

        try {
            expect(fn () => $listener->accept(0.2))
                ->toThrow(ReverseConnectTimeoutException::class, 'No inbound reverse-connect connection');
        } finally {
            $listener->close();
        }
    });

    it('accept() returns a ReverseConnectSession on a valid handshake', function () {
        $dispatcher = new InMemoryEventDispatcher();
        $listener = new ReverseConnectListener(
            '127.0.0.1',
            0,
            new ReverseHelloValidator(['urn:trusted:server']),
            dispatcher: $dispatcher,
        );
        $listener->listen();
        $client = rcDial($listener, rcListenerFrame('urn:trusted:server', 'opc.tcp://server.example:4840'));

        try {
            $session = $listener->accept(2.0);

            expect($session->serverUri)->toBe('urn:trusted:server');
            expect($session->endpointUrl)->toBe('opc.tcp://server.example:4840');
            expect(is_resource($session->socket))->toBeTrue();

            expect($dispatcher->hasEvent(ReverseHelloReceived::class))->toBeTrue();
            expect($dispatcher->hasEvent(ReverseConnectAccepted::class))->toBeTrue();
            expect($dispatcher->hasEvent(ReverseConnectRejected::class))->toBeFalse();

            $accepted = $dispatcher->getEventsOfType(ReverseConnectAccepted::class)[0];
            expect($accepted->message->serverUri)->toBe('urn:trusted:server');
        } finally {
            @fclose($session->socket);
            @fclose($client);
            $listener->close();
        }
    });

    it('rejects an RHE whose ServerUri is not whitelisted and closes the socket', function () {
        $dispatcher = new InMemoryEventDispatcher();
        $listener = new ReverseConnectListener(
            '127.0.0.1',
            0,
            new ReverseHelloValidator(['urn:trusted:server']),
            dispatcher: $dispatcher,
        );
        $listener->listen();
        $client = rcDial($listener, rcListenerFrame('urn:impostor', 'opc.tcp://server.example:4840'));

        try {
            try {
                $listener->accept(2.0);
                expect(false)->toBeTrue();
            } catch (ReverseConnectRejectedException $e) {
                expect($e->getMessage())->toContain('not in the configured whitelist');
                expect($e->rejectedMessage->serverUri)->toBe('urn:impostor');
            }

            expect($dispatcher->hasEvent(ReverseHelloReceived::class))->toBeTrue();
            expect($dispatcher->hasEvent(ReverseConnectRejected::class))->toBeTrue();
            expect($dispatcher->hasEvent(ReverseConnectAccepted::class))->toBeFalse();

            $rejectedEvent = $dispatcher->getEventsOfType(ReverseConnectRejected::class)[0];
            expect($rejectedEvent->reason)->toContain('whitelist');
        } finally {
            @fclose($client);
            $listener->close();
        }
    });

    it('throws ReverseHelloParseException when the peer sends a frame with wrong MessageType', function () {
        $listener = new ReverseConnectListener('127.0.0.1', 0, new ReverseHelloValidator(['urn:trusted:server']));
        $listener->listen();
        $bogus = 'HEL' . 'F' . pack('V', 16) . pack('l', 0) . pack('l', 0);
        $client = rcDial($listener, $bogus);

        try {
            expect(fn () => $listener->accept(2.0))
                ->toThrow(ReverseHelloParseException::class);
        } finally {
            @fclose($client);
            $listener->close();
        }
    });

    it('rejects an RHE whose declared MessageSize is below the minimum', function () {
        $listener = new ReverseConnectListener('127.0.0.1', 0, new ReverseHelloValidator(['urn:x']));
        $listener->listen();
        $bogus = 'RHE' . 'F' . pack('V', 12);
        $client = rcDial($listener, $bogus);

        try {
            expect(fn () => $listener->accept(2.0))
                ->toThrow(ReverseHelloParseException::class, 'below the minimum');
        } finally {
            @fclose($client);
            $listener->close();
        }
    });

    it('rejects an RHE whose declared MessageSize exceeds maxFrameSize', function () {
        $listener = new ReverseConnectListener(
            '127.0.0.1',
            0,
            new ReverseHelloValidator(['urn:x']),
            maxFrameSize: 64,
        );
        $listener->listen();
        $bogus = 'RHE' . 'F' . pack('V', 9999);
        $client = rcDial($listener, $bogus);

        try {
            expect(fn () => $listener->accept(2.0))
                ->toThrow(ReverseHelloParseException::class, 'exceeds the configured maximum');
        } finally {
            @fclose($client);
            $listener->close();
        }
    });

    it('throws ReverseHelloParseException when the peer closes mid-frame', function () {
        $listener = new ReverseConnectListener('127.0.0.1', 0, new ReverseHelloValidator(['urn:x']));
        $listener->listen();
        $partial = 'RHE' . 'F' . pack('V', 100);
        $client = rcDial($listener, $partial);
        fclose($client);

        try {
            expect(fn () => $listener->accept(2.0))
                ->toThrow(ReverseHelloParseException::class, 'Connection closed by peer');
        } finally {
            $listener->close();
        }
    });

    it('throws ReverseHelloParseException when the peer stalls and the frame read times out', function () {
        $listener = new ReverseConnectListener('127.0.0.1', 0, new ReverseHelloValidator(['urn:x']));
        $listener->listen();
        // Announce a 100-byte frame, send only the 8-byte header, then stay
        // connected and silent so the body read hits the socket timeout.
        $partial = 'RHE' . 'F' . pack('V', 100);
        $client = rcDial($listener, $partial);

        try {
            expect(fn () => $listener->accept(1.0))
                ->toThrow(ReverseHelloParseException::class, 'Timeout while reading ReverseHello frame');
        } finally {
            fclose($client);
            $listener->close();
        }
    });

    it('throws ReverseConnectException when bind fails on an unresolvable host', function () {
        $listener = new ReverseConnectListener(
            'not-a-real-host-rc-test.invalid',
            12345,
            new ReverseHelloValidator(['urn:x']),
        );

        set_error_handler(static fn () => true);

        try {
            expect(fn () => $listener->listen())
                ->toThrow(ReverseConnectException::class, 'Failed to bind reverse-connect listener');
        } finally {
            restore_error_handler();
        }
    });

    it('works without an event dispatcher (NullLogger + null dispatcher path)', function () {
        $listener = new ReverseConnectListener('127.0.0.1', 0, new ReverseHelloValidator(['urn:trusted:server']));
        $listener->listen();
        $client = rcDial($listener, rcListenerFrame('urn:trusted:server', 'opc.tcp://h:1'));

        try {
            $session = $listener->accept(2.0);
            expect($session->serverUri)->toBe('urn:trusted:server');
        } finally {
            @fclose($session->socket);
            @fclose($client);
            $listener->close();
        }
    });
});
