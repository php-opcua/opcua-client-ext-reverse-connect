<?php

declare(strict_types=1);

namespace PhpOpcua\Client\ExtReverseConnect;

use PhpOpcua\Client\ExtReverseConnect\Event\ReverseConnectAccepted;
use PhpOpcua\Client\ExtReverseConnect\Event\ReverseConnectRejected;
use PhpOpcua\Client\ExtReverseConnect\Event\ReverseHelloReceived;
use PhpOpcua\Client\ExtReverseConnect\Exception\ReverseConnectException;
use PhpOpcua\Client\ExtReverseConnect\Exception\ReverseConnectRejectedException;
use PhpOpcua\Client\ExtReverseConnect\Exception\ReverseConnectTimeoutException;
use PhpOpcua\Client\ExtReverseConnect\Exception\ReverseHelloParseException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Blocking listener for OPC UA Reverse Connect (OPC UA Part 6 §7.1.2.3).
 *
 * Opens a TCP server socket on the configured bind address, accepts inbound
 * connections from announcing servers, reads and decodes the ReverseHello
 * (`RHE`) frame, and validates it against the supplied
 * {@see ReverseHelloValidator}. On success the live socket is wrapped in a
 * {@see ReverseConnectSession} and returned — at that point the standard
 * UA-TCP handshake (HEL/ACK, OPN, CreateSession, …) can proceed via
 * `TcpTransport::fromConnectedSocket()`.
 *
 * The listener is bound to a single thread: it does *not* implement an event
 * loop. {@see accept()} is bounded by a caller-supplied timeout via
 * {@see stream_select()} and throws
 * {@see ReverseConnectTimeoutException} on expiry. To service multiple
 * servers, call {@see accept()} in a loop.
 *
 * @see https://reference.opcfoundation.org/Core/Part6/v105/docs/7.1.2.3
 */
final class ReverseConnectListener
{
    /** @var resource|null */
    private $server = null;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly string $bindHost,
        private readonly int $bindPort,
        private readonly ReverseHelloValidator $validator,
        ?LoggerInterface $logger = null,
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly int $maxFrameSize = ReverseHelloParser::DEFAULT_MAX_FRAME_SIZE,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Open the TCP server socket on the configured bind address.
     *
     * Idempotent: subsequent calls without a {@see close()} are a no-op.
     *
     * @throws ReverseConnectException If the socket cannot be opened.
     */
    public function listen(): void
    {
        if ($this->server !== null) {
            return;
        }

        $errno = 0;
        $errstr = '';
        $server = @stream_socket_server(
            "tcp://{$this->bindHost}:{$this->bindPort}",
            $errno,
            $errstr,
        );

        if ($server === false) {
            throw new ReverseConnectException(
                "Failed to bind reverse-connect listener on {$this->bindHost}:{$this->bindPort}: [{$errno}] {$errstr}",
            );
        }

        $this->server = $server;
        $this->logger->info(
            'Reverse-connect listener bound',
            ['address' => $this->getBindAddress()],
        );
    }

    /**
     * The actual bound address as reported by the kernel.
     *
     * Useful when the listener was created with port `0` (kernel-assigned)
     * so that the test or caller can discover the real port.
     *
     * @return string `host:port`
     *
     * @throws ReverseConnectException If the listener is not started.
     */
    public function getBindAddress(): string
    {
        if ($this->server === null) {
            throw new ReverseConnectException('Listener is not started; call listen() first.');
        }

        $name = stream_socket_get_name($this->server, false);
        if ($name === false) {
            throw new ReverseConnectException('Failed to read the listener bind address');
        }

        return $name;
    }

    public function isListening(): bool
    {
        return $this->server !== null;
    }

