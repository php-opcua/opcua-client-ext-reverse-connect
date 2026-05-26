---
eyebrow: 'Docs · Getting started'
lede:    'Install the package with Composer and verify it talks to the OPC UA test suite. Reverse Connect requires opcua-client v4.4.0 or newer.'

see_also:
  - { href: './quick-start.md',                meta: '4 min' }
  - { href: '../concepts/how-it-works.md',     meta: '5 min' }

prev: { label: 'Overview',    href: '../overview.md' }
next: { label: 'Quick start', href: './quick-start.md' }
---

# Installation

`php-opcua/opcua-client-ext-reverse-connect` is distributed through
Packagist. Its only required dependency is
[`php-opcua/opcua-client`](https://github.com/php-opcua/opcua-client)
v4.4.0 or newer — the version that introduced
`TcpTransport::fromConnectedSocket()` and the matching
`ManagesConnectionTrait` adjustment the listener relies on.

## Requirements

- **PHP** ≥ 8.2
- **`php-opcua/opcua-client`** ≥ 4.4
- **`psr/log`** ^3.0 and **`psr/event-dispatcher`** ^1.0 — both already
  declared as transitive dependencies of `opcua-client`

There is no native extension to enable. The listener uses the standard
PHP streams API (`stream_socket_server`, `stream_socket_accept`,
`stream_select`) — available everywhere PHP runs.

## Install

```bash
composer require php-opcua/opcua-client-ext-reverse-connect
```

That is the only step. The package autoloads under the
`PhpOpcua\Client\ExtReverseConnect\` namespace via PSR-4.

## Verify

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use PhpOpcua\Client\ExtReverseConnect\ReverseConnectListener;
use PhpOpcua\Client\ExtReverseConnect\ReverseHelloValidator;

$listener = new ReverseConnectListener(
    bindHost: '127.0.0.1',
    bindPort: 0,
    validator: new ReverseHelloValidator(['urn:example']),
);
$listener->listen();
echo "Listener bound on " . $listener->getBindAddress() . PHP_EOL;
$listener->close();
```

A successful run prints `Listener bound on 127.0.0.1:<port>`, where
`<port>` is the kernel-assigned ephemeral port.

## Running against the test server

The integration tests and the example script under `examples/exts/reverse-connect/`
use the OPC UA test server from
[`php-opcua/uanetstandard-test-suite`](https://github.com/php-opcua/uanetstandard-test-suite)
v1.4.0+. That release adds two Method nodes —
`TestServer/ReverseConnect/StartReverseConnect` and
`TestServer/ReverseConnect/StopReverseConnect` — that let a regular
client trigger the server-side outbound dial:

```bash
git clone https://github.com/php-opcua/uanetstandard-test-suite.git
cd uanetstandard-test-suite
docker compose up -d opcua-no-security
```

The `opcua-no-security` service exposes port 4840 and includes
`extra_hosts: ["host.docker.internal:host-gateway"]` so the in-container
server can reach a listener that lives on the docker host.

## What next

- [Quick start](./quick-start.md) — accept your first ReverseHello in
  about 20 lines of PHP.
- [How Reverse Connect works](../concepts/how-it-works.md) — wire
  format, security model, and where it fits in the OPC UA stack.
