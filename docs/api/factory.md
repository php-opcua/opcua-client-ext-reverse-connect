---
eyebrow: 'Docs · API'
lede:    'ReverseConnectSession and ReverseConnectClientFactory — the handoff types that turn a validated ReverseHello into a fully connected php-opcua/opcua-client Client.'

see_also:
  - { href: './listener.md',                meta: '4 min' }
  - { href: './validator.md',               meta: '3 min' }
  - { href: '../concepts/how-it-works.md',  meta: '5 min' }

prev: { label: 'Validator API', href: './validator.md' }
next: { label: 'Events',        href: './events.md' }
---

# `ReverseConnectSession` and `ReverseConnectClientFactory`

These are the two types that bridge a successful `accept()` to the
standard `php-opcua/opcua-client` connection lifecycle.

## `ReverseConnectSession`

Fully qualified name:
`PhpOpcua\Client\ExtReverseConnect\ReverseConnectSession`. Immutable
readonly value object.

```php
final readonly class ReverseConnectSession
{
    public function __construct(
        public string $serverUri,
        public string $endpointUrl,
        public mixed $socket,         // resource — live TCP stream
    ) {}
}
```

| Property        | Meaning                                                                                       |
| --------------- | --------------------------------------------------------------------------------------------- |
| `$serverUri`    | The `ServerUri` the validator accepted — guaranteed non-empty and present in the whitelist.   |
| `$endpointUrl`  | The `EndpointUrl` the server announced, validated to start with `opc.tcp://` and be non-empty. |
| `$socket`       | Live blocking stream socket in CONNECTED state, RHE frame already consumed.                   |

The session takes ownership of the socket. In normal usage you pass
the session to
[`ReverseConnectClientFactory::buildClient()`](#reverseconnectclientfactory),
which wraps the socket into a transport whose `close()` will
`fclose()` it. If you do not call the factory (because you are
plugging the socket into something else), you are responsible for
closing it.

## `ReverseConnectClientFactory`

Fully qualified name:
`PhpOpcua\Client\ExtReverseConnect\ReverseConnectClientFactory`.

```php
public function buildClient(
    ReverseConnectSession $session,
    ?Closure $configure = null,
    ?float $readTimeout = null,
): \PhpOpcua\Client\Client;
```

What it does, in order:

1. Calls `TcpTransport::fromConnectedSocket($session->socket, $readTimeout)`
   to wrap the live socket without re-running
   `stream_socket_client()`.
2. Creates a fresh `ClientBuilder` via `ClientBuilder::create()` and
   calls `setTransport(...)` on it with the transport from step 1.
3. Invokes the optional `$configure` closure with the builder as its
   only argument — this is the standard place to set security policy
   and mode, identity credentials, event dispatcher, logger, or to
   register extra modules.
4. Calls `$builder->connect($session->endpointUrl)` and returns the
   resulting `Client`.

Inside `Client::connect()` the core's
`ManagesConnectionTrait::performConnect()` detects the
already-connected transport via `transport->isConnected()` and skips
the redundant `transport->connect($host, $port)` step. The HEL/ACK,
OPN, and CreateSession exchanges run identically to a regular client.

### Configuring security and identity

```php
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\ExtReverseConnect\ReverseConnectClientFactory;
use PhpOpcua\Client\Security\SecurityMode;
use PhpOpcua\Client\Security\SecurityPolicy;

$client = (new ReverseConnectClientFactory())->buildClient(
    $session,
    static fn (ClientBuilder $b) => $b
        ->setSecurityPolicy(SecurityPolicy::Basic256Sha256)
        ->setSecurityMode(SecurityMode::SignAndEncrypt)
        ->setClientCertificate('/certs/client.pem', '/certs/client.key', '/certs/ca.pem')
        ->setUserCredentials('operator', 'secret'),
);
```

### Do not override the transport in the closure

The factory has already wired a transport built from the inherited
socket. Calling `$b->setTransport(new TcpTransport())` in the
`$configure` closure would discard that transport and force a brand
new outbound TCP connection on `connect()` — which defeats the whole
reverse-connect flow. Treat the transport as off-limits inside the
closure; configure everything else freely.

### `$readTimeout`

Forwarded to `TcpTransport::fromConnectedSocket()`. When `null`, the
transport uses `TcpTransport::DEFAULT_TIMEOUT` (defined as `5.0`
seconds at the time of writing — read the constant in
`PhpOpcua\Client\Transport\TcpTransport` for the authoritative
value).

### Reconnection

A reverse-connected `Client` behaves like any other client returned
by `ClientBuilder::connect()`. If the channel drops and you call
`reconnect()`, the core attempts a regular outbound TCP connection
to `$session->endpointUrl`. If the server is unreachable from the
client side — which is often the whole reason you picked Reverse
Connect — that reconnect will fail, and the application must trigger
the server to dial back again.
