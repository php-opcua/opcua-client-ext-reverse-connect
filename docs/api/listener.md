---
eyebrow: 'Docs · API'
lede:    'ReverseConnectListener — bind a TCP server socket, accept inbound ReverseHello frames, and return validated ReverseConnectSession instances.'

see_also:
  - { href: './validator.md',                meta: '3 min' }
  - { href: './factory.md',                  meta: '3 min' }
  - { href: '../reference/exceptions.md',    meta: '3 min' }

prev: { label: 'How Reverse Connect works', href: '../concepts/how-it-works.md' }
next: { label: 'Validator API',             href: './validator.md' }
---

# `ReverseConnectListener`

Fully qualified name:
`PhpOpcua\Client\ExtReverseConnect\ReverseConnectListener`.

## Constructor

```php
public function __construct(
    string $bindHost,
    int $bindPort,
    ReverseHelloValidator $validator,
    ?LoggerInterface $logger = null,
    ?EventDispatcherInterface $dispatcher = null,
    int $maxFrameSize = ReverseHelloParser::DEFAULT_MAX_FRAME_SIZE,
)
```

| Argument             | Type                          | Notes                                                                                                                          |
| -------------------- | ----------------------------- | ------------------------------------------------------------------------------------------------------------------------------ |
| `$bindHost`          | `string`                      | Interface to bind on. `'0.0.0.0'` for every interface, `'127.0.0.1'` for loopback only, a specific address for multi-homed hosts. |
| `$bindPort`          | `int`                         | TCP port. `0` lets the kernel pick a free port; read it back via `getBindAddress()` after `listen()`.                          |
| `$validator`         | `ReverseHelloValidator`       | Required. The whitelist that gates accepted frames.                                                                            |
| `$logger`            | `?LoggerInterface`            | Optional PSR-3 logger. Defaults to `Psr\Log\NullLogger`.                                                                       |
| `$dispatcher`        | `?EventDispatcherInterface`   | Optional PSR-14 dispatcher. When `null`, no events are emitted.                                                                |
| `$maxFrameSize`      | `int`                         | Upper bound on the declared `MessageSize` of an RHE frame. Defaults to `ReverseHelloParser::DEFAULT_MAX_FRAME_SIZE` (`65535`). |

## Methods

### `listen(): void`

Opens the TCP server socket on `bindHost:bindPort`. Idempotent — a
second call without a `close()` in between is a no-op. Raises
`ReverseConnectException` if the kernel-level `stream_socket_server()`
fails (port already bound, permission denied, …) with a message of
the form `Failed to bind reverse-connect listener on …`.

### `getBindAddress(): string`

Returns the actual bound address — `host:port`, with `port` the
kernel-assigned value when the constructor was called with `0`. Raises
`ReverseConnectException` if called before `listen()`.

### `isListening(): bool`

`true` between `listen()` and the next `close()`. `false` before
`listen()` and after `close()`.

### `accept(float $timeoutSeconds): ReverseConnectSession`

Block until a server connects and a full RHE frame arrives, validate
it, and return a [`ReverseConnectSession`](./factory.md#reverseconnectsession).
The budget covers both the inbound TCP connection and the frame read.

Sequence of side effects on success:

1. `stream_select()` returns ready.
2. `stream_socket_accept()` produces a socket.
3. `stream_set_timeout()` is applied to the accepted socket with the
   integer part of `$timeoutSeconds`, clamped to a minimum of `1`.
4. The RHE frame is decoded via
   [`ReverseHelloParser::parse()`](./validator.md#reversehelloparser).
5. `ReverseHelloReceived` is dispatched (if a dispatcher was
   provided).
6. The validator is invoked.
7. `ReverseConnectAccepted` is dispatched on success.
8. The session is returned.

Errors are reported by exception, in this order:

| Condition                                          | Exception                              |
| -------------------------------------------------- | -------------------------------------- |
| `listen()` not called yet                          | `ReverseConnectException`              |
| `stream_select()` returned `false`                 | `ReverseConnectException`              |
| Timeout elapsed without an inbound connection      | `ReverseConnectTimeoutException`       |
| `stream_socket_accept()` returned `false`          | `ReverseConnectException`              |
| RHE frame failed to decode                         | `ReverseHelloParseException`           |
| Validator rejected the decoded message             | `ReverseConnectRejectedException`      |

On every error path that already accepted the socket, the listener
closes the socket before raising the exception, and dispatches
`ReverseConnectRejected` for whitelist failures.

### `close(): void`

Closes the listener socket. Safe to call multiple times. Does not
close sockets returned by previous `accept()` calls — those are owned
by their respective sessions.

## Logging

When a logger is configured the listener emits:

- `INFO` `Reverse-connect listener bound` with `address` context after
  `listen()` succeeds.
- `INFO` `ReverseHello accepted` with `serverUri` and `endpointUrl`
  context on every successful `accept()`.
- `WARNING` `ReverseHello rejected by validator` with `serverUri`,
  `endpointUrl`, and `reason` context.
- `WARNING` `ReverseHello parse failed` with `error` context.

## Events

See [Events](./events.md) for the three PSR-14 events the listener
dispatches.

## Concurrency

The listener is single-threaded and not safe for concurrent use. To
service multiple servers, call `accept()` serially in a loop, or
operate multiple listener instances on different ports.
