# Contributing to OPC UA Reverse Connect (PHP)

## Welcome!

Thank you for considering contributing to this project! Every contribution
matters — bug reports, feature suggestions, documentation fixes, code
changes. This project is open to everyone, you're welcome here.

If you have any questions or need help getting started, open an issue.
We're happy to help.

## What this package is

`php-opcua/opcua-client-ext-reverse-connect` is the client-side listener
for OPC UA Reverse Connect (Part 6 §7.1.2.3). It extends
[`php-opcua/opcua-client`](https://github.com/php-opcua/opcua-client)
v4.4.0+ — the version that introduced
`TcpTransport::fromConnectedSocket()`. The package is intentionally small:
six runtime classes (listener, parser, message DTO, validator, session,
client factory), three PSR-14 events, and four exceptions.

Two consequences for contributors:

- **Most ideas belong in the core.** Anything that touches the OPC UA
  binary protocol, the secure channel, the session, the modules, the
  trust store, or any other UA-TCP machinery should go into
  [`opcua-client`](https://github.com/php-opcua/opcua-client). Open the
  issue there.
- **The package surface is deliberately tight.** New public classes or
  methods need a clear reverse-connect motivation. Generic plumbing
  belongs in the core; transport variants belong in the core; OPC UA
  service implementations belong in the core.

## Development Setup

### Requirements

- PHP >= 8.2
- Composer
- A local checkout of [`php-opcua/opcua-client`](https://github.com/php-opcua/opcua-client)
  as a **sibling directory** of this repository — `composer.json`
  declares a `path` repository pointing at `../opcua-client` so changes
  to the core can be tested without publishing
- Docker (for the integration suite — the OPC UA test server runs in a
  container)

### Installation

```bash
# In the parent directory:
git clone https://github.com/php-opcua/opcua-client.git
git clone https://github.com/php-opcua/opcua-client-ext-reverse-connect.git

cd opcua-client-ext-reverse-connect
composer install
```

The path repository keeps `php-opcua/opcua-client` symlinked, so a
`git pull` on the core picks up immediately without re-running
`composer update`.

### Test Server

The integration suite requires
[`uanetstandard-test-suite`](https://github.com/php-opcua/uanetstandard-test-suite)
**v1.4.0 or newer**. That release adds the
`TestServer/ReverseConnect/StartReverseConnect` and
`TestServer/ReverseConnect/StopReverseConnect` Method nodes the tests
call to trigger the server-side outbound dial, plus the
`extra_hosts: ["host.docker.internal:host-gateway"]` entry on the
`opcua-no-security` service that lets the in-container server reach the
listener running on the docker host.

```bash
git clone https://github.com/php-opcua/uanetstandard-test-suite.git
cd uanetstandard-test-suite
docker compose up -d opcua-no-security
```

Verify the Method nodes exist before running the integration suite:

```bash
docker logs uanetstandard-test-suite-opcua-no-security-1 | grep ReverseConnect
# Expected: "  [+] ReverseConnect address space built"
```

## Running Tests

```bash
# Everything
./vendor/bin/pest

# Unit only — no external dependency, no Docker, no network
./vendor/bin/pest --exclude-group=integration

# Integration only — requires uanetstandard-test-suite v1.4.0+ running
./vendor/bin/pest --group=integration

# A single file
./vendor/bin/pest tests/Unit/ReverseConnectListenerTest.php
```

The unit suite uses `stream_socket_server()` + `stream_socket_accept()`
over loopback TCP — deliberately not `stream_socket_pair(STREAM_PF_UNIX,
…)` — so it stays portable on Linux, macOS, and Windows.

All tests must pass before submitting a pull request.

## Project Structure

```
src/
├── ReverseConnectListener.php        # TCP server socket, accept() loop
├── ReverseHelloParser.php            # Pure decoder of the RHE frame
├── ReverseHelloMessage.php           # Immutable DTO (serverUri, endpointUrl)
├── ReverseHelloValidator.php         # Whitelist + scheme check (fail-secure)
├── ReverseConnectSession.php         # Validated message + live socket
├── ReverseConnectClientFactory.php   # Bridge to ClientBuilder
├── Event/                            # 3 PSR-14 events
│   ├── ReverseHelloReceived.php
│   ├── ReverseConnectAccepted.php
│   └── ReverseConnectRejected.php
└── Exception/                        # 4 typed exceptions
    ├── ReverseConnectException.php             # base
    ├── ReverseHelloParseException.php
    ├── ReverseConnectRejectedException.php
    └── ReverseConnectTimeoutException.php

tests/
├── Unit/                             # 44 unit tests — no external dependency
│   ├── ReverseConnectListenerTest.php
│   ├── ReverseHelloParserTest.php
│   ├── ReverseHelloValidatorTest.php
│   ├── ReverseConnectClientFactoryTest.php
│   └── Helpers/InMemoryEventDispatcher.php
└── Integration/                      # 4 end-to-end tests against UA-.NETStandard
    └── ReverseConnectE2ETest.php

docs/                                 # Markdown sources for the documentation
├── index.md
├── overview.md
├── getting-started/
├── concepts/
├── api/
├── recipes/
└── reference/
```

## Design Principles

### Minimal core seam

The only hook in `php-opcua/opcua-client` is the
`TcpTransport::fromConnectedSocket()` factory plus the
`ManagesConnectionTrait::performConnect()` skip when the transport is
already connected. Adding more hooks to the core is a deliberate
architectural decision — open an issue on the core repository first.

### Fail-secure by default

`ReverseHelloValidator` rejects everything by default — an empty
whitelist refuses every incoming frame. Never add code paths that
short-circuit the whitelist or "allow all" implicit fallbacks. The
validator is the only application-supplied check between a stranger
on the listener port and the UA secure channel.

### Cross-platform compatibility

The listener has to run on Linux, macOS, and Windows. Do not use
platform-specific APIs:

- No `stream_socket_pair(STREAM_PF_UNIX, …)` — Unix domain sockets do
  not exist on Windows
- No `pcntl_*`, no `posix_*`
- No `/proc`, no signal handling beyond what plain CLI scripts have

The unit tests prove portability via `stream_socket_server()` over
loopback TCP — keep that pattern.

### Public readonly DTOs

`ReverseHelloMessage`, `ReverseConnectSession`, and all three events
are `final readonly`. Properties are accessed directly
(`$message->serverUri`), not via getters. Do not introduce getter
methods on the DTOs; do not relax `readonly`.

### Single-threaded, no event loop

`accept()` is a blocking call bounded by a caller-supplied timeout
via `stream_select()`. The package does not pull in ReactPHP, Amp,
Revolt, or any other event-loop dependency. Applications that need an
event loop wrap the listener themselves.

## Guidelines

### Code Style

The project enforces a Laravel-style coding standard (PSR-12 +
opinionated rules) via
[php-cs-fixer](https://github.com/PHP-CS-Fixer/PHP-CS-Fixer).
Configuration lives in `.php-cs-fixer.php`.

```bash
# Format all files
composer format

# Check without modifying (CI mode)
composer format:check
```

**Run `composer format` before committing.** The GitHub Actions
workflow runs `composer format:check` on the PHP 8.5 Ubuntu leg —
unformatted code fails CI.

Key rules:

- `declare(strict_types=1)` required
- Single quotes for strings
- Trailing commas in multiline arrays, arguments, and parameters
- `not_operator_with_successor_space` (space after `!`)
- Ordered imports (alphabetical)
- No unused imports
- Type declarations for parameters, return types, and properties
- `public readonly` properties on DTOs — no getters

### Documentation & Comments

- Every class and public method must have a PHPDoc block with
  `@param`, `@return`, `@throws`, and `@see` where applicable
- **Do not add comments inside function bodies.** If a method needs an
  inline explanation, split it into smaller, well-named methods. The
  method name and its PHPDoc should be enough
- Update the relevant page in `docs/` for any feature change
- Update `CHANGELOG.md` with every change
- Update `README.md` if you change the public API surface
- Verify every `@see` and link in the documentation points to a class,
  method, or URL that actually exists before submitting

### Public API Changes

The public API is small on purpose. Before adding a new public class
or method:

1. Verify it is reverse-connect-specific. Generic plumbing belongs in
   the core (`opcua-client`).
2. Verify it does not duplicate something `ClientBuilder` already
   exposes — the `$configure` closure of
   `ReverseConnectClientFactory::buildClient()` is the canonical
   extension point for security, identity, dispatcher, logger, and
   modules.
3. Add it to the relevant `docs/api/` page in the same PR.

### Testing

- Write unit tests for all new functionality (Pest PHP syntax)
- Write integration tests for features that interact with an OPC UA
  server — tag them with `->group('integration')`
- Unit tests must run on Linux, macOS, and Windows. Use
  `stream_socket_server()` over loopback, not `stream_socket_pair()`
  with `STREAM_PF_UNIX`
- Integration tests rely on
  [`uanetstandard-test-suite`](https://github.com/php-opcua/uanetstandard-test-suite)
  v1.4.0+. Do not introduce a new test fixture unless the existing
  Method nodes really cannot exercise the scenario — and if you do,
  open a paired PR on the test suite
- Cover both happy-path and failure-path branches — the listener can
  raise all four exception types (`ReverseConnectException`,
  `ReverseHelloParseException`, `ReverseConnectRejectedException`,
  `ReverseConnectTimeoutException`), and each one is reachable

### Commits

- Use descriptive commit messages
- Prefix with `[ADD]`, `[UPD]`, `[PATCH]`, `[REF]`, `[DOC]`, `[TEST]`
  as appropriate
- Commits whose first line starts with `[DOC]` skip the CI workflow
  (`if: ${{ !startsWith(github.event.head_commit.message, '[DOC]') }}`),
  so reserve that prefix for changes that genuinely do not need tests

## Pull Request Process

1. Fork the repository and create a feature branch
2. Write your code and tests
3. Run `composer format` to format your code
4. Ensure unit and (when applicable) integration tests pass
5. Update `docs/`, `README.md`, and `CHANGELOG.md`
6. Submit a pull request using the provided template
7. Wait for review — a maintainer will review, possibly request
   changes, and merge once it's ready

## Reporting Issues

Use the [issue templates](https://github.com/php-opcua/opcua-client-ext-reverse-connect/issues/new/choose):

- **Bug** — something does not behave as documented
- **Feature** — a missing capability of the listener or factory
- **Question** — a usage question that is not yet covered in `docs/`

For bugs that affect the underlying transport, secure channel,
session, or any OPC UA service, open the issue on
[`opcua-client`](https://github.com/php-opcua/opcua-client/issues)
instead — that's where the fix would have to land.
