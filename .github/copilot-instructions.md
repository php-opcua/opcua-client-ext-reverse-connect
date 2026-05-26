# OPC UA Reverse Connect — Copilot Instructions

This repository contains `php-opcua/opcua-client-ext-reverse-connect`, a
client-side listener for OPC UA Reverse Connect (Part 6 §7.1.2.3). It
extends [`php-opcua/opcua-client`](https://github.com/php-opcua/opcua-client)
v4.4.0+ — the version that introduced `TcpTransport::fromConnectedSocket()`.

## Project context

Read first:

1. **[README.md](../README.md)** — value proposition, quick start, ecosystem
2. **[docs/overview.md](../docs/overview.md)** — what the package does and
   what it explicitly does not do
3. **[docs/concepts/how-it-works.md](../docs/concepts/how-it-works.md)** —
   wire format of the RHE frame, lifecycle, security model
4. **[docs/api/](../docs/api/)** — exact constructor / method signatures
   for the four public classes

## Architecture

```
ReverseConnectListener (TCP server socket)
    │
    ▼ accept(timeoutSeconds)
ReverseHelloParser  (wire decoder, pure)
    │
    ▼ ReverseHelloMessage
ReverseHelloValidator (whitelist + scheme check, fail-secure)
    │
    ▼ ReverseConnectSession (live socket + validated message)
ReverseConnectClientFactory
    │
    ▼ TcpTransport::fromConnectedSocket()   ← seam in opcua-client v4.4.0
ClientBuilder::setTransport(...)->connect($session->endpointUrl)
    │
    ▼
PhpOpcua\Client\Client
```

## Key classes

- `src/ReverseConnectListener.php` — binds TCP socket, accepts inbound
  RHE frames, dispatches three PSR-14 events
- `src/ReverseHelloParser.php` — pure decoder (`MIN_FRAME_SIZE = 16`,
  `DEFAULT_MAX_FRAME_SIZE = 65535`)
- `src/ReverseHelloMessage.php` — immutable readonly DTO
  (`serverUri`, `endpointUrl`)
- `src/ReverseHelloValidator.php` — whitelist + scheme check; empty
  whitelist rejects every message (fail-secure)
- `src/ReverseConnectSession.php` — validated message + live socket
- `src/ReverseConnectClientFactory.php` — bridge to `ClientBuilder`
- `src/Event/` — three PSR-14 events (`ReverseHelloReceived`,
  `ReverseConnectAccepted`, `ReverseConnectRejected`)
- `src/Exception/` — four exception classes rooted in
  `ReverseConnectException`

## Code conventions

- `declare(strict_types=1)` in every file
- Public readonly properties on every DTO and event (not getters)
- Full PHPDoc on every class and public method
  (`@param`, `@return`, `@throws`, `@see`)
- **No comments inside function bodies** — split into well-named
  methods instead
- Tests use Pest PHP (not PHPUnit)
- Integration tests are tagged with `->group('integration')` and
  require `uanetstandard-test-suite` v1.4.0+ running on
  `opc.tcp://localhost:4840`
- Cross-platform code: no Unix domain sockets, no
  `stream_socket_pair(STREAM_PF_UNIX, …)` — the listener targets
  Linux, macOS, and Windows
- Coverage target: 99%+

## Dependencies

- `php-opcua/opcua-client` ^4.4 — the only hard dependency
- `psr/log` ^3.0, `psr/event-dispatcher` ^1.0 — interface-only,
  inherited from the core
- `ext-openssl` — already required by the core for the secure channel

## How the core is wired

- `TcpTransport::fromConnectedSocket(mixed $socket, ?float $readTimeout = null): self`
  in `php-opcua/opcua-client` is the only seam. The factory takes the
  live socket the listener accepted and wraps it without re-running
  `stream_socket_client()`.
- `ManagesConnectionTrait::performConnect()` checks
  `transport->isConnected()` and skips the redundant
  `transport->connect($host, $port)` step when the transport already
  owns a connected socket. That happens automatically — no special
  flag is needed.
- The `$configure` closure in `ReverseConnectClientFactory::buildClient()`
  must not call `setTransport()` (doing so discards the inherited
  socket). Configure security, identity, dispatcher, logger, and
  modules freely; leave the transport alone.

## Testing

- `vendor/bin/pest --exclude-group=integration` — unit suite, no
  external dependency
- `vendor/bin/pest --group=integration` — end-to-end against the test
  suite

The integration tests trigger the server-side reverse connect via the
`TestServer/ReverseConnect/StartReverseConnect` and
`StopReverseConnect` Method nodes that
[`uanetstandard-test-suite`](https://github.com/php-opcua/uanetstandard-test-suite)
v1.4.0+ exposes on its `opcua-no-security` service.

The published docs site lives at
<https://www.php-opcua.com/dev/components>.
