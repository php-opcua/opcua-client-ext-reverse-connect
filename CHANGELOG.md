# Changelog

## [v4.4.0] - 2026-06-05

- Requires `php-opcua/opcua-client` ^4.4 (uses `TcpTransport::fromConnectedSocket()` and the matching `ManagesConnectionTrait::performConnect()` skip — both added in core v4.4.0)
- Requires `php-opcua/uanetstandard-test-suite` v1.5.1+ for the integration suite (the `TestServer/ReverseConnect/StartReverseConnect` / `StopReverseConnect` Method nodes used to trigger the server-side outbound dial); v1.5.1+ recommended for the readiness-gated healthcheck (see _Integration suite hardening_ below)

### Fixed — Integration suite hardening (CI)

- **Factory end-to-end test now binds `0.0.0.0:0`** (was `127.0.0.1:0`), matching the accept/reject tests. In CI the server runs in a container and dials the listener via `host.docker.internal`, which `host-gateway` maps to the Docker bridge gateway IP — a loopback-bound listener is unreachable from inside the container, so the inbound ReverseHello never arrived and the test hit the 20s `accept()` timeout.
- **`rcConnectTriggerClient()` now retries the trigger `connect()` for up to 15s**, absorbing the transient `BadServerHalted` (`0x800E0000`) ServiceFault / `ConnectionException` a freshly booted UA-.NETStandard server returns before its `ServerInternal` reaches the Running state. Belt-and-suspenders with the v1.5.1 test-suite healthcheck that now gates `docker compose --wait` on a real readiness marker. Locally the server is already running, so this race only surfaced in CI.

