---
eyebrow: 'Docs · Recipes'
lede:    'Make the in-container OPC UA server reach a listener that lives on the docker host — the network plumbing the integration tests and the example script rely on.'

see_also:
  - { href: '../getting-started/quick-start.md',  meta: '4 min' }
  - { href: '../api/listener.md',                 meta: '4 min' }

prev: { label: 'Events',                href: '../api/events.md' }
next: { label: 'Exceptions reference',  href: '../reference/exceptions.md' }
---

# Docker host networking

Reverse Connect inverts the TCP direction: the server connects to the
client. When the server runs inside a Docker container and the client
runs on the host, the in-container address `127.0.0.1` refers to the
container itself, not the host. Two pieces have to line up.

## 1 — Make the host reachable from the container

Add `host.docker.internal` as a `host-gateway` alias on the service
that should be able to dial out. The
[`uanetstandard-test-suite`](https://github.com/php-opcua/uanetstandard-test-suite)
v1.4.0 `docker-compose.yml` already does this for its
`opcua-no-security` service:

```yaml
services:
  opcua-no-security:
    extra_hosts:
      - "host.docker.internal:host-gateway"
```

Inside the container, `host.docker.internal` then resolves to the
docker host. Verify with:

```bash
docker exec <container> getent ahosts host.docker.internal
# 192.168.65.254  STREAM host.docker.internal
# 192.168.65.254  DGRAM  host.docker.internal
```

The exact IP depends on the docker network — what matters is that the
name resolves to an address the container can route to.

## 2 — Bind the listener on `0.0.0.0`

The listener must accept the incoming TCP connection from the docker
bridge IP, not just loopback. Construct it with `'0.0.0.0'` as
`bindHost`:

```php
$listener = new ReverseConnectListener(
    bindHost: '0.0.0.0',
    bindPort: 0,                  // kernel-assigned
    validator: new ReverseHelloValidator(['urn:opcua:testserver:nodes']),
);
$listener->listen();
[, $port] = explode(':', $listener->getBindAddress());
```

`bindPort: 0` lets the kernel pick a free port; the resulting port is
what you then hand to the server in step 3.

## 3 — Tell the server to dial `host.docker.internal:<port>`

Call the trigger method (in the test suite,
`TestServer/ReverseConnect/StartReverseConnect`) with
`'host.docker.internal'` as the host and the kernel-assigned port as
the value:

```php
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\Variant;

$trigger->call(
    NodeId::string(2, 'TestServer/ReverseConnect'),
    NodeId::string(2, 'TestServer/ReverseConnect/StartReverseConnect'),
    [
        new Variant(BuiltinType::String, 'host.docker.internal'),
        new Variant(BuiltinType::UInt16, $port),
    ],
);
```

The combination of `extra_hosts` + bind on `0.0.0.0` + the alias as
the trigger host gets the server-side TCP connection back to the
listener.

## Non-docker hosts

If the server runs on a different physical machine or VM, replace
`'host.docker.internal'` with the actual reachable address (DNS name
or IP) of the host running the listener — and make sure any firewall
between them allows inbound TCP on the listener port. The listener
code itself does not change.

## Listening on a single interface

If the listener has to stay loopback-only (no docker, no remote
servers), use `'127.0.0.1'` as `bindHost`. The trigger then has to be
something local — for example a second PHP process on the same host —
because nothing else can reach the loopback interface.
