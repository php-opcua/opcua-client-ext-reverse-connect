---
eyebrow: 'Docs · Overview'
lede:    'Client-side listener for OPC UA Reverse Connect (Part 6 §7.1.2.3). The server dials out, the client accepts; everything after the opening ReverseHello frame is the standard UA-TCP protocol.'

see_also:
  - { href: './getting-started/installation.md', meta: '2 min' }
  - { href: './concepts/how-it-works.md',        meta: '5 min' }
  - { href: 'https://reference.opcfoundation.org/Core/Part6/v105/docs/7.1.2.3', meta: 'external', label: 'OPC UA Part 6 §7.1.2.3' }

prev: { label: 'No previous page', href: '#' }
next: { label: 'Installation',     href: './getting-started/installation.md' }
---

# Overview

`php-opcua/opcua-client-ext-reverse-connect` is a thin client-side
extension on top of [`php-opcua/opcua-client`](https://github.com/php-opcua/opcua-client).
It implements the *listener* half of OPC UA Reverse Connect — the
mechanism defined in OPC UA Part 6 §7.1.2.3 that inverts who initiates
the underlying TCP connection.

In the standard flow the client opens the socket to the server. In the
reverse flow the server opens the socket to the client and announces
itself with a `RHE` (ReverseHello) frame. After that frame is consumed
the rest of the conversation — HEL/ACK, secure channel, session,
service calls — is identical to a regular UA-TCP exchange.

## When to use it

Reach for this package when the server cannot accept inbound TCP
connections and your application can:

- **Edge gateways behind NAT or one-way firewalls** — the device dials
  outward; you cannot reach it directly.
- **Many-to-one push topologies** — a fleet of servers dial back to a
  single central client.
- **Hardened deployments** — closing every inbound port on the server
  reduces the attack surface; the server only ever initiates outgoing
  connections.

## What it provides

| Class                                  | Role                                                      |
| -------------------------------------- | --------------------------------------------------------- |
| `ReverseConnectListener`               | Binds a TCP listener, accepts inbound RHE frames          |
| `ReverseHelloParser`                   | Pure decoder of the wire frame                            |
| `ReverseHelloMessage`                  | Immutable DTO: `serverUri`, `endpointUrl`                 |
| `ReverseHelloValidator`                | Whitelist + scheme check on the decoded message           |
| `ReverseConnectSession`                | Validated message + live socket, output of `accept()`     |
| `ReverseConnectClientFactory`          | Bridge from a session to a fully connected `Client`       |

Three PSR-14 events are dispatched when an `EventDispatcherInterface`
is provided to the listener: `ReverseHelloReceived`,
`ReverseConnectAccepted`, `ReverseConnectRejected`. Four exceptions
cover the failure modes — see [Exceptions](./reference/exceptions.md).

## How it relates to `opcua-client`

The core package exposes a single seam:
`TcpTransport::fromConnectedSocket(mixed $socket, ?float $readTimeout = null): self`.
The factory wraps an already-connected stream resource into the same
transport the rest of the pipeline uses. The reverse-connect listener
calls it, hands the result to `ClientBuilder::setTransport()`, then
runs `connect()` normally. The `ManagesConnectionTrait::performConnect()`
hook detects the already-connected transport via `isConnected()` and
skips the redundant `transport->connect($host, $port)` step.

Everything else — the listener, the parser, the whitelist, the bridge,
the events, the exceptions — lives in this package. Applications that
do not need Reverse Connect take no extra dependency.

## What it does not do

- It is not an event loop. `accept()` is bounded by a caller-supplied
  timeout. To service multiple servers, call `accept()` in a loop.
- It does not change the OPC UA security model. The whitelist that
  rejects unknown `ServerUri`s is a defence-in-depth check on top of
  the certificate validation the UA secure channel already performs.
- It is not a server. There is no PHP-side OPC UA server in this
  ecosystem; the listener accepts the inbound TCP connection, but the
  conversation that follows is still client-to-server.
