---
eyebrow: 'Docs · Getting started'
lede:    'Bind a listener, accept a ReverseHello frame, build a Client from the resulting session, and call Read through the reverse-connected channel.'

see_also:
  - { href: '../api/listener.md',      meta: '4 min' }
  - { href: '../api/factory.md',       meta: '3 min' }
  - { href: '../api/validator.md',     meta: '3 min' }

prev: { label: 'Installation',           href: './installation.md' }
next: { label: 'How Reverse Connect works', href: '../concepts/how-it-works.md' }
---

# Quick start

This walkthrough produces a working reverse-connect client in three
explicit steps. It assumes the
[`uanetstandard-test-suite`](https://github.com/php-opcua/uanetstandard-test-suite)
v1.4.0+ stack is running locally on `opc.tcp://localhost:4840` — see
[Installation · Running against the test server](./installation.md#running-against-the-test-server).

## 1 — Open a listener

```php
use PhpOpcua\Client\ExtReverseConnect\ReverseConnectListener;
use PhpOpcua\Client\ExtReverseConnect\ReverseHelloValidator;

$listener = new ReverseConnectListener(
    bindHost: '0.0.0.0',
    bindPort: 0,
    validator: new ReverseHelloValidator(['urn:opcua:testserver:nodes']),
);
$listener->listen();

[, $port] = explode(':', $listener->getBindAddress());
$port = (int) $port;
```

`bindPort: 0` asks the kernel for a free port; `getBindAddress()`
reveals the chosen one. The validator's whitelist is the security
boundary of the flow — only servers whose announced `ServerUri`
matches an entry will be accepted.

## 2 — Trigger the server to dial back

In the test suite, a regular OPC UA client tells the server which host
and port to dial:

```php
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Security\SecurityMode;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\Variant;

$trigger = (new ClientBuilder())
    ->setSecurityPolicy(SecurityPolicy::None)
    ->setSecurityMode(SecurityMode::None)
    ->connect('opc.tcp://localhost:4840/UA/TestServer');

$folder = NodeId::string(2, 'TestServer/ReverseConnect');
$start  = NodeId::string(2, 'TestServer/ReverseConnect/StartReverseConnect');

$trigger->call($folder, $start, [
    new Variant(BuiltinType::String, 'host.docker.internal'),
    new Variant(BuiltinType::UInt16, $port),
]);
```

In production the trigger comes from somewhere else — an HTTPS
callback, an MQTT message, a configuration push, a CLI invocation on
the gateway. The listener does not care how the server learned where
to dial.

## 3 — Accept and build a Client

```php
use PhpOpcua\Client\ExtReverseConnect\ReverseConnectClientFactory;

$session = $listener->accept(timeoutSeconds: 20.0);

$client = (new ReverseConnectClientFactory())->buildClient(
    $session,
    static fn (ClientBuilder $b) => $b
        ->setSecurityPolicy(SecurityPolicy::None)
        ->setSecurityMode(SecurityMode::None),
);

// Use the client exactly like a regularly connected one:
$dataValue = $client->read('ns=2;s=TestServer/DataTypes/Scalar/Int32Value');
echo $dataValue->getValue() . PHP_EOL;     // -100000

$client->disconnect();
$listener->close();
```

`accept()` blocks for up to `timeoutSeconds` waiting for an inbound
TCP connection plus the RHE frame. On timeout it throws
`ReverseConnectTimeoutException`; on a frame whose `ServerUri` is not
whitelisted it throws `ReverseConnectRejectedException` and closes the
socket before returning control.

`buildClient()` returns a fully connected `PhpOpcua\Client\Client`. The
factory wraps the session's socket into a
`TcpTransport::fromConnectedSocket()` and runs the standard
`ClientBuilder::connect()` pipeline; the core's
`ManagesConnectionTrait::performConnect()` skips the redundant TCP
connect step when it sees the transport is already connected.

## Full runnable example

See
[`examples/exts/reverse-connect/listener.php`](https://github.com/php-opcua/examples)
in the shared examples repository — same three steps, plus an
in-process PSR-14 dispatcher that prints every event the flow emits.

## What next

- [How Reverse Connect works](../concepts/how-it-works.md) — wire
  format and security model.
- [Listener API](../api/listener.md) — every constructor argument and
  every method.
- [Validator API](../api/validator.md) — exact rejection rules.