First public release. Ships the OPC UA Reverse Connect (ReverseHello) listener as an optional extension of [`php-opcua/opcua-client`](https://github.com/php-opcua/opcua-client) — implementing the client-side half of OPC UA Part 6 §7.1.2.3. The core `opcua-client` only exposes the `TcpTransport::fromConnectedSocket()` seam; everything else (listener, parser, whitelist, bridge, events, exceptions) lives here under the `PhpOpcua\Client\ExtReverseConnect\*` namespace. Applications that do not need Reverse Connect take no extra dependency.

### Added — Public API

- **`ReverseConnectListener`** — binds a TCP server socket via `stream_socket_server('tcp://<host>:<port>')`, accepts inbound RHE frames bounded by a caller-supplied `accept(float $timeoutSeconds)` budget over `stream_select()`, validates them, and returns a `ReverseConnectSession`. Constructor: `(string $bindHost, int $bindPort, ReverseHelloValidator $validator, ?LoggerInterface $logger = null, ?EventDispatcherInterface $dispatcher = null, int $maxFrameSize = 65535)`. Public methods: `listen()`, `accept()`, `close()`, `getBindAddress()`, `isListening()`. Idempotent `listen()` / `close()`.
- **`ReverseHelloParser`** — pure decoder of the `RHE` wire frame (Part 6 §7.1.2.3). Static `parse(string $frame, int $maxFrameSize = self::DEFAULT_MAX_FRAME_SIZE): ReverseHelloMessage`. Public constants `MIN_FRAME_SIZE = 16` and `DEFAULT_MAX_FRAME_SIZE = 65535`. Reuses `BinaryDecoder::readString()` from the core for OPC UA String decoding; normalises the null string (length `-1`) to the empty string.
- **`ReverseHelloMessage`** — immutable readonly DTO with public `string $serverUri` and `string $endpointUrl`.
- **`ReverseHelloValidator`** — whitelist + scheme check. Fail-secure by default: an empty whitelist refuses every message. Rejection rules in order: empty `ServerUri`, `ServerUri` not in the whitelist (exact, case-sensitive — RFC 3986), empty `EndpointUrl`, `EndpointUrl` not starting with `opc.tcp://`. API: `__construct(iterable $allowedServerUris)`, `getAllowedServerUris()`, `ensureAccepted()` (throws), `isAccepted()` (bool).
- **`ReverseConnectSession`** — immutable readonly value object holding the validated `serverUri`, `endpointUrl`, and the live `mixed $socket` (stream resource).
- **`ReverseConnectClientFactory`** — bridge to the standard `ClientBuilder`. `buildClient(ReverseConnectSession $session, ?Closure $configure = null, ?float $readTimeout = null): Client` wraps the session's socket into `TcpTransport::fromConnectedSocket(...)`, calls `ClientBuilder::setTransport()`, invokes the optional `$configure` closure, and runs `ClientBuilder::connect($session->endpointUrl)`. The core's `ManagesConnectionTrait::performConnect()` detects the already-connected transport via `isConnected()` and skips the redundant outbound `connect($host, $port)`.

### Added — Events (PSR-14)

Three event classes, all `final readonly`, dispatched only when an `EventDispatcherInterface` is provided to the listener (zero overhead otherwise — events are not constructed at all):

- `ReverseHelloReceived(message)` — dispatched after a successful frame decode, before the validator runs.
- `ReverseConnectAccepted(message)` — dispatched after the validator silently approves the message, before `accept()` returns the session.
- `ReverseConnectRejected(message, reason)` — dispatched when the validator refuses a syntactically valid frame, just before `ReverseConnectRejectedException` is raised.

### Added — Exceptions

Four classes rooted in `RuntimeException`:

- `ReverseConnectException` — base class for every error from the package (bind failure, `accept()` called before `listen()`, `stream_select()` / `stream_socket_accept()` returning `false`, …).
- `ReverseHelloParseException` — wire-format / framing errors; the original `EncodingException` from the core is attached as `previous` when relevant.
- `ReverseConnectRejectedException` — carries `public readonly ReverseHelloMessage $rejectedMessage` so logs and event handlers can quote the original payload without re-parsing.
- `ReverseConnectTimeoutException` — `accept()` budget elapsed without an inbound connection.

### Added — Tests

- **44 unit tests** (`tests/Unit/`) using `stream_socket_server()` + `stream_socket_accept()` over loopback TCP (deliberately not `stream_socket_pair(STREAM_PF_UNIX, …)`) so the suite stays portable on Linux, macOS, and Windows. Coverage of: parser positive + every rejection rule (13 tests), validator including fail-secure default + case-sensitivity (12 tests), listener accept happy-path + reject + parse-fail + timeout + bind-error (15 tests), factory transport wiring + `$configure` callback contract (4 tests).
- **4 end-to-end integration tests** (`tests/Integration/ReverseConnectE2ETest.php`, group `integration`) against `uanetstandard-test-suite` v1.4.0+: the PHP client connects normally to `opc.tcp://localhost:4840`, calls `StartReverseConnect(host.docker.internal, <ephemeral-port>)`, accepts the inbound RHE on a `0.0.0.0:0` listener, validates the announced `ServerUri = "urn:opcua:testserver:nodes"`, and builds a fully connected `Client` via the factory. Cleanup via `StopReverseConnect`.
- Shared test helper `tests/Unit/Helpers/InMemoryEventDispatcher.php` — local copy of the core's PSR-14 test fixture.

### Added — Docs & examples

- Full `docs/` tree in the `opcua-client` style (frontmatter `eyebrow`/`lede`/`see_also`/`prev`/`next`): `index.md`, `overview.md`, `getting-started/{installation,quick-start}.md`, `concepts/how-it-works.md`, `api/{listener,validator,factory,events}.md`, `recipes/docker-host-networking.md`, `reference/exceptions.md`. Published at <https://www.php-opcua.com/dev/components>.
- Worked example at [`examples/exts/reverse-connect/listener.php`](https://github.com/php-opcua/examples) — opens a listener on `0.0.0.0:0`, triggers the server via `StartReverseConnect`, accepts the RHE, builds a `Client`, reads `TestServer/DataTypes/Scalar/Int32Value` through the reverse channel, and tears down via `StopReverseConnect`. Output verified end-to-end against the test-suite container.
- `README.md` modelled on the core README (badges, quick start, "See It in Action", "Why This Package?", ecosystem, testing, community).
- `SECURITY.md`, `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, `LICENSE`, plus `.github/` with `workflows/tests.yml`, three issue templates (`bug_report`, `feature_request`, `question`), `pull_request_template.md`, and `copilot-instructions.md`.

### Core-side requirement

This release pairs with **`php-opcua/opcua-client` v4.4.0**, which adds:

- `TcpTransport::fromConnectedSocket(mixed $socket, ?float $readTimeout = null): self`
- A skip in `ManagesConnectionTrait::performConnect()` that bypasses `transport->connect($host, $port)` when `transport->isConnected() === true`

No other change to the core is involved. See the [`opcua-client` v4.4.0 entry](https://github.com/php-opcua/opcua-client/blob/master/CHANGELOG.md) for the matching upstream notes.