    /**
     * Block until a server announces itself via ReverseHello, or the timeout
     * elapses.
     *
     * On success the accepted socket is owned by the returned
     * {@see ReverseConnectSession} — closing the session's transport closes
     * the socket. On failure (parse error, validation rejection) the socket
     * is closed before the exception is thrown.
     *
     * @param float $timeoutSeconds Total wall-clock budget for accepting an
     *                              inbound connection *and* reading the RHE
     *                              frame.
     *
     * @throws ReverseConnectException If the listener is not started, or
     *                                 the kernel-level accept fails.
     * @throws ReverseConnectTimeoutException If no connection arrives within
     *                                        `$timeoutSeconds`.
     * @throws ReverseHelloParseException If the peer sends a frame that
     *                                    cannot be decoded.
     * @throws ReverseConnectRejectedException If the validator rejects the
     *                                         decoded message.
     */
    public function accept(float $timeoutSeconds): ReverseConnectSession
    {
        if ($this->server === null) {
            throw new ReverseConnectException('Listener is not started; call listen() first.');
        }

        $read = [$this->server];
        $write = null;
        $except = null;
        $sec = (int) $timeoutSeconds;
        $usec = (int) (($timeoutSeconds - $sec) * 1_000_000);

        $changed = @stream_select($read, $write, $except, $sec, $usec);
        if ($changed === false) {
            throw new ReverseConnectException('stream_select() failed while waiting for inbound connection');
        }
        if ($changed === 0) {
            throw new ReverseConnectTimeoutException(
                sprintf('No inbound reverse-connect connection within %.3fs', $timeoutSeconds),
            );
        }

        $socket = @stream_socket_accept($this->server, 0);
        if ($socket === false) {
            throw new ReverseConnectException('stream_socket_accept() failed');
        }

        stream_set_timeout($socket, max(1, $sec));

        try {
            $message = $this->readFrame($socket);
        } catch (ReverseHelloParseException $e) {
            @fclose($socket);
            $this->logger->warning('ReverseHello parse failed', ['error' => $e->getMessage()]);

            throw $e;
        } catch (\Throwable $e) {
            @fclose($socket);

            throw $e;
        }

        $this->dispatch(new ReverseHelloReceived($message));

        try {
            $this->validator->ensureAccepted($message);
        } catch (ReverseConnectRejectedException $e) {
            @fclose($socket);
            $this->dispatch(new ReverseConnectRejected($message, $e->getMessage()));
            $this->logger->warning(
                'ReverseHello rejected by validator',
                [
                    'serverUri' => $message->serverUri,
                    'endpointUrl' => $message->endpointUrl,
                    'reason' => $e->getMessage(),
                ],
            );

            throw $e;
        }

        $this->dispatch(new ReverseConnectAccepted($message));
        $this->logger->info(
            'ReverseHello accepted',
            ['serverUri' => $message->serverUri, 'endpointUrl' => $message->endpointUrl],
        );

        return new ReverseConnectSession($message->serverUri, $message->endpointUrl, $socket);
    }

    /**
     * Close the listener socket. Safe to call multiple times.
     */
    public function close(): void
    {
        if ($this->server !== null) {
            @fclose($this->server);
            $this->server = null;
        }
    }

    /**
     * @param resource $socket
     */
    private function readFrame($socket): ReverseHelloMessage
    {
        $header = $this->readExactly($socket, 8);
        $messageSize = unpack('V', substr($header, 4, 4))[1];

        if ($messageSize < ReverseHelloParser::MIN_FRAME_SIZE) {
            throw new ReverseHelloParseException(
                "Declared MessageSize {$messageSize} is below the minimum frame size " . ReverseHelloParser::MIN_FRAME_SIZE,
            );
        }

        if ($messageSize > $this->maxFrameSize) {
            throw new ReverseHelloParseException(
                "Declared MessageSize {$messageSize} exceeds the configured maximum {$this->maxFrameSize}",
            );
        }

        $remaining = $messageSize - 8;
        $body = $remaining > 0 ? $this->readExactly($socket, $remaining) : '';

        return ReverseHelloParser::parse($header . $body, $this->maxFrameSize);
    }

    /**
     * @param resource $socket
     */
    private function readExactly($socket, int $length): string
    {
        $data = '';
        $remaining = $length;

        while ($remaining > 0) {
            $chunk = @fread($socket, $remaining);
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($socket);
                if ($meta['timed_out']) {
                    throw new ReverseHelloParseException('Timeout while reading ReverseHello frame from peer');
                }

                throw new ReverseHelloParseException('Connection closed by peer before ReverseHello frame was complete');
            }
            $data .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $data;
    }

    private function dispatch(object $event): void
    {
        $this->dispatcher?->dispatch($event);
    }
}
